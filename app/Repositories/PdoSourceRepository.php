<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\Connection;
use App\Domain\Sources\ProcessingStatus;
use App\Domain\Sources\Source;
use App\Domain\Sources\SourceStatus;
use App\Domain\Sources\SourceType;
use App\Domain\Sources\SourceVersion;
use Closure;
use PDO;
use RuntimeException;
use Throwable;

final class PdoSourceRepository implements SourceRepositoryInterface
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function all(): array
    {
        $statement = $this->connection->pdo()->query($this->sourceSelect() . ' ORDER BY s.created_at DESC, s.id DESC');

        return array_map($this->hydrateSource(...), $statement->fetchAll());
    }

    public function findById(int $id): ?Source
    {
        $statement = $this->connection->pdo()->prepare($this->sourceSelect() . ' WHERE s.id = :id LIMIT 1');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        return is_array($row) ? $this->hydrateSource($row) : null;
    }

    public function lockById(int $id): ?Source
    {
        $statement = $this->connection->pdo()->prepare('SELECT id FROM sources WHERE id = :id FOR UPDATE');
        $statement->execute(['id' => $id]);

        return $statement->fetchColumn() === false ? null : $this->findById($id);
    }

    public function findVersionById(int $id): ?SourceVersion
    {
        $statement = $this->connection->pdo()->prepare(
            $this->versionSelect() . ' WHERE sv.id = :id LIMIT 1',
        );
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        return is_array($row) ? $this->hydrateVersion($row) : null;
    }

    public function versionsForSource(int $sourceId): array
    {
        $statement = $this->connection->pdo()->prepare(
            $this->versionSelect() . ' WHERE sv.source_id = :source_id ORDER BY sv.version_number DESC',
        );
        $statement->execute(['source_id' => $sourceId]);

        return array_map($this->hydrateVersion(...), $statement->fetchAll());
    }

    public function countEnabled(): int
    {
        $statement = $this->connection->pdo()->query(
            "SELECT COUNT(*) FROM sources WHERE status = 'enabled' AND deleted_at IS NULL",
        );

        return (int) $statement->fetchColumn();
    }

    public function createSource(string $name, SourceType $type): Source
    {
        $statement = $this->connection->pdo()->prepare(
            "INSERT INTO sources (name, source_type, status, created_at, updated_at)
             VALUES (:name, :source_type, 'enabled', UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))",
        );
        $statement->execute(['name' => $name, 'source_type' => $type->value]);
        $source = $this->findById((int) $this->connection->pdo()->lastInsertId());

        if (!$source instanceof Source) {
            throw new RuntimeException('The source could not be loaded after creation.');
        }

        return $source;
    }

    public function createUrlVersion(int $sourceId, string $url): SourceVersion
    {
        return $this->insertVersion($sourceId, [
            'original_filename' => null,
            'original_url' => $url,
            'stored_file_path' => null,
            'content_hash' => null,
            'file_hash' => null,
            'mime_type' => null,
            'file_size' => null,
        ]);
    }

    public function createFileVersion(
        int $sourceId,
        string $originalFilename,
        string $storedFilePath,
        string $contentHash,
        string $mimeType,
        int $fileSize,
    ): SourceVersion {
        return $this->insertVersion($sourceId, [
            'original_filename' => $originalFilename,
            'original_url' => null,
            'stored_file_path' => $storedFilePath,
            'content_hash' => null,
            'file_hash' => $contentHash,
            'mime_type' => $mimeType,
            'file_size' => $fileSize,
        ]);
    }

    public function disable(int $id): void
    {
        $this->updateStatus($id, SourceStatus::Disabled);
    }

    public function enable(int $id): void
    {
        $this->updateStatus($id, SourceStatus::Enabled);
    }

    public function softDelete(int $id): void
    {
        $statement = $this->connection->pdo()->prepare(
            "UPDATE sources SET status = 'disabled', deleted_at = UTC_TIMESTAMP(6), updated_at = UTC_TIMESTAMP(6)
             WHERE id = :id AND deleted_at IS NULL",
        );
        $statement->execute(['id' => $id]);
    }

    public function hasInFlightJobs(int $sourceId): bool
    {
        $statement = $this->connection->pdo()->prepare(
            "SELECT j.id
             FROM ingestion_jobs j
             INNER JOIN source_versions sv ON sv.id = j.source_version_id
             WHERE sv.source_id = :source_id AND j.status IN ('pending', 'processing')
             FOR UPDATE",
        );
        $statement->execute(['source_id' => $sourceId]);

        return $statement->fetchAll() !== [];
    }

    public function permanentlyDelete(int $id): void
    {
        $clearActive = $this->connection->pdo()->prepare(
            'UPDATE sources SET active_version_id = NULL WHERE id = :id',
        );
        $clearActive->execute(['id' => $id]);
        $delete = $this->connection->pdo()->prepare('DELETE FROM sources WHERE id = :id');
        $delete->execute(['id' => $id]);

        if ($delete->rowCount() !== 1) {
            throw new RuntimeException('The source could not be permanently deleted.');
        }
    }

    public function transaction(Closure $operation): mixed
    {
        $pdo = $this->connection->pdo();
        $pdo->beginTransaction();

        try {
            $result = $operation();
            $pdo->commit();

            return $result;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $exception;
        }
    }

    /** @param array<string, string|int|null> $values */
    private function insertVersion(int $sourceId, array $values): SourceVersion
    {
        $pdo = $this->connection->pdo();
        $lock = $pdo->prepare('SELECT id FROM sources WHERE id = :id FOR UPDATE');
        $lock->execute(['id' => $sourceId]);

        if ($lock->fetchColumn() === false) {
            throw new RuntimeException('Cannot create a version for a missing source.');
        }

        $numberStatement = $pdo->prepare(
            'SELECT COALESCE(MAX(version_number), 0) + 1 FROM source_versions WHERE source_id = :source_id',
        );
        $numberStatement->execute(['source_id' => $sourceId]);
        $versionNumber = (int) $numberStatement->fetchColumn();
        $statement = $pdo->prepare(
            "INSERT INTO source_versions (
                source_id, version_number, original_filename, original_url, stored_file_path,
                content_hash, file_hash, mime_type, file_size, processing_status, created_at
             ) VALUES (
                :source_id, :version_number, :original_filename, :original_url, :stored_file_path,
                :content_hash, :file_hash, :mime_type, :file_size, 'pending', UTC_TIMESTAMP(6)
             )",
        );
        $statement->execute([
            'source_id' => $sourceId,
            'version_number' => $versionNumber,
            ...$values,
        ]);
        $version = $this->findVersionById((int) $pdo->lastInsertId());

        if (!$version instanceof SourceVersion) {
            throw new RuntimeException('The source version could not be loaded after creation.');
        }

        return $version;
    }

    private function updateStatus(int $id, SourceStatus $status): void
    {
        $statement = $this->connection->pdo()->prepare(
            'UPDATE sources SET status = :status, updated_at = UTC_TIMESTAMP(6)
             WHERE id = :id AND deleted_at IS NULL',
        );
        $statement->execute(['id' => $id, 'status' => $status->value]);
    }

    private function sourceSelect(): string
    {
        return <<<'SQL'
            SELECT s.id, s.name, s.source_type, s.status, s.active_version_id,
                   s.created_at, s.updated_at, s.deleted_at,
                   (SELECT COUNT(*) FROM source_versions sv WHERE sv.source_id = s.id) AS version_count,
                   (SELECT sv2.processing_status FROM source_versions sv2
                    WHERE sv2.source_id = s.id ORDER BY sv2.version_number DESC LIMIT 1) AS latest_processing_status
            FROM sources s
            SQL;
    }

    private function versionSelect(): string
    {
        return <<<'SQL'
            SELECT sv.id, sv.source_id, sv.version_number, sv.original_filename, sv.original_url,
                   sv.stored_file_path, sv.content_hash, sv.file_hash, sv.mime_type, sv.file_size,
                   sv.processing_status, sv.error_message, sv.extracted_text, sv.metadata_json,
                   sv.created_at, sv.processed_at,
                   sv.activated_at, s.source_type,
                   (SELECT COUNT(*) FROM source_chunks sc WHERE sc.source_version_id = sv.id) AS chunk_count
            FROM source_versions sv
            INNER JOIN sources s ON s.id = sv.source_id
            SQL;
    }

    /** @param array<string, mixed> $row */
    private function hydrateSource(array $row): Source
    {
        return new Source(
            (int) $row['id'],
            (string) $row['name'],
            SourceType::from((string) $row['source_type']),
            SourceStatus::from((string) $row['status']),
            isset($row['active_version_id']) ? (int) $row['active_version_id'] : null,
            (string) $row['created_at'],
            (string) $row['updated_at'],
            isset($row['deleted_at']) ? (string) $row['deleted_at'] : null,
            (int) $row['version_count'],
            isset($row['latest_processing_status'])
                ? ProcessingStatus::from((string) $row['latest_processing_status'])
                : null,
        );
    }

    /** @param array<string, mixed> $row */
    private function hydrateVersion(array $row): SourceVersion
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
            isset($row['source_type']) ? SourceType::from((string) $row['source_type']) : null,
            isset($row['chunk_count']) ? (int) $row['chunk_count'] : 0,
            isset($row['extracted_text']) ? (string) $row['extracted_text'] : null,
            isset($row['metadata_json'])
                ? (json_decode((string) $row['metadata_json'], true, flags: JSON_THROW_ON_ERROR) ?: [])
                : [],
        );
    }
}
