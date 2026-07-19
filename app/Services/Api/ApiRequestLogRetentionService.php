<?php

declare(strict_types=1);

namespace App\Services\Api;

use App\Maintenance\MaintenanceLockInterface;
use App\Repositories\ApiRequestLogRepositoryInterface;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Psr\Log\LoggerInterface;
use Throwable;

final class ApiRequestLogRetentionService
{
    private readonly Closure $clock;

    public function __construct(
        private readonly ApiRequestLogRepositoryInterface $logs,
        private readonly ApiRequestLogRetentionPolicy $policy,
        private readonly MaintenanceLockInterface $lock,
        private readonly LoggerInterface $logger,
        private readonly int $batchSize,
        private readonly string $lockName,
        ?Closure $clock = null,
    ) {
        if ($batchSize < 1 || $batchSize > 10000) {
            throw new \InvalidArgumentException('API request log purge batch size must be between 1 and 10000.');
        }

        $this->clock = $clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    public function run(): ApiRequestLogRetentionResult
    {
        if ($this->policy->keepsForever()) {
            $this->logger->info('API request log retention skipped because records are configured to be kept forever.');

            return new ApiRequestLogRetentionResult(false, false, 0, 0, null);
        }

        $cutoff = $this->policy->cutoff(($this->clock)());

        if (!$cutoff instanceof DateTimeImmutable) {
            throw new \LogicException('An enabled retention policy must provide a cutoff.');
        }

        $cutoffUtc = $cutoff->format('Y-m-d H:i:s.u');

        if (!$this->lock->acquire($this->lockName)) {
            $this->logger->notice('API request log retention skipped because another purge is running.', [
                'cutoff_utc' => $cutoffUtc,
            ]);

            return new ApiRequestLogRetentionResult(true, false, 0, 0, $cutoffUtc);
        }

        $deleted = 0;
        $batches = 0;
        $purgeFailure = null;

        try {
            $this->logger->info('API request log retention started.', [
                'retention_days' => $this->policy->days,
                'cutoff_utc' => $cutoffUtc,
                'batch_size' => $this->batchSize,
            ]);

            do {
                $batchDeleted = $this->logs->pruneOlderThan($cutoffUtc, $this->batchSize);
                $deleted += $batchDeleted;

                if ($batchDeleted > 0) {
                    ++$batches;
                    $this->logger->debug('API request log retention batch completed.', [
                        'deleted_records' => $batchDeleted,
                        'total_deleted_records' => $deleted,
                    ]);
                }
            } while ($batchDeleted === $this->batchSize);

            $this->logger->info('API request log retention completed.', [
                'retention_days' => $this->policy->days,
                'cutoff_utc' => $cutoffUtc,
                'deleted_records' => $deleted,
                'batches' => $batches,
            ]);

            return new ApiRequestLogRetentionResult(true, true, $deleted, $batches, $cutoffUtc);
        } catch (Throwable $exception) {
            $purgeFailure = $exception;

            throw $exception;
        } finally {
            try {
                $this->lock->release($this->lockName);
            } catch (Throwable $releaseFailure) {
                $this->logger->critical('The API request log retention lock could not be released.', [
                    'exception' => $releaseFailure,
                ]);

                if (!$purgeFailure instanceof Throwable) {
                    throw $releaseFailure;
                }
            }
        }
    }
}
