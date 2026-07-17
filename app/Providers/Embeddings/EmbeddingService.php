<?php

declare(strict_types=1);

namespace App\Providers\Embeddings;

use App\Domain\Ingestion\Chunk;
use InvalidArgumentException;

final class EmbeddingService
{
    public function __construct(
        private readonly EmbeddingProviderInterface $provider,
        private readonly int $batchSize,
    ) {
        if ($this->batchSize < 1 || $this->batchSize > 2048) {
            throw new InvalidArgumentException('Embedding batch size must be between 1 and 2048.');
        }
    }

    /**
     * @param list<Chunk> $chunks
     * @return list<Chunk>
     */
    public function embedChunks(array $chunks): array
    {
        $embedded = [];

        foreach (array_chunk($chunks, $this->batchSize) as $batch) {
            $texts = array_map(static fn (Chunk $chunk): string => $chunk->content, $batch);
            $vectors = $this->provider->embedBatch($texts);

            if (count($vectors) !== count($batch)) {
                throw new MalformedEmbeddingResponseException('The embedding provider returned an unexpected number of vectors.');
            }

            foreach ($batch as $index => $chunk) {
                $vector = $vectors[$index] ?? null;

                if (!is_array($vector) || $vector === []) {
                    throw new MalformedEmbeddingResponseException('The embedding provider returned an empty vector.');
                }

                $embedded[] = $chunk->withEmbedding($vector, $this->provider->model());
            }
        }

        return $embedded;
    }
}
