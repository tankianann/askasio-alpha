<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Ingestion\ExtractedDocument;
use App\Domain\Ingestion\ExtractedSection;
use PHPUnit\Framework\TestCase;

final class ExtractedDocumentTest extends TestCase
{
    public function testItNormalizesSectionsRecordsOffsetsAndHashesContent(): void
    {
        $document = ExtractedDocument::fromSections('Policy', [
            new ExtractedSection('First', '  Alpha text  '),
            new ExtractedSection('Empty', '   '),
            new ExtractedSection('Second', 'Beta text', ['Policy', 'Second'], 2),
        ]);

        self::assertSame("Alpha text\n\nBeta text", $document->content);
        self::assertCount(2, $document->sections);
        self::assertSame(0, $document->sections[0]->startOffset);
        self::assertSame(10, $document->sections[0]->endOffset);
        self::assertSame(12, $document->sections[1]->startOffset);
        self::assertSame(21, $document->sections[1]->endOffset);
        self::assertSame(hash('sha256', "Alpha text\n\nBeta text"), $document->contentHash());
    }
}
