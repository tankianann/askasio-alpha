<?php

declare(strict_types=1);

namespace App\Ingestion\Extractors;

use App\Domain\Ingestion\ExtractedDocument;
use App\Domain\Sources\SourceType;
use App\Domain\Sources\SourceVersion;
use App\Ingestion\PermanentIngestionException;
use App\Ingestion\SourceExtractorInterface;
use App\Networking\UrlFetcherInterface;

final class UrlExtractor implements SourceExtractorInterface
{
    public function __construct(
        private readonly UrlFetcherInterface $fetcher,
        private readonly HtmlDocumentParser $html,
    ) {
    }

    public function supports(SourceVersion $version): bool
    {
        return $version->sourceType === SourceType::Url || $version->originalUrl !== null;
    }

    public function extract(SourceVersion $version): ExtractedDocument
    {
        if ($version->originalUrl === null) {
            throw new PermanentIngestionException('The URL source version has no original URL.');
        }

        $page = $this->fetcher->fetch($version->originalUrl);
        $body = $this->normalizeEncoding($page->body, $page->contentType);

        return $this->html->parse($body, $page->url, [
            'source_type' => SourceType::Url->value,
            'url' => $page->url,
            'original_url' => $version->originalUrl,
            'content_type' => $page->contentType,
            'http_status' => $page->statusCode,
        ]);
    }

    private function normalizeEncoding(string $body, string $contentType): string
    {
        $encoding = null;

        if (preg_match('/charset\s*=\s*["\']?([^;\s"\']+)/i', $contentType, $match) === 1) {
            $encoding = $match[1];
        }

        $encoding ??= mb_detect_encoding($body, ['UTF-8', 'Windows-1252', 'ISO-8859-1'], true) ?: 'UTF-8';
        $body = mb_convert_encoding($body, 'UTF-8', $encoding);

        if (preg_match('//u', $body) !== 1) {
            throw new PermanentIngestionException('The URL response encoding could not be normalized.');
        }

        return $body;
    }
}
