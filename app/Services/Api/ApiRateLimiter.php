<?php

declare(strict_types=1);

namespace App\Services\Api;

use App\Domain\ApiKeys\ApiKey;
use App\Repositories\ApiRateLimitRepositoryInterface;
use InvalidArgumentException;

final class ApiRateLimiter
{
    public function __construct(
        private readonly ApiRateLimitRepositoryInterface $repository,
        private readonly string $applicationSecret,
        private readonly int $windowSeconds,
        private readonly int $perKeyLimit,
        private readonly int $perIpLimit,
        private readonly string $bucketNamespace = 'api',
    ) {
        if (strlen($this->applicationSecret) < 32) {
            throw new InvalidArgumentException('A strong application secret is required for API rate limiting.');
        }

        if ($this->windowSeconds < 1 || $this->windowSeconds > 3600
            || $this->perKeyLimit < 1 || $this->perIpLimit < 1) {
            throw new InvalidArgumentException('API rate-limit configuration is invalid.');
        }

        if (preg_match('/^[a-z0-9_-]{1,32}$/', $this->bucketNamespace) !== 1) {
            throw new InvalidArgumentException('API rate-limit namespace is invalid.');
        }
    }

    public function consume(ApiKey $apiKey, string $clientIp, ?int $now = null): ApiRateLimitDecision
    {
        $now ??= time();
        $bucketEpoch = intdiv($now, $this->windowSeconds) * $this->windowSeconds;
        $bucketStartedAt = gmdate('Y-m-d H:i:s', $bucketEpoch);
        $expiresAt = gmdate('Y-m-d H:i:s', $bucketEpoch + ($this->windowSeconds * 2));
        $retryAfter = max(1, ($bucketEpoch + $this->windowSeconds) - $now);
        $this->repository->pruneExpired();
        $keyCount = $this->repository->consume(
            'api_key',
            hash('sha256', $this->bucketNamespace . ':api-key:' . $apiKey->id),
            $bucketStartedAt,
            $expiresAt,
        );

        if ($keyCount > $this->perKeyLimit) {
            return new ApiRateLimitDecision(false, $this->perKeyLimit, 0, $retryAfter);
        }

        $ipCount = $this->repository->consume(
            'ip',
            hash_hmac('sha256', $this->bucketNamespace . ':' . $clientIp, $this->applicationSecret),
            $bucketStartedAt,
            $expiresAt,
        );

        if ($ipCount > $this->perIpLimit) {
            return new ApiRateLimitDecision(false, $this->perIpLimit, 0, $retryAfter);
        }

        return new ApiRateLimitDecision(
            true,
            min($this->perKeyLimit, $this->perIpLimit),
            max(0, min($this->perKeyLimit - $keyCount, $this->perIpLimit - $ipCount)),
            $retryAfter,
        );
    }
}
