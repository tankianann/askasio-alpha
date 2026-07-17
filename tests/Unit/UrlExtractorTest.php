<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Ingestion\FetchedPage;
use App\Domain\Sources\ProcessingStatus;
use App\Domain\Sources\SourceType;
use App\Domain\Sources\SourceVersion;
use App\Ingestion\Extractors\HtmlDocumentParser;
use App\Ingestion\Extractors\UrlExtractor;
use PHPUnit\Framework\TestCase;
use Tests\Fakes\FakeUrlFetcher;

final class UrlExtractorTest extends TestCase
{
    public function testItExtractsUrlTextAndResponseMetadata(): void
    {
        $fetcher = new FakeUrlFetcher(new FetchedPage(
            'https://example.com/final',
            '<html><head><title>Terms</title></head><body><main><h1>Terms</h1><p>Payment is due monthly.</p></main></body></html>',
            'text/html; charset=UTF-8',
            200,
            [],
        ));
        $version = new SourceVersion(
            4, 2, 1, null, 'https://example.com/start', null, null, null, null,
            ProcessingStatus::Processing, null, '2026-01-01', null, null, null, SourceType::Url,
        );

        $document = (new UrlExtractor($fetcher, new HtmlDocumentParser()))->extract($version);

        self::assertStringContainsString('Payment is due monthly.', $document->content);
        self::assertSame('https://example.com/final', $document->metadata['url']);
        self::assertSame('https://example.com/start', $document->metadata['original_url']);
    }
}
