<?php

declare(strict_types=1);

namespace App\Domain\RAG;

final class SelectedContext
{
    /** @param list<RetrievedChunk> $chunks */
    public function __construct(
        public readonly array $chunks,
        public readonly int $estimatedTokens,
    ) {
    }
}
