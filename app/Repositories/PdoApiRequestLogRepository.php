<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\Connection;
use App\Domain\Api\ApiRequestConnectionOption;
use App\Domain\Api\ApiRequestLog;
use App\Domain\Api\ApiRequestLogQuery;
use App\Support\Pagination\PaginatedResult;

final class PdoApiRequestLogRepository implements ApiRequestLogRepositoryInterface
{
    private readonly ApiRequestLogSqlQueryBuilder $queries;

    public function __construct(
        private readonly Connection $connection,
        ?ApiRequestLogSqlQueryBuilder $queries = null,
    ) {
        $this->queries = $queries ?? new ApiRequestLogSqlQueryBuilder();
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

    public function paginate(ApiRequestLogQuery $query): PaginatedResult
    {
        $pdo = $this->connection->pdo();
        $where = $this->queries->where($query);
        $count = $pdo->prepare('SELECT COUNT(*) FROM api_request_logs logs' . $where['sql']);
        $count->execute($where['parameters']);
        $total = (int) $count->fetchColumn();
        $pageRequest = $query->pagination->clampToTotal($total);
        $statement = $pdo->prepare(
            $this->select()
            . $where['sql']
            . $this->queries->orderBy($query)
            . ' LIMIT ' . $pageRequest->perPage
            . ' OFFSET ' . $pageRequest->offset(),
        );
        $statement->execute($where['parameters']);

        return new PaginatedResult(
            array_map($this->hydrate(...), $statement->fetchAll()),
            $total,
            $pageRequest,
        );
    }

    public function connectionOptions(): array
    {
        $rows = $this->connection->pdo()->query(
            "SELECT logs.api_key_id,
                    MAX(api_key_records.name) AS api_key_name,
                    MAX(api_key_records.visible_prefix) AS api_key_prefix
             FROM api_request_logs logs
             LEFT JOIN api_keys api_key_records ON api_key_records.id = logs.api_key_id
             WHERE logs.api_key_id IS NOT NULL
             GROUP BY logs.api_key_id
             ORDER BY COALESCE(MAX(api_key_records.name), CONCAT('Deleted connection #', logs.api_key_id)),
                      logs.api_key_id",
        )->fetchAll();

        return array_map(static function (array $row): ApiRequestConnectionOption {
            $id = (int) $row['api_key_id'];
            $name = isset($row['api_key_name']) ? (string) $row['api_key_name'] : null;

            return new ApiRequestConnectionOption(
                $id,
                $name ?? sprintf('Deleted connection #%d', $id),
                isset($row['api_key_prefix']) ? (string) $row['api_key_prefix'] : null,
                $name === null,
            );
        }, $rows);
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
        return 'SELECT logs.request_id, logs.api_key_id, logs.method, logs.endpoint,
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
