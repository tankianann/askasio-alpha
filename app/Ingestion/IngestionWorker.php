<?php

declare(strict_types=1);

namespace App\Ingestion;

use App\Domain\Ingestion\JobStatus;
use App\Services\Ingestion\IngestionQueue;
use Psr\Log\LoggerInterface;

final class IngestionWorker
{
    private bool $running = true;

    public function __construct(
        private readonly IngestionQueue $queue,
        private readonly IngestionProcessorInterface $processor,
        private readonly LoggerInterface $logger,
        private readonly string $workerId,
        private readonly int $pollSeconds,
    ) {
        if ($this->pollSeconds < 1 || $this->pollSeconds > 60) {
            throw new \InvalidArgumentException('Worker polling must be between 1 and 60 seconds.');
        }
    }

    public function isAvailable(): bool
    {
        return $this->processor->isAvailable();
    }

    public function runOnce(): bool
    {
        if (!$this->isAvailable()) {
            $this->logger->notice('Ingestion worker skipped claiming because the processing pipeline is unavailable.', [
                'worker_id' => $this->workerId,
            ]);

            return false;
        }

        $recovered = $this->queue->recoverAbandoned();

        if ($recovered['retried'] > 0 || $recovered['failed'] > 0) {
            $this->logger->warning('Recovered abandoned ingestion jobs.', [
                'worker_id' => $this->workerId,
                ...$recovered,
            ]);
        }

        $job = $this->queue->claim($this->workerId);

        if ($job === null) {
            return false;
        }

        $startedAt = hrtime(true);
        $this->logger->info('Ingestion job claimed.', [
            'worker_id' => $this->workerId,
            'job_id' => $job->id,
            'source_version_id' => $job->sourceVersionId,
            'attempt' => $job->attempts,
            'max_attempts' => $job->maxAttempts,
        ]);

        try {
            $this->processor->process($job);
            $this->queue->complete($job, $this->workerId);
            $this->logger->info('Ingestion job completed.', [
                'worker_id' => $this->workerId,
                'job_id' => $job->id,
                'source_version_id' => $job->sourceVersionId,
                'duration_ms' => (int) ((hrtime(true) - $startedAt) / 1_000_000),
            ]);
        } catch (\Throwable $exception) {
            $status = $this->queue->retryOrFail($job, $this->workerId, $exception);
            $this->logger->error('Ingestion job processing failed.', [
                'worker_id' => $this->workerId,
                'job_id' => $job->id,
                'source_version_id' => $job->sourceVersionId,
                'attempt' => $job->attempts,
                'resulting_status' => $status->value,
                'duration_ms' => (int) ((hrtime(true) - $startedAt) / 1_000_000),
                'exception' => $exception,
            ]);
        }

        return true;
    }

    public function run(): void
    {
        $this->registerSignalHandlers();

        while ($this->running) {
            if (!$this->runOnce()) {
                sleep($this->pollSeconds);
            }
        }

        $this->logger->info('Ingestion worker stopped.', ['worker_id' => $this->workerId]);
    }

    public function stop(): void
    {
        $this->running = false;
    }

    private function registerSignalHandlers(): void
    {
        if (!function_exists('pcntl_async_signals') || !function_exists('pcntl_signal')) {
            return;
        }

        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, $this->stop(...));
        pcntl_signal(SIGINT, $this->stop(...));
    }
}
