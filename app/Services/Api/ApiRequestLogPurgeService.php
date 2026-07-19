<?php

declare(strict_types=1);

namespace App\Services\Api;

use App\Domain\Api\ApiRequestLogPurgeCriteria;
use App\Domain\Api\ApiRequestLogPurgeResult;
use App\Domain\Api\ApiRequestLogPurgeSnapshot;
use App\Maintenance\MaintenanceLockInterface;
use App\Repositories\ApiRequestLogRepositoryInterface;
use Psr\Log\LoggerInterface;
use Throwable;

final class ApiRequestLogPurgeService
{
    public function __construct(
        private readonly ApiRequestLogRepositoryInterface $logs,
        private readonly MaintenanceLockInterface $lock,
        private readonly LoggerInterface $logger,
        private readonly int $batchSize,
        private readonly string $lockName,
    ) {
        if ($batchSize < 1 || $batchSize > 10000) {
            throw new \InvalidArgumentException('API request log purge batch size must be between 1 and 10000.');
        }
    }

    public function preview(ApiRequestLogPurgeCriteria $criteria): ApiRequestLogPurgeSnapshot
    {
        return $this->logs->purgeSnapshot($criteria);
    }

    public function purge(ApiRequestLogPurgeSnapshot $snapshot, int $administratorId): ApiRequestLogPurgeResult
    {
        if (!$this->lock->acquire($this->lockName)) {
            $this->logger->notice('Manual API request log purge skipped because another purge is running.', [
                'admin_user_id' => $administratorId,
                'scope' => $snapshot->criteria->scope->value,
            ]);

            return new ApiRequestLogPurgeResult(false, 0, 0);
        }

        $deleted = 0;
        $batches = 0;
        $purgeFailure = null;

        try {
            $this->logger->info('Manual API request log purge started.', [
                'admin_user_id' => $administratorId,
                'scope' => $snapshot->criteria->scope->value,
                'reviewed_record_count' => $snapshot->recordCount,
                'snapshot_maximum_id' => $snapshot->maximumId,
                'batch_size' => $this->batchSize,
            ]);

            do {
                $batchDeleted = $this->logs->purgeSnapshotBatch($snapshot, $this->batchSize);
                $deleted += $batchDeleted;

                if ($batchDeleted > 0) {
                    ++$batches;
                }
            } while ($batchDeleted === $this->batchSize);

            $this->logger->info('Manual API request log purge completed.', [
                'admin_user_id' => $administratorId,
                'scope' => $snapshot->criteria->scope->value,
                'reviewed_record_count' => $snapshot->recordCount,
                'deleted_records' => $deleted,
                'batches' => $batches,
            ]);

            return new ApiRequestLogPurgeResult(true, $deleted, $batches);
        } catch (Throwable $exception) {
            $purgeFailure = $exception;
            throw $exception;
        } finally {
            try {
                $this->lock->release($this->lockName);
            } catch (Throwable $releaseFailure) {
                $this->logger->critical('The manual API request log purge lock could not be released.', [
                    'exception' => $releaseFailure,
                ]);

                if (!$purgeFailure instanceof Throwable) {
                    throw $releaseFailure;
                }
            }
        }
    }
}
