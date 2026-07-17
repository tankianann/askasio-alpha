<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Domain\Api\ApiRequestLog;
use App\Repositories\ApiRequestLogRepositoryInterface;

final class InMemoryApiRequestLogRepository implements ApiRequestLogRepositoryInterface
{
    /** @var list<ApiRequestLog> */
    public array $logs = [];

    public function record(ApiRequestLog $log): void
    {
        $this->logs[] = $log;
    }

    public function recent(int $limit): array
    {
        return array_slice(array_reverse($this->logs), 0, $limit);
    }

    public function pruneOlderThan(string $cutoff, int $limit = 1000): int
    {
        return 0;
    }
}
