<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\Connection;
use App\Domain\ApiKeys\ApiKey;
use App\Domain\ApiKeys\ApiKeyListQuery;
use App\Support\Pagination\PaginatedResult;
use RuntimeException;

final class PdoApiKeyRepository implements ApiKeyRepositoryInterface
{
    private readonly ApiKeyListSqlQueryBuilder $listQueries;

    public function __construct(
        private readonly Connection $connection,
        ?ApiKeyListSqlQueryBuilder $listQueries = null,
    ) {
        $this->listQueries = $listQueries ?? new ApiKeyListSqlQueryBuilder();
    }

    public function all(): array
    {
        $rows = $this->connection->pdo()->query($this->select() . ' ORDER BY created_at DESC, id DESC')->fetchAll();

        return array_map($this->hydrate(...), $rows);
    }

    public function paginate(ApiKeyListQuery $query): PaginatedResult
    {
        $pdo = $this->connection->pdo();
        $where = $this->listQueries->where($query);
        $count = $pdo->prepare('SELECT COUNT(*) FROM api_keys' . $where['sql']);
        $count->execute($where['parameters']);
        $total = (int) $count->fetchColumn();
        $pageRequest = $query->pagination->clampToTotal($total);
        $statement = $pdo->prepare(
            $this->listSelect()
            . $where['sql']
            . $this->listQueries->orderBy($query)
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

    public function findById(int $id): ?ApiKey
    {
        $statement = $this->connection->pdo()->prepare($this->select() . ' WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function findByHash(string $secretHash): ?ApiKey
    {
        $statement = $this->connection->pdo()->prepare($this->select() . ' WHERE secret_hash = :hash LIMIT 1');
        $statement->execute(['hash' => $secretHash]);
        $row = $statement->fetch();

        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function create(
        int $adminId,
        string $name,
        string $visiblePrefix,
        string $secretHash,
        ?string $expiresAt,
    ): ApiKey {
        $statement = $this->connection->pdo()->prepare(
            "INSERT INTO api_keys (
                created_by_admin_id, name, visible_prefix, secret_hash, status, created_at, expires_at
             ) VALUES (
                :admin_id, :name, :visible_prefix, :secret_hash, 'active', UTC_TIMESTAMP(6), :expires_at
             )",
        );
        $statement->execute([
            'admin_id' => $adminId,
            'name' => $name,
            'visible_prefix' => $visiblePrefix,
            'secret_hash' => $secretHash,
            'expires_at' => $expiresAt,
        ]);
        $key = $this->findById((int) $this->connection->pdo()->lastInsertId());

        if (!$key instanceof ApiKey) {
            throw new RuntimeException('The API key could not be loaded after creation.');
        }

        return $key;
    }

    public function touchLastUsed(int $id): void
    {
        $statement = $this->connection->pdo()->prepare(
            'UPDATE api_keys SET last_used_at = UTC_TIMESTAMP(6) WHERE id = :id',
        );
        $statement->execute(['id' => $id]);
    }

    public function revoke(int $id): void
    {
        $statement = $this->connection->pdo()->prepare(
            "UPDATE api_keys
             SET status = 'revoked', revoked_at = COALESCE(revoked_at, UTC_TIMESTAMP(6))
             WHERE id = :id",
        );
        $statement->execute(['id' => $id]);
    }

    public function delete(int $id): void
    {
        $statement = $this->connection->pdo()->prepare('DELETE FROM api_keys WHERE id = :id');
        $statement->execute(['id' => $id]);
    }

    private function select(): string
    {
        return 'SELECT id, created_by_admin_id, name, visible_prefix, secret_hash, status,
                       created_at, last_used_at, expires_at, revoked_at
                FROM api_keys';
    }

    private function listSelect(): string
    {
        return 'SELECT id, created_by_admin_id, name, visible_prefix, status,
                       created_at, last_used_at, expires_at, revoked_at
                FROM api_keys';
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): ApiKey
    {
        return new ApiKey(
            (int) $row['id'],
            (int) $row['created_by_admin_id'],
            (string) $row['name'],
            (string) $row['visible_prefix'],
            (string) ($row['secret_hash'] ?? ''),
            (string) $row['status'],
            (string) $row['created_at'],
            isset($row['last_used_at']) ? (string) $row['last_used_at'] : null,
            isset($row['expires_at']) ? (string) $row['expires_at'] : null,
            isset($row['revoked_at']) ? (string) $row['revoked_at'] : null,
        );
    }
}
