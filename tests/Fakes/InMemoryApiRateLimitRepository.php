<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Repositories\ApiRateLimitRepositoryInterface;

final class InMemoryApiRateLimitRepository implements ApiRateLimitRepositoryInterface
{
    /** @var array<string, int> */
    private array $counts = [];

    public function consume(
        string $scope,
        string $identifierHash,
        string $bucketStartedAt,
        string $expiresAt,
    ): int {
        $key = implode(':', [$scope, $identifierHash, $bucketStartedAt]);
        $this->counts[$key] = ($this->counts[$key] ?? 0) + 1;

        return $this->counts[$key];
    }

    public function pruneExpired(int $limit = 500): int
    {
        return 0;
    }
}
