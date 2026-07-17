<?php

declare(strict_types=1);

namespace App\RAG;

use App\Domain\RAG\ChunkEmbedding;
use App\Domain\RAG\EmbeddableChunk;
use App\Domain\RAG\RetrievedChunk;

interface VectorStoreInterface
{
    /**
     * @param list<ChunkEmbedding> $chunks
     */
    public function upsertChunks(array $chunks): void;

    /**
     * Supported filters: embedding_model, minimum_similarity, source_ids, source_types.
     *
     * @param list<float> $queryEmbedding
     * @param array<string, mixed> $filters
     * @return list<RetrievedChunk>
     */
    public function search(array $queryEmbedding, int $limit, array $filters = []): array;

    public function deleteVersion(int $sourceVersionId): void;

    public function clearActiveEmbeddings(): int;

    /** @return list<EmbeddableChunk> */
    public function pendingActiveChunks(int $limit, string $embeddingModel): array;
}
