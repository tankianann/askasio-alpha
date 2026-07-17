<?php

declare(strict_types=1);

namespace App\Domain\Ingestion;

final class ExtractedSection
{
    /**
     * @param list<string> $headingHierarchy
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public readonly ?string $title,
        public readonly string $text,
        public readonly array $headingHierarchy = [],
        public readonly ?int $page = null,
        public readonly array $metadata = [],
        public readonly int $startOffset = 0,
        public readonly int $endOffset = 0,
    ) {
    }

    public function withOffsets(int $startOffset, int $endOffset): self
    {
        return new self(
            $this->title,
            $this->text,
            $this->headingHierarchy,
            $this->page,
            $this->metadata,
            $startOffset,
            $endOffset,
        );
    }
}
