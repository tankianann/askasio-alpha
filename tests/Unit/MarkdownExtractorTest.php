<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Sources\ProcessingStatus;
use App\Domain\Sources\SourceType;
use App\Domain\Sources\SourceVersion;
use App\Ingestion\Extractors\HtmlDocumentParser;
use App\Ingestion\Extractors\MarkdownExtractor;
use App\Services\Sources\PrivateSourceFileLocator;
use PHPUnit\Framework\TestCase;

final class MarkdownExtractorTest extends TestCase
{
    public function testItExtractsHeadingsAndStripsEmbeddedHtml(): void
    {
        $directory = sys_get_temp_dir() . '/rag-md-' . bin2hex(random_bytes(6));
        mkdir($directory, 0700);
        file_put_contents($directory . '/document.md', "# Returns\n\nRefunds take five days.\n\n<script>bad()</script>\n\n## Timing\n\nContact support.");

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
            self::assertSame(['Returns', 'Timing'], $document->sections[1]->headingHierarchy);
        } finally {
            @unlink($directory . '/document.md');
            @rmdir($directory);
        }
    }
}
