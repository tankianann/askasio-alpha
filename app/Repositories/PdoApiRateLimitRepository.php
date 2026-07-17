<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\Connection;

final class PdoApiRateLimitRepository implements ApiRateLimitRepositoryInterface
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function consume(
        string $scope,
        string $identifierHash,
        string $bucketStartedAt,
        string $expiresAt,
    ): int {
        $statement = $this->connection->pdo()->prepare(
            'INSERT INTO api_rate_limit_buckets (
                scope, identifier_hash, bucket_started_at, expires_at, request_count, created_at, updated_at
             ) VALUES (
                :scope, :identifier_hash, :bucket_started_at, :expires_at, 1, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6)
             ) ON DUPLICATE KEY UPDATE
                request_count = LAST_INSERT_ID(request_count + 1),
                expires_at = VALUES(expires_at),
                updated_at = UTC_TIMESTAMP(6)',
        );
        $statement->execute([
            'scope' => $scope,
            'identifier_hash' => $identifierHash,
            'bucket_started_at' => $bucketStartedAt,
            'expires_at' => $expiresAt,
        ]);

        if ($statement->rowCount() === 1) {
            return 1;
        }

        return (int) $this->connection->pdo()->lastInsertId();
    }

    public function pruneExpired(int $limit = 500): int
    {
        $limit = max(1, min($limit, 5000));
        $statement = $this->connection->pdo()->prepare(
            'DELETE FROM api_rate_limit_buckets WHERE expires_at < UTC_TIMESTAMP() LIMIT ' . $limit,
        );
        $statement->execute();

        return $statement->rowCount();
    }
}
