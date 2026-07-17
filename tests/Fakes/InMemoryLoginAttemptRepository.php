<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Repositories\LoginAttemptRepositoryInterface;

final class InMemoryLoginAttemptRepository implements LoginAttemptRepositoryInterface
{
    /** @var list<array{username: string, ip: string}> */
    private array $attempts = [];

    public function countRecent(string $usernameHash, string $ipHash, int $windowSeconds): int
    {
        return count(array_filter(
            $this->attempts,
            static fn (array $attempt): bool => $attempt['username'] === $usernameHash || $attempt['ip'] === $ipHash,
        ));
    }

    public function record(string $usernameHash, string $ipHash): void
    {
        $this->attempts[] = ['username' => $usernameHash, 'ip' => $ipHash];
    }

    public function clear(string $usernameHash, string $ipHash): void
    {
        $this->attempts = array_values(array_filter(
            $this->attempts,
            static fn (array $attempt): bool => $attempt['username'] !== $usernameHash && $attempt['ip'] !== $ipHash,
        ));
    }

    public function purgeOlderThan(int $ageSeconds): void
    {
    }
}
