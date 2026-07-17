<?php

declare(strict_types=1);

use App\Database\Connection;
use App\Ingestion\IngestionWorker;
use App\Ingestion\PipelineUnavailableProcessor;
use App\Logging\LoggerFactory;
use App\Repositories\PdoIngestionJobRepository;
use App\Services\Ingestion\IngestionQueue;
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
$repository = new PdoIngestionJobRepository(new Connection($config));
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
$worker = new IngestionWorker(
    $queue,
    new PipelineUnavailableProcessor(),
    $logger,
    $workerId,
    $config->requireInt('queue.poll_seconds'),
);

return ['worker' => $worker, 'queue' => $queue];
