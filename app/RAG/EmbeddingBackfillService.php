<?php

declare(strict_types=1);

namespace App\RAG;

use App\Domain\RAG\ChunkEmbedding;
use App\Providers\Embeddings\EmbeddingProviderInterface;
use RuntimeException;

final class EmbeddingBackfillService
{
    public function __construct(
        private readonly EmbeddingProviderInterface $provider,
        private readonly VectorStoreInterface $vectors,
        private readonly int $batchSize,
    ) {
        if ($this->batchSize < 1 || $this->batchSize > 1000) {
            throw new \InvalidArgumentException('Backfill batch size must be between 1 and 1000.');
        }
    }

    public function runBatch(): int
    {
        $chunks = $this->vectors->pendingActiveChunks($this->batchSize, $this->provider->model());

        if ($chunks === []) {
            return 0;
        }

        $embeddings = $this->provider->embedBatch(array_map(
            static fn ($chunk): string => $chunk->content,
            $chunks,
        ));

        if (count($chunks) !== count($embeddings)) {
            throw new RuntimeException('The provider returned an unexpected number of embeddings during backfill.');
        }

        $upserts = [];

        foreach ($chunks as $index => $chunk) {
            $upserts[] = new ChunkEmbedding($chunk->id, $embeddings[$index], $this->provider->model());
        }

        $this->vectors->upsertChunks($upserts);

        return count($upserts);
    }
}
