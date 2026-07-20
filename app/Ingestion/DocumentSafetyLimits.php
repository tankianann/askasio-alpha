<?php

declare(strict_types=1);

namespace App\Ingestion;

use App\Domain\Ingestion\ExtractedDocument;
use InvalidArgumentException;

final class DocumentSafetyLimits
{
    public function __construct(
        public readonly int $maximumExtractedCharacters,
        public readonly int $maximumChunks,
        public readonly int $maximumPdfPages,
    ) {
        if ($this->maximumExtractedCharacters < 1 || $this->maximumExtractedCharacters > 100_000_000) {
            throw new InvalidArgumentException('The extracted-document character limit must be between 1 and 100,000,000.');
        }

        if ($this->maximumChunks < 1 || $this->maximumChunks > 100_000) {
            throw new InvalidArgumentException('The chunks-per-document limit must be between 1 and 100,000.');
        }

        if ($this->maximumPdfPages < 1 || $this->maximumPdfPages > 100_000) {
            throw new InvalidArgumentException('The PDF page limit must be between 1 and 100,000.');
        }
    }

    public function assertDocument(ExtractedDocument $document): void
    {
        $this->assertExtractedCharacters(mb_strlen($document->content, 'UTF-8'));
    }

    public function assertExtractedCharacters(int $characters): void
    {
        if ($characters > $this->maximumExtractedCharacters) {
            throw new PermanentIngestionException(sprintf(
                'The extracted document exceeded the configured %s character limit.',
                number_format($this->maximumExtractedCharacters),
            ));
        }
    }

    public function assertChunkCount(int $chunks): void
    {
        if ($chunks > $this->maximumChunks) {
            throw new PermanentIngestionException(sprintf(
                'The extracted document exceeded the configured %s chunk limit.',
                number_format($this->maximumChunks),
            ));
        }
    }

    public function assertPdfPageCount(int $pages): void
    {
        if ($pages > $this->maximumPdfPages) {
            throw new PermanentIngestionException(sprintf(
                'The PDF exceeded the configured %s page limit.',
                number_format($this->maximumPdfPages),
            ));
        }
    }
}
