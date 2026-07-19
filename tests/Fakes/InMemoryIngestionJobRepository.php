<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Domain\Ingestion\IngestionJob;
use App\Domain\Ingestion\JobStatus;
use App\Repositories\IngestionJobRepositoryInterface;

final class InMemoryIngestionJobRepository implements IngestionJobRepositoryInterface
{
    /** @var array<int, IngestionJob> */
    private array $jobs = [];

    public int $claimCalls = 0;

    public ?int $lastRetryDelay = null;

    public ?string $lastPersistedError = null;

    public ?int $lastRecoveryTimeout = null;

    /** @var array{completed: int, retried: int, failed: int} */
    public array $recoveryResult = ['completed' => 0, 'retried' => 0, 'failed' => 0];

    public function enqueue(int $sourceVersionId, int $maxAttempts, int $priority = 0): IngestionJob
    {
        $id = count($this->jobs) + 1;
        $job = $this->makeJob($id, $sourceVersionId, JobStatus::Pending, 0, $maxAttempts, $priority);
        $this->jobs[$id] = $job;

        return $job;
    }

    public function claim(string $workerId): ?IngestionJob
    {
        $this->claimCalls++;

        foreach ($this->jobs as $id => $job) {
            if ($job->status !== JobStatus::Pending) {
                continue;
            }

            $claimed = $this->copy(
                $job,
                status: JobStatus::Processing,
                attempts: $job->attempts + 1,
                reservedBy: $workerId,
            );
            $this->jobs[$id] = $claimed;

            return $claimed;
        }

        return null;
    }

    public function complete(int $jobId, string $workerId): void
    {
        $this->jobs[$jobId] = $this->copy($this->jobs[$jobId], status: JobStatus::Completed, reservedBy: null);
    }

    public function releaseForRetry(int $jobId, string $workerId, string $error, int $delaySeconds): void
    {
        $this->lastRetryDelay = $delaySeconds;
        $this->lastPersistedError = $error;
        $this->jobs[$jobId] = $this->copy(
            $this->jobs[$jobId],
            status: JobStatus::Pending,
            reservedBy: null,
            lastError: $error,
        );
    }

    public function fail(int $jobId, string $workerId, string $error): void
    {
        $this->lastPersistedError = $error;
        $this->jobs[$jobId] = $this->copy(
            $this->jobs[$jobId],
            status: JobStatus::Failed,
            reservedBy: null,
            lastError: $error,
        );
    }

    public function recoverAbandoned(int $timeoutSeconds): array
    {
        $this->lastRecoveryTimeout = $timeoutSeconds;

        return $this->recoveryResult;
    }

    public function recent(int $limit): array
    {
        return array_slice(array_values($this->jobs), 0, $limit);
    }

    public function forSource(int $sourceId): array
    {
        return array_values(array_filter(
            $this->jobs,
            static fn (IngestionJob $job): bool => $job->sourceId === $sourceId,
        ));
    }

    public function counts(): array
    {
        $counts = ['pending' => 0, 'processing' => 0, 'completed' => 0, 'failed' => 0];

        foreach ($this->jobs as $job) {
            $counts[$job->status->value]++;
        }

        return $counts;
    }

    public function job(int $id): IngestionJob
    {
        return $this->jobs[$id];
    }

    private function makeJob(
        int $id,
        int $sourceVersionId,
        JobStatus $status,
        int $attempts,
        int $maxAttempts,
        int $priority,
    ): IngestionJob {
        return new IngestionJob(
            $id,
            $sourceVersionId,
            'ingest_source_version',
            $status,
            $priority,
            $attempts,
            $maxAttempts,
            '2026-07-17 00:00:00.000000',
            null,
            null,
            null,
            '2026-07-17 00:00:00.000000',
            '2026-07-17 00:00:00.000000',
            null,
            null,
            1,
            1,
            'Test source',
        );
    }

    private function copy(
        IngestionJob $job,
        ?JobStatus $status = null,
        ?int $attempts = null,
        ?string $reservedBy = '__unchanged__',
        ?string $lastError = '__unchanged__',
    ): IngestionJob {
        return new IngestionJob(
            $job->id,
            $job->sourceVersionId,
            $job->jobType,
            $status ?? $job->status,
            $job->priority,
            $attempts ?? $job->attempts,
            $job->maxAttempts,
            $job->availableAt,
            $reservedBy === '__unchanged__' ? $job->reservedAt : ($reservedBy === null ? null : $job->reservedAt),
            $reservedBy === '__unchanged__' ? $job->reservedBy : $reservedBy,
            $lastError === '__unchanged__' ? $job->lastError : $lastError,
            $job->createdAt,
            $job->updatedAt,
            $status === JobStatus::Completed ? '2026-07-17 00:01:00.000000' : $job->completedAt,
            $status === JobStatus::Failed ? '2026-07-17 00:01:00.000000' : $job->failedAt,
            $job->sourceId,
            $job->versionNumber,
            $job->sourceName,
        );
    }
}
