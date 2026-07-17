<?php

declare(strict_types=1);

use App\Database\Connection;
use App\Ingestion\Chunking\HeuristicTokenEstimator;
use App\Ingestion\Chunking\SemanticChunker;
use App\Ingestion\DocumentIngestionProcessor;
use App\Ingestion\ExtractorRegistry;
use App\Ingestion\Extractors\HtmlDocumentParser;
use App\Ingestion\Extractors\MarkdownExtractor;
use App\Ingestion\Extractors\PdfExtractor;
use App\Ingestion\Extractors\UrlExtractor;
use App\Ingestion\IngestionWorker;
use App\Ingestion\Ocr\OcrmyPdfEngine;
use App\Logging\LoggerFactory;
use App\Networking\SafeUrlFetcher;
use App\Networking\UrlNetworkGuard;
use App\Repositories\PdoIngestionJobRepository;
use App\Repositories\PdoSourceIngestionRepository;
use App\Security\UrlSourceValidator;
use App\Services\Ingestion\IngestionQueue;
use App\Services\Sources\PrivateSourceFileLocator;
use App\Support\Config;
use Dotenv\Dotenv;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
Dotenv::createImmutable($root)->safeLoad();
$config = Config::load($root . '/config');
$timezone = $config->requireString('app.timezone');

if (!in_array($timezone, timezone_identifiers_list(), true)) {
    throw new RuntimeException('APP_TIMEZONE is not a valid timezone identifier.');
}

date_default_timezone_set($timezone);
$logger = (new LoggerFactory())->create($config);
$connection = new Connection($config);
$repository = new PdoIngestionJobRepository($connection);
$queue = new IngestionQueue(
    $repository,
    $config->requireInt('queue.max_attempts'),
    $config->requireInt('queue.retry_base_seconds'),
    $config->requireInt('queue.retry_maximum_seconds'),
    $config->requireInt('queue.abandoned_timeout_minutes') * 60,
);
$hostname = gethostname();
$workerId = sprintf(
    '%s:%d:%s',
    is_string($hostname) ? substr($hostname, 0, 32) : 'worker',
    getmypid() ?: 0,
    bin2hex(random_bytes(6)),
);
$configuredStoragePath = $config->requireString('app.filesystem_path');
$storagePath = str_starts_with($configuredStoragePath, DIRECTORY_SEPARATOR)
    ? $configuredStoragePath
    : $root . DIRECTORY_SEPARATOR . $configuredStoragePath;
$resolvedStoragePath = realpath($storagePath);
$resolvedPublicPath = realpath($root . '/public');

if ($resolvedStoragePath === false || $resolvedPublicPath === false) {
    throw new RuntimeException('The configured source storage and public directories must exist.');
}

if ($resolvedStoragePath === $resolvedPublicPath
    || str_starts_with($resolvedStoragePath, $resolvedPublicPath . DIRECTORY_SEPARATOR)) {
    throw new RuntimeException('FILESYSTEM_PATH must be outside the public directory.');
}

$html = new HtmlDocumentParser();
$files = new PrivateSourceFileLocator($resolvedStoragePath);
$fetcher = new SafeUrlFetcher(
    new UrlNetworkGuard(new UrlSourceValidator()),
    $config->requireInt('ingestion.url_connect_timeout_seconds'),
    $config->requireInt('ingestion.url_request_timeout_seconds'),
    $config->requireInt('ingestion.url_maximum_redirects'),
    $config->requireInt('ingestion.url_maximum_response_bytes'),
    $config->requireString('ingestion.url_user_agent'),
);
$ocr = null;

if ($config->requireBool('ingestion.pdf_ocr_enabled')) {
    $languages = $config->get('ingestion.pdf_ocr_languages');

    if (!is_array($languages)) {
        throw new RuntimeException('PDF OCR languages are not configured correctly.');
    }

    $ocr = new OcrmyPdfEngine(
        $config->requireString('ingestion.pdf_ocr_binary'),
        $languages,
        $config->requireInt('ingestion.pdf_ocr_process_timeout_seconds'),
        $config->requireInt('ingestion.pdf_ocr_page_timeout_seconds'),
        $config->requireInt('ingestion.pdf_ocr_jobs'),
        $config->requireInt('ingestion.pdf_ocr_maximum_output_bytes'),
        $config->requireBool('ingestion.pdf_ocr_rotate_pages'),
        $config->requireBool('ingestion.pdf_ocr_deskew'),
    );
}
$processor = new DocumentIngestionProcessor(
    new PdoSourceIngestionRepository($connection),
    new ExtractorRegistry([
        new UrlExtractor($fetcher, $html),
        new MarkdownExtractor($files, $html),
        new PdfExtractor($files, $ocr),
    ]),
    new SemanticChunker(
        new HeuristicTokenEstimator(),
        $config->requireInt('ingestion.chunk_size_tokens'),
        $config->requireInt('ingestion.chunk_overlap_tokens'),
        $config->requireInt('ingestion.minimum_chunk_tokens'),
    ),
);
$worker = new IngestionWorker(
    $queue,
    $processor,
    $logger,
    $workerId,
    $config->requireInt('queue.poll_seconds'),
);

return ['worker' => $worker, 'queue' => $queue];
