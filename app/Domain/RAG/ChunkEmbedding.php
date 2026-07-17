<?php

declare(strict_types=1);

namespace App\Domain\RAG;

final class ChunkEmbedding
{
    /** @param list<float> $vector */
    public function __construct(
        public readonly int $chunkId,
        public readonly array $vector,
        public readonly string $model,
    ) {
    }
}
