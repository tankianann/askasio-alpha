<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Domain\Api\ApiRequestLog;
use App\Domain\Api\ApiRequestConnectionOption;
use App\Domain\Api\ApiRequestLogQuery;
use App\Support\Pagination\PaginatedResult;

interface ApiRequestLogRepositoryInterface
{
    public function record(ApiRequestLog $log): void;

    /** @return list<ApiRequestLog> */
    public function recent(int $limit): array;

    /** @return PaginatedResult<ApiRequestLog> */
    public function paginate(ApiRequestLogQuery $query): PaginatedResult;

    /** @return list<ApiRequestConnectionOption> */
    public function connectionOptions(): array;

    public function pruneOlderThan(string $cutoff, int $limit = 1000): int;
}
