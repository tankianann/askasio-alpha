<?php

declare(strict_types=1);

namespace App\Domain\Ingestion;

final class Chunk
{
    /**
     * @param array<string, mixed> $metadata
     * @param null|list<float> $embedding
     */
    public function __construct(
        public readonly int $number,
        public readonly string $content,
        public readonly int $tokenCount,
        public readonly array $metadata,
        public readonly ?array $embedding = null,
        public readonly ?string $embeddingModel = null,
    ) {
    }

    /** @param list<float> $embedding */
    public function withEmbedding(array $embedding, string $model): self
    {
        return new self(
            $this->number,
            $this->content,
            $this->tokenCount,
            $this->metadata,
            $embedding,
            $model,
        );
    }
}
