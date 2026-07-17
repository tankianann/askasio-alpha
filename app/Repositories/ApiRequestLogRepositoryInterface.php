<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Domain\Api\ApiRequestLog;

interface ApiRequestLogRepositoryInterface
{
    public function record(ApiRequestLog $log): void;

    /** @return list<ApiRequestLog> */
    public function recent(int $limit): array;

    public function pruneOlderThan(string $cutoff, int $limit = 1000): int;
}
