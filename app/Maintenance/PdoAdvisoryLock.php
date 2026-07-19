<?php

declare(strict_types=1);

namespace App\Maintenance;

use App\Database\Connection;
use RuntimeException;

final class PdoAdvisoryLock implements MaintenanceLockInterface
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function acquire(string $name): bool
    {
        $this->validateName($name);
        $statement = $this->connection->pdo()->prepare('SELECT GET_LOCK(:lock_name, 0)');
        $statement->execute(['lock_name' => $name]);

        return (int) $statement->fetchColumn() === 1;
    }

    public function release(string $name): void
    {
        $this->validateName($name);
        $statement = $this->connection->pdo()->prepare('SELECT RELEASE_LOCK(:lock_name)');
        $statement->execute(['lock_name' => $name]);

        if ((int) $statement->fetchColumn() !== 1) {
            throw new RuntimeException('The maintenance lock could not be released.');
        }
    }

    private function validateName(string $name): void
    {
        if ($name === '' || strlen($name) > 64) {
            throw new \InvalidArgumentException('Maintenance lock names must contain between 1 and 64 bytes.');
        }
    }
}
