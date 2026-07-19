<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Domain\Ingestion\IngestionJob;

interface IngestionJobRepositoryInterface
{
    public function enqueue(int $sourceVersionId, int $maxAttempts, int $priority = 0): IngestionJob;

    public function claim(string $workerId): ?IngestionJob;

    public function complete(int $jobId, string $workerId): void;

    public function releaseForRetry(int $jobId, string $workerId, string $error, int $delaySeconds): void;

    public function fail(int $jobId, string $workerId, string $error): void;

    /** @return array{completed: int, retried: int, failed: int} */
    public function recoverAbandoned(int $timeoutSeconds): array;

    /** @return list<IngestionJob> */
    public function recent(int $limit): array;

    /** @return list<IngestionJob> */
    public function forSource(int $sourceId): array;

    /** @return array{pending: int, processing: int, completed: int, failed: int} */
    public function counts(): array;
}
