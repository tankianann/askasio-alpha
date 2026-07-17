<?php

declare(strict_types=1);

namespace App\Domain\RAG;

final class EmbeddableChunk
{
    public function __construct(
        public readonly int $id,
        public readonly string $content,
    ) {
    }
}
