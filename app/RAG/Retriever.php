<?php

declare(strict_types=1);

namespace App\RAG;

use App\Domain\RAG\RetrievedChunk;
use App\Providers\Embeddings\EmbeddingProviderInterface;
use InvalidArgumentException;

final class Retriever
{
    public function __construct(
        private readonly EmbeddingProviderInterface $embeddings,
        private readonly VectorStoreInterface $vectors,
        private readonly int $defaultTopK,
        private readonly int $maximumTopK,
        private readonly float $minimumSimilarity,
    ) {
        if ($this->defaultTopK < 1 || $this->maximumTopK < $this->defaultTopK || $this->maximumTopK > 100) {
            throw new InvalidArgumentException('Retrieval top-k configuration is invalid.');
        }

        if ($this->minimumSimilarity < -1.0 || $this->minimumSimilarity > 1.0) {
            throw new InvalidArgumentException('Retrieval similarity threshold must be between -1 and 1.');
        }
    }

    /**
     * @param array<string, mixed> $filters
     * @return list<RetrievedChunk>
     */
    public function retrieve(string $query, ?int $topK = null, array $filters = []): array
    {
        $query = trim($query);

        if ($query === '') {
            throw new InvalidArgumentException('A retrieval query is required.');
        }

        $limit = $topK ?? $this->defaultTopK;

        if ($limit < 1 || $limit > $this->maximumTopK) {
            throw new InvalidArgumentException(sprintf('top_k must be between 1 and %d.', $this->maximumTopK));
        }

        $queryEmbedding = $this->embeddings->embed($query);
        $filters['embedding_model'] = $this->embeddings->model();
        $filters['minimum_similarity'] ??= $this->minimumSimilarity;

        return $this->vectors->search($queryEmbedding, $limit, $filters);
    }
}
