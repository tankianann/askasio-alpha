<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Ingestion\ExtractedDocument;
use App\Domain\Ingestion\ExtractedSection;
use App\Ingestion\DocumentSafetyLimits;
use App\Ingestion\PermanentIngestionException;
use PHPUnit\Framework\TestCase;

final class DocumentSafetyLimitsTest extends TestCase
{
    public function testItRejectsAnOversizedExtractedDocument(): void
    {
        $limits = new DocumentSafetyLimits(10, 2, 1);
        $document = ExtractedDocument::fromSections('Large', [
            new ExtractedSection('Large', '12345678901'),
        ]);

        $this->expectException(PermanentIngestionException::class);
        $this->expectExceptionMessage('10 character limit');
        $limits->assertDocument($document);
    }

    public function testItRejectsTooManyChunks(): void
    {
        $limits = new DocumentSafetyLimits(100, 2, 1);

        $this->expectException(PermanentIngestionException::class);
        $this->expectExceptionMessage('2 chunk limit');
        $limits->assertChunkCount(3);
    }

    public function testItRejectsTooManyPdfPages(): void
    {
        $limits = new DocumentSafetyLimits(100, 2, 1);

        $this->expectException(PermanentIngestionException::class);
        $this->expectExceptionMessage('1 page limit');
        $limits->assertPdfPageCount(2);
    }
}
