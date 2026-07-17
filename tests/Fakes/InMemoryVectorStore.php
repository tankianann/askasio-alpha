<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Domain\RAG\ChunkEmbedding;
use App\Domain\RAG\EmbeddableChunk;
use App\Domain\RAG\RetrievedChunk;
use App\RAG\VectorStoreInterface;

final class InMemoryVectorStore implements VectorStoreInterface
{
    /** @var list<float> */
    public array $queryEmbedding = [];

    /** @var array<string, mixed> */
    public array $filters = [];

    public int $limit = 0;

    /** @var list<ChunkEmbedding> */
    public array $upserts = [];

    /** @var list<int> */
    public array $deletedVersions = [];

    public bool $activeEmbeddingsCleared = false;

    /**
     * @param list<RetrievedChunk> $matches
     * @param list<EmbeddableChunk> $pending
     */
    public function __construct(
        private readonly array $matches = [],
        private readonly array $pending = [],
    ) {
    }

    public function upsertChunks(array $chunks): void
    {
        $this->upserts = $chunks;
    }

    public function search(array $queryEmbedding, int $limit, array $filters = []): array
    {
        $this->queryEmbedding = $queryEmbedding;
        $this->limit = $limit;
        $this->filters = $filters;

        return array_slice($this->matches, 0, $limit);
    }

    public function deleteVersion(int $sourceVersionId): void
    {
        $this->deletedVersions[] = $sourceVersionId;
    }

    public function clearActiveEmbeddings(): int
    {
        $this->activeEmbeddingsCleared = true;

        return count($this->pending);
    }

    public function pendingActiveChunks(int $limit, string $embeddingModel): array
    {
        return array_slice($this->pending, 0, $limit);
    }
}
