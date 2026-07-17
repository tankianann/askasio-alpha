<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Domain\Admin\AdminUser;
use App\Repositories\AdminRepositoryInterface;

final class InMemoryAdminRepository implements AdminRepositoryInterface
{
    /** @var array<int, AdminUser> */
    private array $admins = [];

    public int $successfulLogins = 0;

    public int $passwordUpdates = 0;

    public function count(): int
    {
        return count($this->admins);
    }

    public function create(string $username, string $passwordHash): AdminUser
    {
        $admin = new AdminUser(count($this->admins) + 1, $username, $passwordHash);
        $this->admins[$admin->id] = $admin;

        return $admin;
    }

    public function findById(int $id): ?AdminUser
    {
        return $this->admins[$id] ?? null;
    }

    public function findByUsername(string $username): ?AdminUser
    {
        foreach ($this->admins as $admin) {
            if ($admin->username === $username) {
                return $admin;
            }
        }

        return null;
    }

    public function updatePasswordHash(int $id, string $passwordHash): void
    {
        $admin = $this->admins[$id];
        $this->admins[$id] = new AdminUser($admin->id, $admin->username, $passwordHash, $admin->lastLoginAt);
        $this->passwordUpdates++;
    }

    public function recordSuccessfulLogin(int $id): void
    {
        $this->successfulLogins++;
    }
}
