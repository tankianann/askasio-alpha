<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\Connection;
use App\Domain\Admin\AdminUser;
use RuntimeException;

final class PdoAdminRepository implements AdminRepositoryInterface
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function count(): int
    {
        return (int) $this->connection->pdo()->query('SELECT COUNT(*) FROM admin_users')->fetchColumn();
    }

    public function create(string $username, string $passwordHash): AdminUser
    {
        $statement = $this->connection->pdo()->prepare(
            'INSERT INTO admin_users (username, password_hash, created_at, updated_at)
             VALUES (:username, :password_hash, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))',
        );
        $statement->execute([
            'username' => $username,
            'password_hash' => $passwordHash,
        ]);
        $id = (int) $this->connection->pdo()->lastInsertId();
        $admin = $this->findById($id);

        if (!$admin instanceof AdminUser) {
            throw new RuntimeException('The administrator could not be loaded after creation.');
        }

        return $admin;
    }

    public function findById(int $id): ?AdminUser
    {
        $statement = $this->connection->pdo()->prepare(
            'SELECT id, username, password_hash, last_login_at FROM admin_users WHERE id = :id LIMIT 1',
        );
        $statement->execute(['id' => $id]);

        return $this->hydrate($statement->fetch());
    }

    public function findByUsername(string $username): ?AdminUser
    {
        $statement = $this->connection->pdo()->prepare(
            'SELECT id, username, password_hash, last_login_at FROM admin_users WHERE username = :username LIMIT 1',
        );
        $statement->execute(['username' => $username]);

        return $this->hydrate($statement->fetch());
    }

    public function updatePasswordHash(int $id, string $passwordHash): void
    {
        $statement = $this->connection->pdo()->prepare(
            'UPDATE admin_users SET password_hash = :password_hash, updated_at = UTC_TIMESTAMP(6) WHERE id = :id',
        );
        $statement->execute(['id' => $id, 'password_hash' => $passwordHash]);
    }

    public function recordSuccessfulLogin(int $id): void
    {
        $statement = $this->connection->pdo()->prepare(
            'UPDATE admin_users SET last_login_at = UTC_TIMESTAMP(6), updated_at = UTC_TIMESTAMP(6) WHERE id = :id',
        );
        $statement->execute(['id' => $id]);
    }

    private function hydrate(mixed $row): ?AdminUser
    {
        if (!is_array($row)) {
            return null;
        }

        return new AdminUser(
            (int) $row['id'],
            (string) $row['username'],
            (string) $row['password_hash'],
            isset($row['last_login_at']) ? (string) $row['last_login_at'] : null,
        );
    }
}
