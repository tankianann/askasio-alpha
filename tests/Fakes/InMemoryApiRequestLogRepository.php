<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Domain\Api\ApiRequestAuthenticationState;
use App\Domain\Api\ApiRequestConnectionOption;
use App\Domain\Api\ApiRequestLog;
use App\Domain\Api\ApiRequestLogPurgeCriteria;
use App\Domain\Api\ApiRequestLogPurgeSnapshot;
use App\Domain\Api\ApiRequestStatusGroup;
use App\Domain\Api\ApiRequestLogQuery;
use App\Domain\Api\ApiRequestLogSort;
use App\Repositories\ApiRequestLogRepositoryInterface;
use App\Support\Pagination\PaginatedResult;
use App\Support\SortDirection;
use Throwable;

final class InMemoryApiRequestLogRepository implements ApiRequestLogRepositoryInterface
{
    /** @var list<ApiRequestLog> */
    public array $logs = [];
    public ?Throwable $pruneFailure = null;

    /** @var array<int, int> */
    private array $logIds = [];
    private int $nextLogId = 1;

    public function record(ApiRequestLog $log): void
    {
        $this->logs[] = $log;
    }

    public function recent(int $limit): array
    {
        return array_slice(array_reverse($this->logs), 0, $limit);
    }

    public function paginate(ApiRequestLogQuery $query): PaginatedResult
    {
        $logs = array_values(array_filter($this->logs, static function (ApiRequestLog $log) use ($query): bool {
            if ($query->createdFromUtc !== null && (string) $log->createdAt < $query->createdFromUtc) {
                return false;
            }

            if ($query->createdBeforeUtc !== null && (string) $log->createdAt >= $query->createdBeforeUtc) {
                return false;
            }

            if ($query->apiKeyId !== null && $log->apiKeyId !== $query->apiKeyId) {
                return false;
            }

            if ($query->endpoint !== null && $log->endpoint !== $query->endpoint) {
                return false;
            }

            if ($query->method !== null && $log->method !== $query->method) {
                return false;
            }

            if ($query->statusCode !== null && $log->statusCode !== $query->statusCode) {
                return false;
            }

            if ($query->statusCode === null && $query->statusGroup !== null
                && ($log->statusCode < $query->statusGroup->minimumStatus()
                    || $log->statusCode > $query->statusGroup->maximumStatus())) {
                return false;
            }

            if ($query->minimumDurationMilliseconds !== null
                && $log->durationMilliseconds < $query->minimumDurationMilliseconds) {
                return false;
            }

            if ($query->maximumDurationMilliseconds !== null
                && $log->durationMilliseconds > $query->maximumDurationMilliseconds) {
                return false;
            }

            if ($query->requestId !== null && $log->requestId !== $query->requestId) {
                return false;
            }

            if ($query->authentication === ApiRequestAuthenticationState::Authenticated && $log->apiKeyId === null) {
                return false;
            }

            return $query->authentication !== ApiRequestAuthenticationState::Unauthenticated
                || $log->apiKeyId === null;
        }));
        $direction = $query->direction === SortDirection::Ascending ? 1 : -1;
        usort($logs, static function (ApiRequestLog $left, ApiRequestLog $right) use ($query, $direction): int {
            $comparison = match ($query->sort) {
                ApiRequestLogSort::Date => strcmp((string) $left->createdAt, (string) $right->createdAt),
                ApiRequestLogSort::Duration => $left->durationMilliseconds <=> $right->durationMilliseconds,
                ApiRequestLogSort::Status => $left->statusCode <=> $right->statusCode,
                ApiRequestLogSort::Endpoint => strcmp($left->endpoint, $right->endpoint),
                ApiRequestLogSort::Connection => strcmp((string) $left->apiKeyName, (string) $right->apiKeyName),
            };

            return ($comparison !== 0 ? $comparison : strcmp($left->requestId, $right->requestId)) * $direction;
        });
        $total = count($logs);
        $pageRequest = $query->pagination->clampToTotal($total);

        return new PaginatedResult(
            array_slice($logs, $pageRequest->offset(), $pageRequest->perPage),
            $total,
            $pageRequest,
        );
    }

    public function connectionOptions(): array
    {
        $options = [];

        foreach ($this->logs as $log) {
            if ($log->apiKeyId === null) {
                continue;
            }

            $options[$log->apiKeyId] = new ApiRequestConnectionOption(
                $log->apiKeyId,
                $log->apiKeyName ?? sprintf('Deleted connection #%d', $log->apiKeyId),
                $log->apiKeyPrefix,
                $log->apiKeyName === null,
            );
        }

        usort($options, static fn (ApiRequestConnectionOption $left, ApiRequestConnectionOption $right): int =>
            strcasecmp($left->name, $right->name));

        return array_values($options);
    }

    public function pruneOlderThan(string $cutoff, int $limit = 1000): int
    {
        if ($this->pruneFailure instanceof Throwable) {
            throw $this->pruneFailure;
        }

        $deleted = 0;
        $remaining = [];

        foreach ($this->logs as $log) {
            if ($deleted < $limit && $log->createdAt !== null && $log->createdAt < $cutoff) {
                ++$deleted;
                continue;
            }

            $remaining[] = $log;
        }

        $this->logs = $remaining;

        return $deleted;
    }

    public function purgeSnapshot(ApiRequestLogPurgeCriteria $criteria): ApiRequestLogPurgeSnapshot
    {
        $count = 0;
        $maximumId = null;

        foreach ($this->logs as $log) {
            if (!$this->matchesPurgeCriteria($log, $criteria)) {
                continue;
            }

            ++$count;
            $maximumId = max($maximumId ?? 0, $this->idFor($log));
        }

        return new ApiRequestLogPurgeSnapshot($criteria, $count, $maximumId);
    }

    public function purgeSnapshotBatch(ApiRequestLogPurgeSnapshot $snapshot, int $limit = 1000): int
    {
        if ($snapshot->maximumId === null) {
            return 0;
        }

        $deleted = 0;
        $remaining = [];

        foreach ($this->logs as $log) {
            if ($deleted < $limit
                && $this->idFor($log) <= $snapshot->maximumId
                && $this->matchesPurgeCriteria($log, $snapshot->criteria)) {
                ++$deleted;
                continue;
            }

            $remaining[] = $log;
        }

        $this->logs = $remaining;

        return $deleted;
    }

    private function idFor(ApiRequestLog $log): int
    {
        $objectId = spl_object_id($log);

        return $this->logIds[$objectId] ??= $this->nextLogId++;
    }

    private function matchesPurgeCriteria(ApiRequestLog $log, ApiRequestLogPurgeCriteria $criteria): bool
    {
        if ($criteria->cutoffUtc !== null && ($log->createdAt === null || $log->createdAt >= $criteria->cutoffUtc)) {
            return false;
        }

        if ($criteria->createdFromUtc !== null && ($log->createdAt === null || $log->createdAt < $criteria->createdFromUtc)) {
            return false;
        }

        if ($criteria->createdBeforeUtc !== null && ($log->createdAt === null || $log->createdAt >= $criteria->createdBeforeUtc)) {
            return false;
        }

        if ($criteria->apiKeyId !== null && $log->apiKeyId !== $criteria->apiKeyId) {
            return false;
        }

        if ($criteria->endpoint !== null && $log->endpoint !== $criteria->endpoint) {
            return false;
        }

        if ($criteria->method !== null && $log->method !== $criteria->method) {
            return false;
        }

        if ($criteria->statusCode !== null && $log->statusCode !== $criteria->statusCode) {
            return false;
        }

        if ($criteria->statusCode === null && $criteria->statusGroup instanceof ApiRequestStatusGroup
            && ($log->statusCode < $criteria->statusGroup->minimumStatus()
                || $log->statusCode > $criteria->statusGroup->maximumStatus())) {
            return false;
        }

        if ($criteria->minimumDurationMilliseconds !== null
            && $log->durationMilliseconds < $criteria->minimumDurationMilliseconds) {
            return false;
        }

        if ($criteria->maximumDurationMilliseconds !== null
            && $log->durationMilliseconds > $criteria->maximumDurationMilliseconds) {
            return false;
        }

        if ($criteria->requestId !== null && $log->requestId !== $criteria->requestId) {
            return false;
        }

        if ($criteria->authentication === ApiRequestAuthenticationState::Authenticated && $log->apiKeyId === null) {
            return false;
        }

        return $criteria->authentication !== ApiRequestAuthenticationState::Unauthenticated || $log->apiKeyId === null;
    }
}
