<?php

declare(strict_types=1);

namespace App\Domain\Chatbots;

final readonly class ChatbotSession
{
    public function __construct(
        public int $id,
        public string $publicId,
        public string $tokenPrefix,
        public string $tokenHash,
        public int $chatbotId,
        public int $publicationId,
        public ChatbotSessionChannel $channel,
        public ?string $normalizedOrigin,
        public bool $isTest,
        public ChatbotSessionStatus $status,
        public int $messageCount,
        public int $maximumMessages,
        public int $maximumMessageCharacters,
        public int $idleTimeoutMinutes,
        public int $retentionDays,
        public int $inputTokens,
        public int $outputTokens,
        public int $embeddingTokens,
        public int $providerTokens,
        public string $startedAt,
        public string $lastActivityAt,
        public string $idleExpiresAt,
        public string $absoluteExpiresAt,
        public ?string $completedAt,
        public ?string $purgeEligibleAt,
    ) {
    }
}
