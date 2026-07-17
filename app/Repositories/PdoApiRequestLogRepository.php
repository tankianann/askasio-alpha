<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\Connection;
use App\Domain\Api\ApiRequestLog;

final class PdoApiRequestLogRepository implements ApiRequestLogRepositoryInterface
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function record(ApiRequestLog $log): void
    {
        $statement = $this->connection->pdo()->prepare(
            'INSERT INTO api_request_logs (
                request_id, api_key_id, ip_hash, method, endpoint, status_code,
                duration_ms, error_category, usage_json, created_at
             ) VALUES (
                :request_id, :api_key_id, :ip_hash, :method, :endpoint, :status_code,
                :duration_ms, :error_category, :usage_json, UTC_TIMESTAMP(6)
             )',
        );
        $statement->execute([
            'request_id' => $log->requestId,
            'api_key_id' => $log->apiKeyId,
            'ip_hash' => $log->ipHash,
            'method' => $log->method,
            'endpoint' => $log->endpoint,
            'status_code' => $log->statusCode,
            'duration_ms' => $log->durationMilliseconds,
            'error_category' => $log->errorCategory,
            'usage_json' => $log->usage === [] ? null : json_encode($log->usage, JSON_THROW_ON_ERROR),
        ]);
    }

    public function recent(int $limit): array
    {
        $limit = max(1, min($limit, 500));
        $rows = $this->connection->pdo()->query(
            $this->select() . ' ORDER BY logs.created_at DESC, logs.id DESC LIMIT ' . $limit,
        )->fetchAll();

        return array_map($this->hydrate(...), $rows);
    }

    public function pruneOlderThan(string $cutoff, int $limit = 1000): int
    {
        $limit = max(1, min($limit, 10000));
        $statement = $this->connection->pdo()->prepare(
            'DELETE FROM api_request_logs WHERE created_at < :cutoff LIMIT ' . $limit,
        );
        $statement->execute(['cutoff' => $cutoff]);

        return $statement->rowCount();
    }

    private function select(): string
    {
        return 'SELECT logs.request_id, logs.api_key_id, logs.ip_hash, logs.method, logs.endpoint,
                       logs.status_code, logs.duration_ms, logs.error_category, logs.usage_json,
                       logs.created_at, api_key_records.name AS api_key_name,
                       api_key_records.visible_prefix AS api_key_prefix
                FROM api_request_logs logs
                LEFT JOIN api_keys api_key_records ON api_key_records.id = logs.api_key_id';
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): ApiRequestLog
    {
        $usage = isset($row['usage_json'])
            ? json_decode((string) $row['usage_json'], true, flags: JSON_THROW_ON_ERROR)
            : [];

        return new ApiRequestLog(
            (string) $row['request_id'],
            isset($row['api_key_id']) ? (int) $row['api_key_id'] : null,
            (string) ($row['ip_hash'] ?? ''),
            (string) $row['method'],
            (string) $row['endpoint'],
            (int) $row['status_code'],
            (int) $row['duration_ms'],
            isset($row['error_category']) ? (string) $row['error_category'] : null,
            is_array($usage) ? $usage : [],
            (string) $row['created_at'],
            isset($row['api_key_name']) ? (string) $row['api_key_name'] : null,
            isset($row['api_key_prefix']) ? (string) $row['api_key_prefix'] : null,
        );
    }
}
