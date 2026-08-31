<?php

declare(strict_types=1);

namespace App\RAG;

use App\Domain\RAG\RetrievedChunk;
use App\Exceptions\ValidationException;
use App\Security\UrlSourceValidator;

final class CitationProjector
{
    public function __construct(private readonly UrlSourceValidator $urls = new UrlSourceValidator())
    {
    }

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
            'url' => $this->publicUrl($chunk),
        ];
    }

    private function publicUrl(RetrievedChunk $chunk): ?string
    {
        $candidate = match ($chunk->sourceType) {
            'url' => $chunk->sourceUrl,
            'markdown' => $chunk->metadata['canonical_url'] ?? null,
            default => null,
        };

        if (!is_string($candidate) || trim($candidate) === '') {
            return null;
        }

        try {
            return $this->urls->validate($candidate);
        } catch (ValidationException) {
            return null;
        }
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
