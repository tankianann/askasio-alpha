<?php

declare(strict_types=1);

namespace App\Repositories;

interface LoginAttemptRepositoryInterface
{
    public function countRecent(string $usernameHash, string $ipHash, int $windowSeconds): int;

    public function record(string $usernameHash, string $ipHash): void;

    public function clear(string $usernameHash, string $ipHash): void;

    public function purgeOlderThan(int $ageSeconds): void;
}
