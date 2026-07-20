<?php

declare(strict_types=1);

namespace App\Domain\RAG;

final class GeneratedAnswer
{
    /**
     * @param list<RetrievedChunk> $chunks
     * @param array<string, int> $usage
     */
    public function __construct(
        public readonly string $answer,
        public readonly array $chunks,
        public readonly array $usage,
        public readonly ?string $model = null,
        public readonly bool $fallback = false,
    ) {
    }
}
