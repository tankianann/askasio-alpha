<?php

declare(strict_types=1);

namespace App\RAG;

use App\Domain\RAG\RetrievedChunk;

final class CitationProjector
{
    /** @return array<string, mixed> */
    public function legacy(RetrievedChunk $chunk, int $index): array
    {
        return [
            'reference' => 'S' . ($index + 1),
            'source_id' => $chunk->sourceId,
            'source_version_id' => $chunk->sourceVersionId,
            'chunk_id' => $chunk->chunkId,
            'source_name' => $chunk->sourceName,
            'source_type' => $chunk->sourceType,
            'source_url' => $chunk->sourceUrl,
            'page' => $chunk->page(),
            'heading' => $chunk->heading(),
            'excerpt' => $this->excerpt($chunk),
        ];
    }

    /** @return array{reference: string, title: string, heading: ?string, page: ?int, url: ?string} */
    public function public(RetrievedChunk $chunk, int $index): array
    {
        return [
            'reference' => 'S' . ($index + 1),
            'title' => $chunk->sourceName,
            'heading' => $chunk->heading(),
            'page' => $chunk->page(),
            'url' => $chunk->sourceType === 'url' ? $chunk->sourceUrl : null,
        ];
    }

    /** @return array<string, mixed> */
    public function diagnostic(RetrievedChunk $chunk, int $index): array
    {
        return [
            'reference' => 'S' . ($index + 1),
            'source_id' => $chunk->sourceId,
            'source_version_id' => $chunk->sourceVersionId,
            'chunk_id' => $chunk->chunkId,
            'similarity' => round($chunk->similarity, 6),
            'excerpt' => $this->excerpt($chunk),
        ];
    }

    private function excerpt(RetrievedChunk $chunk): string
    {
        $excerpt = preg_replace('/\s+/u', ' ', trim($chunk->content)) ?? trim($chunk->content);

        return mb_strlen($excerpt) > 280
            ? rtrim(mb_substr($excerpt, 0, 279)) . '…'
            : $excerpt;
    }
}
