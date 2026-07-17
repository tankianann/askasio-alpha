<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Domain\Admin\AdminUser;

interface AdminRepositoryInterface
{
    public function count(): int;

    public function create(string $username, string $passwordHash): AdminUser;

    public function findById(int $id): ?AdminUser;

    public function findByUsername(string $username): ?AdminUser;

    public function updatePasswordHash(int $id, string $passwordHash): void;

    public function recordSuccessfulLogin(int $id): void;
}
