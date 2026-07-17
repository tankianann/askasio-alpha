<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\Connection;

final class PdoLoginAttemptRepository implements LoginAttemptRepositoryInterface
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function countRecent(string $usernameHash, string $ipHash, int $windowSeconds): int
    {
        $cutoff = gmdate('Y-m-d H:i:s.u', time() - $windowSeconds);
        $statement = $this->connection->pdo()->prepare(
            'SELECT COUNT(*) FROM admin_login_attempts
             WHERE attempted_at >= :cutoff
               AND (username_hash = :username_hash OR ip_hash = :ip_hash)',
        );
        $statement->execute([
            'cutoff' => $cutoff,
            'username_hash' => $usernameHash,
            'ip_hash' => $ipHash,
        ]);

        return (int) $statement->fetchColumn();
    }

    public function record(string $usernameHash, string $ipHash): void
    {
        $statement = $this->connection->pdo()->prepare(
            'INSERT INTO admin_login_attempts (username_hash, ip_hash, attempted_at)
             VALUES (:username_hash, :ip_hash, UTC_TIMESTAMP(6))',
        );
        $statement->execute([
            'username_hash' => $usernameHash,
            'ip_hash' => $ipHash,
        ]);
    }

    public function clear(string $usernameHash, string $ipHash): void
    {
        $statement = $this->connection->pdo()->prepare(
            'DELETE FROM admin_login_attempts WHERE username_hash = :username_hash OR ip_hash = :ip_hash',
        );
        $statement->execute([
            'username_hash' => $usernameHash,
            'ip_hash' => $ipHash,
        ]);
    }

    public function purgeOlderThan(int $ageSeconds): void
    {
        $cutoff = gmdate('Y-m-d H:i:s.u', time() - $ageSeconds);
        $statement = $this->connection->pdo()->prepare('DELETE FROM admin_login_attempts WHERE attempted_at < :cutoff');
        $statement->execute(['cutoff' => $cutoff]);
    }
}
