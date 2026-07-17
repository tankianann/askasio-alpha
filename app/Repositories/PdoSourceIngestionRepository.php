<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\Connection;
use App\Domain\Ingestion\Chunk;
use App\Domain\Ingestion\ExtractedDocument;
use App\Domain\Sources\ProcessingStatus;
use App\Domain\Sources\SourceType;
use App\Domain\Sources\SourceVersion;
use RuntimeException;
use Throwable;

final class PdoSourceIngestionRepository implements SourceIngestionRepositoryInterface
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function findVersion(int $id): ?SourceVersion
    {
        $statement = $this->connection->pdo()->prepare(
            'SELECT sv.id, sv.source_id, sv.version_number, sv.original_filename, sv.original_url,
                    sv.stored_file_path, sv.content_hash, sv.file_hash, sv.mime_type, sv.file_size,
                    sv.processing_status, sv.error_message, sv.created_at, sv.processed_at,
                    sv.activated_at, s.source_type,
                    (SELECT COUNT(*) FROM source_chunks sc WHERE sc.source_version_id = sv.id) AS chunk_count
             FROM source_versions sv
             INNER JOIN sources s ON s.id = sv.source_id
             WHERE sv.id = :id LIMIT 1',
        );
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function storeAndActivate(SourceVersion $version, ExtractedDocument $document, array $chunks): bool
    {
        if ($chunks === []) {
            throw new RuntimeException('An extracted source cannot be activated without chunks.');
        }

        $pdo = $this->connection->pdo();
        $pdo->beginTransaction();

        try {
            $lock = $pdo->prepare(
                'SELECT s.active_version_id, active.content_hash AS active_content_hash
                 FROM sources s
                 LEFT JOIN source_versions active ON active.id = s.active_version_id
                 WHERE s.id = :source_id FOR UPDATE',
            );
            $lock->execute(['source_id' => $version->sourceId]);
            $source = $lock->fetch();

            if (!is_array($source)) {
                throw new RuntimeException('The source no longer exists.');
            }

            $activeVersionId = isset($source['active_version_id']) ? (int) $source['active_version_id'] : null;
            $contentHash = $document->contentHash();
            $documentMetadata = json_encode($document->metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $delete = $pdo->prepare('DELETE FROM source_chunks WHERE source_version_id = :version_id');
            $delete->execute(['version_id' => $version->id]);

            if ($activeVersionId !== null
                && $activeVersionId !== $version->id
                && hash_equals((string) ($source['active_content_hash'] ?? ''), $contentHash)) {
                $unchanged = $pdo->prepare(
                    "UPDATE source_versions
                     SET content_hash = :content_hash, extracted_text = :extracted_text,
                         metadata_json = :metadata_json, processing_status = 'inactive',
                         error_message = NULL, processed_at = UTC_TIMESTAMP(6), activated_at = NULL
                     WHERE id = :id",
                );
                $unchanged->execute([
                    'content_hash' => $contentHash,
                    'extracted_text' => $document->content,
                    'metadata_json' => $documentMetadata,
                    'id' => $version->id,
                ]);
                $pdo->commit();

                return false;
            }

            $insert = $pdo->prepare(
                'INSERT INTO source_chunks (
                    source_version_id, chunk_number, content, token_count, embedding, metadata_json, created_at
                 ) VALUES (
                    :source_version_id, :chunk_number, :content, :token_count, NULL, :metadata_json, UTC_TIMESTAMP(6)
                 )',
            );

            foreach ($chunks as $chunk) {
                if (!$chunk instanceof Chunk) {
                    throw new RuntimeException('The chunker returned an invalid chunk.');
                }

                $insert->execute([
                    'source_version_id' => $version->id,
                    'chunk_number' => $chunk->number,
                    'content' => $chunk->content,
                    'token_count' => $chunk->tokenCount,
                    'metadata_json' => json_encode(
                        $chunk->metadata,
                        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                    ),
                ]);
            }

            if ($activeVersionId !== null && $activeVersionId !== $version->id) {
                $deactivate = $pdo->prepare(
                    "UPDATE source_versions SET processing_status = 'inactive'
                     WHERE id = :id AND processing_status = 'ready'",
                );
                $deactivate->execute(['id' => $activeVersionId]);
            }

            $activate = $pdo->prepare(
                "UPDATE source_versions
                 SET content_hash = :content_hash, extracted_text = :extracted_text,
                     metadata_json = :metadata_json, processing_status = 'ready',
                     error_message = NULL, processed_at = UTC_TIMESTAMP(6), activated_at = UTC_TIMESTAMP(6)
                 WHERE id = :id",
            );
            $activate->execute([
                'content_hash' => $contentHash,
                'extracted_text' => $document->content,
                'metadata_json' => $documentMetadata,
                'id' => $version->id,
            ]);

            if ($activate->rowCount() !== 1) {
                throw new RuntimeException('The source version could not be activated.');
            }

            $sourceUpdate = $pdo->prepare(
                'UPDATE sources SET active_version_id = :version_id, updated_at = UTC_TIMESTAMP(6) WHERE id = :source_id',
            );
            $sourceUpdate->execute(['version_id' => $version->id, 'source_id' => $version->sourceId]);
            $pdo->commit();

            return true;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $exception;
        }
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): SourceVersion
    {
        return new SourceVersion(
            (int) $row['id'],
            (int) $row['source_id'],
            (int) $row['version_number'],
            isset($row['original_filename']) ? (string) $row['original_filename'] : null,
            isset($row['original_url']) ? (string) $row['original_url'] : null,
            isset($row['stored_file_path']) ? (string) $row['stored_file_path'] : null,
            isset($row['content_hash']) ? (string) $row['content_hash'] : null,
            isset($row['mime_type']) ? (string) $row['mime_type'] : null,
            isset($row['file_size']) ? (int) $row['file_size'] : null,
            ProcessingStatus::from((string) $row['processing_status']),
            isset($row['error_message']) ? (string) $row['error_message'] : null,
            (string) $row['created_at'],
            isset($row['processed_at']) ? (string) $row['processed_at'] : null,
            isset($row['activated_at']) ? (string) $row['activated_at'] : null,
            isset($row['file_hash']) ? (string) $row['file_hash'] : null,
            SourceType::from((string) $row['source_type']),
            (int) $row['chunk_count'],
        );
    }
}
