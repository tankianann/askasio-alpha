<?php

declare(strict_types=1);

namespace App\Repositories;

interface ApiRateLimitRepositoryInterface
{
    public function consume(
        string $scope,
        string $identifierHash,
        string $bucketStartedAt,
        string $expiresAt,
    ): int;

    public function pruneExpired(int $limit = 500): int;
}
