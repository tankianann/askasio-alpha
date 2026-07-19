<?php

declare(strict_types=1);

namespace App\Services\Ingestion;

use App\Domain\Ingestion\IngestionJob;
use App\Domain\Ingestion\JobStatus;
use App\Domain\Ingestion\IngestionJobListQuery;
use App\Domain\Ingestion\IngestionJobSourceOption;
use App\Ingestion\IngestionException;
use App\Ingestion\PermanentIngestionException;
use App\Repositories\IngestionJobRepositoryInterface;
use InvalidArgumentException;
use Throwable;
use App\Support\Pagination\PaginatedResult;

final class IngestionQueue
{
    public function __construct(
        private readonly IngestionJobRepositoryInterface $jobs,
        private readonly int $defaultMaxAttempts,
        private readonly int $retryBaseSeconds,
        private readonly int $retryMaximumSeconds,
        private readonly int $abandonedTimeoutSeconds,
    ) {
        if ($this->defaultMaxAttempts < 1 || $this->defaultMaxAttempts > 100) {
            throw new InvalidArgumentException('Job attempts must be between 1 and 100.');
        }

        if ($this->retryBaseSeconds < 1 || $this->retryMaximumSeconds < $this->retryBaseSeconds) {
            throw new InvalidArgumentException('Job retry delays are invalid.');
        }

        if ($this->abandonedTimeoutSeconds < 60) {
            throw new InvalidArgumentException('The abandoned-job timeout must be at least 60 seconds.');
        }
    }

    public function enqueue(int $sourceVersionId, int $priority = 0): IngestionJob
    {
        return $this->jobs->enqueue($sourceVersionId, $this->defaultMaxAttempts, $priority);
    }

    public function claim(string $workerId): ?IngestionJob
    {
        return $this->jobs->claim($workerId);
    }

    public function complete(IngestionJob $job, string $workerId): void
    {
        $this->jobs->complete($job->id, $workerId);
    }

    public function retryOrFail(IngestionJob $job, string $workerId, Throwable $exception): JobStatus
    {
        $error = $this->safeError($job, $exception);

        if ($exception instanceof PermanentIngestionException || $job->attempts >= $job->maxAttempts) {
            $this->jobs->fail($job->id, $workerId, $error);

            return JobStatus::Failed;
        }

        $exponent = max(0, $job->attempts - 1);
        $delay = min($this->retryMaximumSeconds, $this->retryBaseSeconds * (2 ** $exponent));
        $this->jobs->releaseForRetry($job->id, $workerId, $error, (int) $delay);

        return JobStatus::Pending;
    }

    /** @return array{completed: int, retried: int, failed: int} */
    public function recoverAbandoned(): array
    {
        return $this->jobs->recoverAbandoned($this->abandonedTimeoutSeconds);
    }

    /** @return list<IngestionJob> */
    public function recent(int $limit = 100): array
    {
        return $this->jobs->recent($limit);
    }

    /** @return PaginatedResult<IngestionJob> */
    public function paginate(IngestionJobListQuery $query): PaginatedResult
    {
        return $this->jobs->paginate($query);
    }

    /** @return list<IngestionJobSourceOption> */
    public function sourceOptions(): array
    {
        return $this->jobs->sourceOptions();
    }

    /** @return list<IngestionJob> */
    public function forSource(int $sourceId): array
    {
        return $this->jobs->forSource($sourceId);
    }

    /** @return array{pending: int, processing: int, completed: int, failed: int} */
    public function counts(): array
    {
        return $this->jobs->counts();
    }

    private function safeError(IngestionJob $job, Throwable $exception): string
    {
        $message = $exception instanceof IngestionException
            ? $exception->getMessage()
            : sprintf('Unexpected ingestion error for job %d. Review the application log.', $job->id);
        $message = trim(preg_replace('/[\x00-\x1F\x7F]/', ' ', $message) ?? 'Ingestion failed.');

        $patterns = [
            '/(Bearer\s+)[^\s]+/i' => '$1[REDACTED]',
            '/\b(?:sk-[A-Za-z0-9_-]{8,}|rag_(?:live|test)_[A-Za-z0-9_-]{8,})\b/' => '[REDACTED]',
            '/((?:api[_-]?key|password|secret|token)\s*[=:]\s*)[^\s,;]+/i' => '$1[REDACTED]',
            '/([a-z][a-z0-9+.-]*:\/\/[^:\s\/]+:)[^@\s\/]+@/i' => '$1[REDACTED]@',
        ];
        $message = (string) preg_replace(array_keys($patterns), array_values($patterns), $message);

        return substr($message, 0, 900);
    }
}
