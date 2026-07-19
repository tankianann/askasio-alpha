<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\Connection;
use App\Domain\Ingestion\IngestionJob;
use App\Domain\Ingestion\IngestionJobListQuery;
use App\Domain\Ingestion\IngestionJobSourceOption;
use App\Domain\Ingestion\JobStatus;
use PDO;
use RuntimeException;
use Throwable;
use App\Support\Pagination\PaginatedResult;

final class PdoIngestionJobRepository implements IngestionJobRepositoryInterface
{
    private readonly IngestionJobListSqlQueryBuilder $listQueries;

    public function __construct(
        private readonly Connection $connection,
        ?IngestionJobListSqlQueryBuilder $listQueries = null,
    ) {
        $this->listQueries = $listQueries ?? new IngestionJobListSqlQueryBuilder();
    }

    public function enqueue(int $sourceVersionId, int $maxAttempts, int $priority = 0): IngestionJob
    {
        $statement = $this->connection->pdo()->prepare(
            "INSERT INTO ingestion_jobs (
                source_version_id, job_type, status, priority, attempts, max_attempts,
                available_at, created_at, updated_at
             ) VALUES (
                :source_version_id, 'ingest_source_version', 'pending', :priority, 0, :max_attempts,
                UTC_TIMESTAMP(6), UTC_TIMESTAMP(6), UTC_TIMESTAMP(6)
             )",
        );
        $statement->execute([
            'source_version_id' => $sourceVersionId,
            'priority' => $priority,
            'max_attempts' => $maxAttempts,
        ]);
        $job = $this->findById((int) $this->connection->pdo()->lastInsertId());

        if (!$job instanceof IngestionJob) {
            throw new RuntimeException('The ingestion job could not be loaded after creation.');
        }

        return $job;
    }

    public function claim(string $workerId): ?IngestionJob
    {
        $pdo = $this->connection->pdo();
        $pdo->beginTransaction();

        try {
            $statement = $pdo->query(
                "SELECT id, source_version_id FROM ingestion_jobs
                 WHERE status = 'pending' AND available_at <= UTC_TIMESTAMP(6)
                 ORDER BY priority DESC, available_at ASC, id ASC
                 LIMIT 1 FOR UPDATE SKIP LOCKED",
            );
            $row = $statement->fetch();

            if (!is_array($row)) {
                $pdo->commit();

                return null;
            }

            $update = $pdo->prepare(
                "UPDATE ingestion_jobs
                 SET status = 'processing', attempts = attempts + 1,
                     reserved_at = UTC_TIMESTAMP(6), reserved_by = :worker_id,
                     updated_at = UTC_TIMESTAMP(6)
                 WHERE id = :id AND status = 'pending'",
            );
            $update->execute(['worker_id' => $workerId, 'id' => $row['id']]);

            if ($update->rowCount() !== 1) {
                throw new RuntimeException('The ingestion job could not be claimed atomically.');
            }

            $version = $pdo->prepare(
                "UPDATE source_versions
                 SET processing_status = 'processing', error_message = NULL
                 WHERE id = :id",
            );
            $version->execute(['id' => $row['source_version_id']]);
            $pdo->commit();

            return $this->findById((int) $row['id']);
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $exception;
        }
    }

    public function complete(int $jobId, string $workerId): void
    {
        $statement = $this->connection->pdo()->prepare(
            "UPDATE ingestion_jobs
             SET status = 'completed', reserved_at = NULL, reserved_by = NULL,
                 completed_at = UTC_TIMESTAMP(6), updated_at = UTC_TIMESTAMP(6), last_error = NULL
             WHERE id = :id AND status = 'processing' AND reserved_by = :worker_id",
        );
        $statement->execute(['id' => $jobId, 'worker_id' => $workerId]);
        $this->assertUpdated($statement->rowCount(), 'complete');
    }

    public function releaseForRetry(int $jobId, string $workerId, string $error, int $delaySeconds): void
    {
        $pdo = $this->connection->pdo();
        $pdo->beginTransaction();

        try {
            $statement = $pdo->prepare(
                "UPDATE ingestion_jobs
                 SET status = 'pending', reserved_at = NULL, reserved_by = NULL,
                     available_at = DATE_ADD(UTC_TIMESTAMP(6), INTERVAL :delay SECOND),
                     last_error = :error, updated_at = UTC_TIMESTAMP(6)
                 WHERE id = :id AND status = 'processing' AND reserved_by = :worker_id",
            );
            $statement->execute([
                'delay' => $delaySeconds,
                'error' => $error,
                'id' => $jobId,
                'worker_id' => $workerId,
            ]);
            $this->assertUpdated($statement->rowCount(), 'release');
            $this->setVersionStatusForJob($jobId, 'pending', $error);
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $exception;
        }
    }

    public function fail(int $jobId, string $workerId, string $error): void
    {
        $pdo = $this->connection->pdo();
        $pdo->beginTransaction();

        try {
            $statement = $pdo->prepare(
                "UPDATE ingestion_jobs
                 SET status = 'failed', reserved_at = NULL, reserved_by = NULL,
                     failed_at = UTC_TIMESTAMP(6), last_error = :error, updated_at = UTC_TIMESTAMP(6)
                 WHERE id = :id AND status = 'processing' AND reserved_by = :worker_id",
            );
            $statement->execute(['error' => $error, 'id' => $jobId, 'worker_id' => $workerId]);
            $this->assertUpdated($statement->rowCount(), 'fail');
            $this->setVersionStatusForJob($jobId, 'failed', $error);
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $exception;
        }
    }

    public function recoverAbandoned(int $timeoutSeconds): array
    {
        $cutoff = gmdate('Y-m-d H:i:s.u', time() - $timeoutSeconds);
        $pdo = $this->connection->pdo();
        $pdo->beginTransaction();
        $completed = 0;
        $retried = 0;
        $failed = 0;

        try {
            $statement = $pdo->prepare(
                "SELECT j.id, j.attempts, j.max_attempts, sv.processing_status
                 FROM ingestion_jobs j
                 INNER JOIN source_versions sv ON sv.id = j.source_version_id
                 WHERE j.status = 'processing' AND j.reserved_at < :cutoff
                 ORDER BY j.reserved_at ASC LIMIT 100 FOR UPDATE SKIP LOCKED",
            );
            $statement->execute(['cutoff' => $cutoff]);

            foreach ($statement->fetchAll() as $row) {
                $jobId = (int) $row['id'];
                $versionStatus = (string) $row['processing_status'];

                if (in_array($versionStatus, ['ready', 'inactive'], true)) {
                    $update = $pdo->prepare(
                        "UPDATE ingestion_jobs
                         SET status = 'completed', reserved_at = NULL, reserved_by = NULL,
                             completed_at = UTC_TIMESTAMP(6), updated_at = UTC_TIMESTAMP(6), last_error = NULL
                         WHERE id = :id AND status = 'processing'",
                    );
                    $update->execute(['id' => $jobId]);
                    $completed++;

                    continue;
                }

                if ($versionStatus === 'failed') {
                    $update = $pdo->prepare(
                        "UPDATE ingestion_jobs
                         SET status = 'failed', reserved_at = NULL, reserved_by = NULL,
                             failed_at = UTC_TIMESTAMP(6), updated_at = UTC_TIMESTAMP(6),
                             last_error = COALESCE(last_error, 'Source processing failed before the worker reservation expired.')
                         WHERE id = :id AND status = 'processing'",
                    );
                    $update->execute(['id' => $jobId]);
                    $failed++;

                    continue;
                }

                if ((int) $row['attempts'] >= (int) $row['max_attempts']) {
                    $update = $pdo->prepare(
                        "UPDATE ingestion_jobs
                         SET status = 'failed', reserved_at = NULL, reserved_by = NULL,
                             failed_at = UTC_TIMESTAMP(6), updated_at = UTC_TIMESTAMP(6),
                             last_error = 'Worker reservation expired after the final attempt.'
                         WHERE id = :id",
                    );
                    $update->execute(['id' => $jobId]);
                    $this->setVersionStatusForJob($jobId, 'failed', 'Worker reservation expired after the final attempt.');
                    $failed++;
                } else {
                    $update = $pdo->prepare(
                        "UPDATE ingestion_jobs
                         SET status = 'pending', reserved_at = NULL, reserved_by = NULL,
                             available_at = UTC_TIMESTAMP(6), updated_at = UTC_TIMESTAMP(6),
                             last_error = 'Recovered after an abandoned worker reservation.'
                         WHERE id = :id",
                    );
                    $update->execute(['id' => $jobId]);
                    $this->setVersionStatusForJob($jobId, 'pending', 'Recovered after an abandoned worker reservation.');
                    $retried++;
                }
            }

            $pdo->commit();

            return ['completed' => $completed, 'retried' => $retried, 'failed' => $failed];
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $exception;
        }
    }

    public function recent(int $limit): array
    {
        $limit = max(1, min($limit, 200));
        $statement = $this->connection->pdo()->query(
            $this->jobSelect() . ' ORDER BY j.created_at DESC, j.id DESC LIMIT ' . $limit,
        );

        return array_map($this->hydrate(...), $statement->fetchAll());
    }

    public function paginate(IngestionJobListQuery $query): PaginatedResult
    {
        $pdo = $this->connection->pdo();
        $where = $this->listQueries->where($query);
        $count = $pdo->prepare('SELECT COUNT(*)' . "\n" . $this->jobFrom() . $where['sql']);
        $count->execute($where['parameters']);
        $total = (int) $count->fetchColumn();
        $pageRequest = $query->pagination->clampToTotal($total);
        $statement = $pdo->prepare(
            $this->jobSelect()
            . $where['sql']
            . $this->listQueries->orderBy($query)
            . ' LIMIT ' . $pageRequest->perPage
            . ' OFFSET ' . $pageRequest->offset(),
        );
        $statement->execute($where['parameters']);

        return new PaginatedResult(
            array_map($this->hydrate(...), $statement->fetchAll()),
            $total,
            $pageRequest,
        );
    }

    public function sourceOptions(): array
    {
        $rows = $this->connection->pdo()->query(
            'SELECT DISTINCT s.id, s.name' . "\n"
            . $this->jobFrom()
            . ' ORDER BY s.name ASC, s.id ASC',
        )->fetchAll();

        return array_map(
            static fn (array $row): IngestionJobSourceOption => new IngestionJobSourceOption(
                (int) $row['id'],
                (string) $row['name'],
            ),
            $rows,
        );
    }

    public function forSource(int $sourceId): array
    {
        $statement = $this->connection->pdo()->prepare(
            $this->jobSelect() . ' WHERE sv.source_id = :source_id ORDER BY j.created_at DESC, j.id DESC',
        );
        $statement->execute(['source_id' => $sourceId]);

        return array_map($this->hydrate(...), $statement->fetchAll());
    }

    public function counts(): array
    {
        $counts = ['pending' => 0, 'processing' => 0, 'completed' => 0, 'failed' => 0];
        $rows = $this->connection->pdo()->query(
            'SELECT status, COUNT(*) AS aggregate FROM ingestion_jobs GROUP BY status',
        )->fetchAll();

        foreach ($rows as $row) {
            $status = (string) $row['status'];

            if (array_key_exists($status, $counts)) {
                $counts[$status] = (int) $row['aggregate'];
            }
        }

        return $counts;
    }

    private function findById(int $id): ?IngestionJob
    {
        $statement = $this->connection->pdo()->prepare($this->jobSelect() . ' WHERE j.id = :id LIMIT 1');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        return is_array($row) ? $this->hydrate($row) : null;
    }

    private function setVersionStatusForJob(int $jobId, string $status, string $error): void
    {
        $statement = $this->connection->pdo()->prepare(
            'UPDATE source_versions sv
             INNER JOIN ingestion_jobs j ON j.source_version_id = sv.id
             SET sv.processing_status = :status, sv.error_message = :error
             WHERE j.id = :job_id',
        );
        $statement->execute(['status' => $status, 'error' => $error, 'job_id' => $jobId]);
    }

    private function assertUpdated(int $count, string $operation): void
    {
        if ($count !== 1) {
            throw new RuntimeException(sprintf('Unable to %s the claimed ingestion job.', $operation));
        }
    }

    private function jobSelect(): string
    {
        return <<<'SQL'
            SELECT j.id, j.source_version_id, j.job_type, j.status, j.priority,
                   j.attempts, j.max_attempts, j.available_at, j.reserved_at, j.reserved_by,
                   j.last_error, j.created_at, j.updated_at, j.completed_at, j.failed_at,
                   sv.source_id, sv.version_number, s.name AS source_name
            SQL . "\n" . $this->jobFrom();
    }

    private function jobFrom(): string
    {
        return <<<'SQL'
            FROM ingestion_jobs j
            INNER JOIN source_versions sv ON sv.id = j.source_version_id
            INNER JOIN sources s ON s.id = sv.source_id
            SQL;
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): IngestionJob
    {
        return new IngestionJob(
            (int) $row['id'],
            (int) $row['source_version_id'],
            (string) $row['job_type'],
            JobStatus::from((string) $row['status']),
            (int) $row['priority'],
            (int) $row['attempts'],
            (int) $row['max_attempts'],
            (string) $row['available_at'],
            isset($row['reserved_at']) ? (string) $row['reserved_at'] : null,
            isset($row['reserved_by']) ? (string) $row['reserved_by'] : null,
            isset($row['last_error']) ? (string) $row['last_error'] : null,
            (string) $row['created_at'],
            (string) $row['updated_at'],
            isset($row['completed_at']) ? (string) $row['completed_at'] : null,
            isset($row['failed_at']) ? (string) $row['failed_at'] : null,
            (int) $row['source_id'],
            (int) $row['version_number'],
            (string) $row['source_name'],
        );
    }
}
