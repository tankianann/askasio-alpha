<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Sources\ProcessingStatus;
use App\Domain\Sources\SourceType;
use App\Domain\Sources\SourceVersion;
use App\Ingestion\Extractors\HtmlDocumentParser;
use App\Ingestion\Extractors\MarkdownExtractor;
use App\Ingestion\DocumentSafetyLimits;
use App\Ingestion\PermanentIngestionException;
use App\Services\Sources\PrivateSourceFileLocator;
use PHPUnit\Framework\TestCase;

final class MarkdownExtractorTest extends TestCase
{
    public function testItExtractsHeadingsAndStripsEmbeddedHtml(): void
    {
        $directory = sys_get_temp_dir() . '/rag-md-' . bin2hex(random_bytes(6));
        mkdir($directory, 0700);
        file_put_contents($directory . '/document.md', "\xEF\xBB\xBF---\ntitle: Refund policy\ncanonical_url: https://example.com/refunds\nsource_id: 1314\n---\n\n# Returns\n\nRefunds take five days.\n\n<script>bad()</script>\n\n## Timing\n\nContact support.");

        try {
            $version = new SourceVersion(
                1, 1, 1, 'returns.md', null, 'document.md', null, 'text/markdown', 100,
                ProcessingStatus::Processing, null, '2026-01-01', null, null, null, SourceType::Markdown,
            );
            $extractor = new MarkdownExtractor(
                new PrivateSourceFileLocator($directory),
                new HtmlDocumentParser(),
            );
            $document = $extractor->extract($version);

            self::assertSame('Returns', $document->title);
            self::assertStringContainsString('Refunds take five days.', $document->content);
            self::assertStringNotContainsString('bad()', $document->content);
            self::assertStringNotContainsString('source_id', $document->content);
            self::assertSame('https://example.com/refunds', $document->metadata['canonical_url']);
            self::assertSame(['Returns', 'Timing'], $document->sections[1]->headingHierarchy);
        } finally {
            @unlink($directory . '/document.md');
            @rmdir($directory);
        }
    }

    public function testItRejectsAnUnsafeCanonicalUrl(): void
    {
        $directory = sys_get_temp_dir() . '/rag-md-canonical-' . bin2hex(random_bytes(6));
        mkdir($directory, 0700);
        file_put_contents($directory . '/document.md', "---\ntitle: Internal\ncanonical: http://127.0.0.1/private\n---\n\n# Internal\n\nPrivate content.");

        try {
            $version = new SourceVersion(
                1, 1, 1, 'internal.md', null, 'document.md', null, 'text/markdown', 100,
                ProcessingStatus::Processing, null, '2026-01-01', null, null, null, SourceType::Markdown,
            );
            $extractor = new MarkdownExtractor(
                new PrivateSourceFileLocator($directory),
                new HtmlDocumentParser(),
            );

            $this->expectException(PermanentIngestionException::class);
            $this->expectExceptionMessage('public HTTP or HTTPS URL');
            $extractor->extract($version);
        } finally {
            @unlink($directory . '/document.md');
            @rmdir($directory);
        }
    }

    public function testItRejectsOversizedMarkdownBeforeHtmlConversion(): void
    {
        $directory = sys_get_temp_dir() . '/rag-md-large-' . bin2hex(random_bytes(6));
        mkdir($directory, 0700);
        file_put_contents($directory . '/document.md', str_repeat('a', 101));
        $limits = new DocumentSafetyLimits(100, 10, 10);

        try {
            $version = new SourceVersion(
                1, 1, 1, 'large.md', null, 'document.md', null, 'text/markdown', 101,
                ProcessingStatus::Processing, null, '2026-01-01', null, null, null, SourceType::Markdown,
            );
            $extractor = new MarkdownExtractor(
                new PrivateSourceFileLocator($directory),
                new HtmlDocumentParser($limits),
                $limits,
            );

            $this->expectException(PermanentIngestionException::class);
            $this->expectExceptionMessage('100 character limit');
            $extractor->extract($version);
        } finally {
            @unlink($directory . '/document.md');
            @rmdir($directory);
        }
    }
}
