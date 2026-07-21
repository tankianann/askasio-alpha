<?php

declare(strict_types=1);

namespace App\Services\Chatbots;

use App\Repositories\ApiRateLimitRepositoryInterface;
use App\Services\Api\ApiRateLimitDecision;

final readonly class PublicChatbotRateLimiter
{
    public function __construct(
        private ApiRateLimitRepositoryInterface $repository,
        private string $applicationSecret,
        private int $windowSeconds,
        private int $perIpLimit,
        private int $perChatbotLimit,
        private string $bucketNamespace,
        private ?int $perSessionLimit = null,
    ) {
        if (strlen($this->applicationSecret) < 32
            || $this->windowSeconds < 1 || $this->windowSeconds > 3600
            || $this->perIpLimit < 1 || $this->perChatbotLimit < 1
            || ($this->perSessionLimit !== null && $this->perSessionLimit < 1)
            || preg_match('/\A[a-z0-9_-]{1,32}\z/', $this->bucketNamespace) !== 1) {
            throw new \InvalidArgumentException('Public chatbot rate-limit configuration is invalid.');
        }
    }

    public function consume(
        string $publicChatbotId,
        string $clientIp,
        ?string $publicSessionId = null,
        ?int $now = null,
    ): ApiRateLimitDecision
    {
        $now ??= time();
        $bucketEpoch = intdiv($now, $this->windowSeconds) * $this->windowSeconds;
        $startedAt = gmdate('Y-m-d H:i:s', $bucketEpoch);
        $expiresAt = gmdate('Y-m-d H:i:s', $bucketEpoch + ($this->windowSeconds * 2));
        $retryAfter = max(1, ($bucketEpoch + $this->windowSeconds) - $now);
        $this->repository->pruneExpired();
        $ipCount = $this->repository->consume(
            'ip',
            hash_hmac('sha256', $this->bucketNamespace . ':ip:' . $clientIp, $this->applicationSecret),
            $startedAt,
            $expiresAt,
        );

        if ($ipCount > $this->perIpLimit) {
            return new ApiRateLimitDecision(false, $this->perIpLimit, 0, $retryAfter);
        }

        $chatbotCount = $this->repository->consume(
            'chatbot',
            hash_hmac('sha256', $this->bucketNamespace . ':chatbot:' . $publicChatbotId, $this->applicationSecret),
            $startedAt,
            $expiresAt,
        );

        if ($chatbotCount > $this->perChatbotLimit) {
            return new ApiRateLimitDecision(false, $this->perChatbotLimit, 0, $retryAfter);
        }

        $sessionCount = 0;

        if ($this->perSessionLimit !== null && $publicSessionId !== null) {
            $sessionCount = $this->repository->consume(
                'session',
                hash_hmac('sha256', $this->bucketNamespace . ':session:' . $publicSessionId, $this->applicationSecret),
                $startedAt,
                $expiresAt,
            );

            if ($sessionCount > $this->perSessionLimit) {
                return new ApiRateLimitDecision(false, $this->perSessionLimit, 0, $retryAfter);
            }
        }

        $limits = [$this->perIpLimit, $this->perChatbotLimit];
        $remaining = [$this->perIpLimit - $ipCount, $this->perChatbotLimit - $chatbotCount];

        if ($this->perSessionLimit !== null && $publicSessionId !== null) {
            $limits[] = $this->perSessionLimit;
            $remaining[] = $this->perSessionLimit - $sessionCount;
        }

        return new ApiRateLimitDecision(
            true,
            min($limits),
            max(0, min($remaining)),
            $retryAfter,
        );
    }
}
