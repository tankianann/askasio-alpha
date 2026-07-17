<?php

declare(strict_types=1);

namespace App\RAG;

use App\Database\Connection;
use App\Domain\RAG\ChunkEmbedding;
use App\Domain\RAG\EmbeddableChunk;
use App\Domain\RAG\RetrievedChunk;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

final class PdoVectorStore implements VectorStoreInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly CosineSimilarity $cosine,
    ) {
    }

    public function upsertChunks(array $chunks): void
    {
        if ($chunks === []) {
            return;
        }

        $pdo = $this->connection->pdo();
        $pdo->beginTransaction();

        try {
            $statement = $pdo->prepare(
                'UPDATE source_chunks
                 SET embedding = :embedding, embedding_model = :model,
                     embedding_dimensions = :dimensions, embedded_at = UTC_TIMESTAMP(6)
                 WHERE id = :id',
            );

            foreach ($chunks as $chunk) {
                if (!$chunk instanceof ChunkEmbedding || $chunk->vector === [] || trim($chunk->model) === '') {
                    throw new InvalidArgumentException('Vector upserts require valid chunk embeddings.');
                }

                foreach ($chunk->vector as $value) {
                    if (!is_float($value) && !is_int($value) || !is_finite((float) $value)) {
                        throw new InvalidArgumentException('Embedding vectors must contain finite numbers.');
                    }
                }

                $statement->execute([
                    'embedding' => json_encode($chunk->vector, JSON_THROW_ON_ERROR),
                    'model' => $chunk->model,
                    'dimensions' => count($chunk->vector),
                    'id' => $chunk->chunkId,
                ]);

                if ($statement->rowCount() !== 1) {
                    throw new RuntimeException(sprintf('Chunk %d could not be updated.', $chunk->chunkId));
                }
            }

            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $exception;
        }
    }

    public function search(array $queryEmbedding, int $limit, array $filters = []): array
    {
        if ($queryEmbedding === []) {
            throw new InvalidArgumentException('A query embedding is required.');
        }

        if ($limit < 1 || $limit > 100) {
            throw new InvalidArgumentException('Retrieval limit must be between 1 and 100.');
        }

        $model = $filters['embedding_model'] ?? null;

        if (!is_string($model) || trim($model) === '') {
            throw new InvalidArgumentException('The embedding_model retrieval filter is required.');
        }

        $minimumSimilarity = $filters['minimum_similarity'] ?? -1.0;

        if ((!is_int($minimumSimilarity) && !is_float($minimumSimilarity))
            || !is_finite((float) $minimumSimilarity)
            || (float) $minimumSimilarity < -1.0
            || (float) $minimumSimilarity > 1.0) {
            throw new InvalidArgumentException('minimum_similarity must be between -1 and 1.');
        }

        $parameters = ['model' => $model, 'dimensions' => count($queryEmbedding)];
        $conditions = [
            "s.status = 'enabled'",
            's.deleted_at IS NULL',
            's.active_version_id = sv.id',
            "sv.processing_status = 'ready'",
            'sc.embedding IS NOT NULL',
            'sc.embedding_model = :model',
            'sc.embedding_dimensions = :dimensions',
        ];

        $this->addIntegerListFilter($conditions, $parameters, 's.id', 'source_id', $filters['source_ids'] ?? null);
        $this->addStringListFilter(
            $conditions,
            $parameters,
            's.source_type',
            'source_type',
            $filters['source_types'] ?? null,
            ['url', 'markdown', 'pdf'],
        );

        $sql = 'SELECT sc.id AS chunk_id, sc.source_version_id, sc.chunk_number, sc.content,
                       sc.embedding, sc.metadata_json, sv.original_url, s.id AS source_id,
                       s.name AS source_name, s.source_type
                FROM source_chunks sc
                INNER JOIN source_versions sv ON sv.id = sc.source_version_id
                INNER JOIN sources s ON s.id = sv.source_id
                WHERE ' . implode(' AND ', $conditions);
        $statement = $this->connection->pdo()->prepare($sql);
        $statement->execute($parameters);
        $matches = [];

        foreach ($statement->fetchAll() as $row) {
            try {
                $vector = json_decode((string) $row['embedding'], true, flags: JSON_THROW_ON_ERROR);

                if (!is_array($vector)) {
                    continue;
                }

                $similarity = $this->cosine->calculate($queryEmbedding, $vector);
            } catch (Throwable) {
                continue;
            }

            if ($similarity < (float) $minimumSimilarity) {
                continue;
            }

            $metadata = json_decode((string) $row['metadata_json'], true, flags: JSON_THROW_ON_ERROR);
            $matches[] = new RetrievedChunk(
                (int) $row['chunk_id'],
                (int) $row['source_id'],
                (int) $row['source_version_id'],
                (int) $row['chunk_number'],
                (string) $row['content'],
                $similarity,
                (string) $row['source_name'],
                (string) $row['source_type'],
                isset($row['original_url']) ? (string) $row['original_url'] : null,
                is_array($metadata) ? $metadata : [],
            );
        }

        usort(
            $matches,
            static fn (RetrievedChunk $left, RetrievedChunk $right): int =>
                $right->similarity <=> $left->similarity ?: $left->chunkId <=> $right->chunkId,
        );

        return array_slice($matches, 0, $limit);
    }

    public function deleteVersion(int $sourceVersionId): void
    {
        $statement = $this->connection->pdo()->prepare(
            'UPDATE source_chunks
             SET embedding = NULL, embedding_model = NULL, embedding_dimensions = NULL, embedded_at = NULL
             WHERE source_version_id = :version_id',
        );
        $statement->execute(['version_id' => $sourceVersionId]);
    }

    public function clearActiveEmbeddings(): int
    {
        $statement = $this->connection->pdo()->prepare(
            "UPDATE source_chunks sc
             INNER JOIN source_versions sv ON sv.id = sc.source_version_id
             INNER JOIN sources s ON s.id = sv.source_id
             SET sc.embedding = NULL, sc.embedding_model = NULL,
                 sc.embedding_dimensions = NULL, sc.embedded_at = NULL
             WHERE s.active_version_id = sv.id
               AND sv.processing_status = 'ready'
               AND s.deleted_at IS NULL",
        );
        $statement->execute();

        return $statement->rowCount();
    }

    public function pendingActiveChunks(int $limit, string $embeddingModel): array
    {
        $limit = max(1, min($limit, 1000));
        $statement = $this->connection->pdo()->prepare(
            "SELECT sc.id, sc.content
             FROM source_chunks sc
             INNER JOIN source_versions sv ON sv.id = sc.source_version_id
             INNER JOIN sources s ON s.id = sv.source_id
             WHERE (sc.embedding IS NULL OR sc.embedding_model IS NULL OR sc.embedding_model <> :model)
               AND s.active_version_id = sv.id
               AND sv.processing_status = 'ready'
               AND s.deleted_at IS NULL
             ORDER BY sc.id ASC
             LIMIT " . $limit,
        );
        $statement->execute(['model' => $embeddingModel]);

        return array_map(
            static fn (array $row): EmbeddableChunk => new EmbeddableChunk((int) $row['id'], (string) $row['content']),
            $statement->fetchAll(PDO::FETCH_ASSOC),
        );
    }

    /**
     * @param list<string> $conditions
     * @param array<string, int|string> $parameters
     */
    private function addIntegerListFilter(
        array &$conditions,
        array &$parameters,
        string $column,
        string $prefix,
        mixed $values,
    ): void {
        if ($values === null) {
            return;
        }

        if (!is_array($values) || $values === []) {
            throw new InvalidArgumentException(sprintf('%s must be a non-empty list.', $prefix));
        }

        $placeholders = [];

        foreach (array_values($values) as $index => $value) {
            if (!is_int($value) || $value < 1) {
                throw new InvalidArgumentException(sprintf('%s values must be positive integers.', $prefix));
            }

            $key = $prefix . '_' . $index;
            $placeholders[] = ':' . $key;
            $parameters[$key] = $value;
        }

        $conditions[] = sprintf('%s IN (%s)', $column, implode(', ', $placeholders));
    }

    /**
     * @param list<string> $conditions
     * @param array<string, int|string> $parameters
     * @param list<string> $allowed
     */
    private function addStringListFilter(
        array &$conditions,
        array &$parameters,
        string $column,
        string $prefix,
        mixed $values,
        array $allowed,
    ): void {
        if ($values === null) {
            return;
        }

        if (!is_array($values) || $values === []) {
            throw new InvalidArgumentException(sprintf('%s must be a non-empty list.', $prefix));
        }

        $placeholders = [];

        foreach (array_values($values) as $index => $value) {
            if (!is_string($value) || !in_array($value, $allowed, true)) {
                throw new InvalidArgumentException(sprintf('%s contains an unsupported value.', $prefix));
            }

            $key = $prefix . '_' . $index;
            $placeholders[] = ':' . $key;
            $parameters[$key] = $value;
        }

        $conditions[] = sprintf('%s IN (%s)', $column, implode(', ', $placeholders));
    }
}
