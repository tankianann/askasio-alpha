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
    ) {
        if (strlen($this->applicationSecret) < 32
            || $this->windowSeconds < 1 || $this->windowSeconds > 3600
            || $this->perIpLimit < 1 || $this->perChatbotLimit < 1
            || preg_match('/\A[a-z0-9_-]{1,32}\z/', $this->bucketNamespace) !== 1) {
            throw new \InvalidArgumentException('Public chatbot rate-limit configuration is invalid.');
        }
    }

    public function consume(string $publicChatbotId, string $clientIp, ?int $now = null): ApiRateLimitDecision
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

        return new ApiRateLimitDecision(
            true,
            min($this->perIpLimit, $this->perChatbotLimit),
            max(0, min($this->perIpLimit - $ipCount, $this->perChatbotLimit - $chatbotCount)),
            $retryAfter,
        );
    }
}
