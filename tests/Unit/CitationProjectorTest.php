<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\RAG\RetrievedChunk;
use App\RAG\CitationProjector;
use PHPUnit\Framework\TestCase;

final class CitationProjectorTest extends TestCase
{
    public function testItLinksUrlSourcesAndMarkdownCanonicalUrls(): void
    {
        $projector = new CitationProjector();
        $url = new RetrievedChunk(
            1, 1, 1, 1, 'URL content', 0.9, 'Web page', 'url', 'https://example.com/page', [],
        );
        $markdown = new RetrievedChunk(
            2, 2, 2, 1, 'Markdown content', 0.8, 'Guide', 'markdown', null,
            ['canonical_url' => 'https://example.com/guide'],
        );

        self::assertSame('https://example.com/page', $projector->public($url, 0)['url']);
        self::assertSame('https://example.com/guide', $projector->public($markdown, 1)['url']);
    }

    public function testItDoesNotExposeUnsafeOrUnsupportedCitationUrls(): void
    {
        $projector = new CitationProjector();
        $unsafeMarkdown = new RetrievedChunk(
            1, 1, 1, 1, 'Private content', 0.9, 'Private guide', 'markdown', null,
            ['canonical_url' => 'http://127.0.0.1/private'],
        );
        $pdf = new RetrievedChunk(
            2, 2, 2, 1, 'PDF content', 0.8, 'Upload', 'pdf', null,
            ['canonical_url' => 'https://example.com/not-approved'],
        );

        self::assertNull($projector->public($unsafeMarkdown, 0)['url']);
        self::assertNull($projector->public($pdf, 1)['url']);
    }
}
