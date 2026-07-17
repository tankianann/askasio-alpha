<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Ingestion\Extractors\HtmlDocumentParser;
use PHPUnit\Framework\TestCase;

final class HtmlDocumentParserTest extends TestCase
{
    public function testItRemovesNoiseAndPreservesDocumentStructure(): void
    {
        $html = <<<'HTML'
            <html><head><title>Refund policy</title><link rel="canonical" href="https://example.com/refunds"></head>
            <body><nav>Private navigation</nav><main>
            <h1>Refund policy</h1><p>Returns are accepted within 30 days.</p>
            <h2>Exceptions</h2><p>Final sale items are excluded.</p><script>secret()</script>
            </main></body></html>
            HTML;

        $document = (new HtmlDocumentParser())->parse($html, 'Fallback');

        self::assertSame('Refund policy', $document->title);
        self::assertStringContainsString('Returns are accepted', $document->content);
        self::assertStringNotContainsString('Private navigation', $document->content);
        self::assertStringNotContainsString('secret()', $document->content);
        self::assertSame('https://example.com/refunds', $document->metadata['canonical_url']);
        self::assertSame(['Refund policy', 'Exceptions'], $document->sections[1]->headingHierarchy);
    }
}
