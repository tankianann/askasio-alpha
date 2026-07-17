<?php

declare(strict_types=1);

namespace App\Auth;

use App\Repositories\LoginAttemptRepositoryInterface;
use InvalidArgumentException;

final class LoginRateLimiter
{
    public function __construct(
        private readonly LoginAttemptRepositoryInterface $attempts,
        private readonly string $secret,
        private readonly int $maxAttempts,
        private readonly int $windowSeconds,
    ) {
        if ($this->maxAttempts < 1 || $this->maxAttempts > 100) {
            throw new InvalidArgumentException('Login attempts must be between 1 and 100.');
        }

        if ($this->windowSeconds < 60) {
            throw new InvalidArgumentException('The login rate-limit window must be at least 60 seconds.');
        }
    }

    public function tooManyAttempts(string $username, string $ipAddress): bool
    {
        [$usernameHash, $ipHash] = $this->keys($username, $ipAddress);

        return $this->attempts->countRecent($usernameHash, $ipHash, $this->windowSeconds) >= $this->maxAttempts;
    }

    public function recordFailure(string $username, string $ipAddress): void
    {
        [$usernameHash, $ipHash] = $this->keys($username, $ipAddress);
        $this->attempts->record($usernameHash, $ipHash);

        if (random_int(1, 100) === 1) {
            $this->attempts->purgeOlderThan($this->windowSeconds * 4);
        }
    }

    public function clear(string $username, string $ipAddress): void
    {
        [$usernameHash, $ipHash] = $this->keys($username, $ipAddress);
        $this->attempts->clear($usernameHash, $ipHash);
    }

    public function windowMinutes(): int
    {
        return (int) ceil($this->windowSeconds / 60);
    }

    public function ipIdentifier(string $ipAddress): string
    {
        return hash_hmac('sha256', $ipAddress, $this->secret);
    }

    /** @return array{string, string} */
    private function keys(string $username, string $ipAddress): array
    {
        return [
            hash_hmac('sha256', AuthenticationService::normalizeUsername($username), $this->secret),
            hash_hmac('sha256', $ipAddress, $this->secret),
        ];
    }
}
