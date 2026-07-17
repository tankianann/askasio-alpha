<?php

declare(strict_types=1);

namespace App\Domain\RAG;

final class RetrievedChunk
{
    /** @param array<string, mixed> $metadata */
    public function __construct(
        public readonly int $chunkId,
        public readonly int $sourceId,
        public readonly int $sourceVersionId,
        public readonly int $chunkNumber,
        public readonly string $content,
        public readonly float $similarity,
        public readonly string $sourceName,
        public readonly string $sourceType,
        public readonly ?string $sourceUrl,
        public readonly array $metadata,
    ) {
    }

    public function page(): ?int
    {
        $page = $this->metadata['page'] ?? null;

        return is_int($page) ? $page : (is_numeric($page) ? (int) $page : null);
    }

    public function heading(): ?string
    {
        foreach (['section_title', 'heading'] as $key) {
            $heading = $this->metadata[$key] ?? null;

            if (is_string($heading) && trim($heading) !== '') {
                return $heading;
            }
        }

        $hierarchy = $this->metadata['heading_hierarchy'] ?? null;

        if (is_array($hierarchy)) {
            $headings = array_values(array_filter($hierarchy, 'is_string'));

            return $headings === [] ? null : end($headings);
        }

        return null;
    }
}
