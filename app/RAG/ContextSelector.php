<?php

declare(strict_types=1);

namespace App\RAG;

use App\Domain\RAG\RetrievedChunk;
use App\Domain\RAG\SelectedContext;
use App\Ingestion\Chunking\HeuristicTokenEstimator;
use InvalidArgumentException;

final class ContextSelector
{
    public function __construct(
        private readonly HeuristicTokenEstimator $tokens,
        private readonly int $maximumTokens,
    ) {
        if ($this->maximumTokens < 256) {
            throw new InvalidArgumentException('RAG chat context must allow at least 256 estimated tokens.');
        }
    }

    /** @param list<RetrievedChunk> $chunks */
    public function select(array $chunks): SelectedContext
    {
        $selected = [];
        $used = 0;

        foreach ($chunks as $chunk) {
            if (!$chunk instanceof RetrievedChunk) {
                throw new InvalidArgumentException('RAG context must contain retrieved chunks.');
            }

            $overhead = $this->tokens->estimate($this->metadataText($chunk)) + 12;
            $contentTokens = $this->tokens->estimate($chunk->content);
            $required = $overhead + $contentTokens;

            if ($used + $required <= $this->maximumTokens) {
                $selected[] = $chunk;
                $used += $required;
                continue;
            }

            if ($selected !== []) {
                continue;
            }

            $availableContentTokens = $this->maximumTokens - $overhead;

            if ($availableContentTokens < 16) {
                continue;
            }

            $truncated = $this->truncate($chunk->content, $availableContentTokens);
            $truncatedChunk = new RetrievedChunk(
                $chunk->chunkId,
                $chunk->sourceId,
                $chunk->sourceVersionId,
                $chunk->chunkNumber,
                $truncated,
                $chunk->similarity,
                $chunk->sourceName,
                $chunk->sourceType,
                $chunk->sourceUrl,
                $chunk->metadata,
            );
            $selected[] = $truncatedChunk;
            $used = min($this->maximumTokens, $overhead + $this->tokens->estimate($truncated));
        }

        return new SelectedContext($selected, $used);
    }

    private function metadataText(RetrievedChunk $chunk): string
    {
        return implode(' ', array_filter([
            $chunk->sourceName,
            $chunk->sourceType,
            $chunk->sourceUrl,
            $chunk->heading(),
            $chunk->page() === null ? null : (string) $chunk->page(),
        ], static fn (?string $value): bool => $value !== null && $value !== ''));
    }

    private function truncate(string $content, int $tokenLimit): string
    {
        $candidate = mb_substr(trim($content), 0, $tokenLimit * 4, 'UTF-8');

        while ($candidate !== '' && $this->tokens->estimate($candidate . '…') > $tokenLimit) {
            $candidate = mb_substr($candidate, 0, max(0, mb_strlen($candidate, 'UTF-8') - 4), 'UTF-8');
        }

        $wordBoundary = mb_strrpos($candidate, ' ', 0, 'UTF-8');

        if ($wordBoundary !== false && $wordBoundary > (int) (mb_strlen($candidate, 'UTF-8') * 0.75)) {
            $candidate = mb_substr($candidate, 0, $wordBoundary, 'UTF-8');
        }

        return rtrim($candidate) . '…';
    }
}
