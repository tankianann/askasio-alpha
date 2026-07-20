<?php

declare(strict_types=1);

namespace App\Domain\Chatbots;

final readonly class ChatbotMessage
{
    /** @param array<string, mixed>|null $retrieval @param list<array<string, mixed>>|null $citations */
    public function __construct(
        public int $id,
        public int $sessionId,
        public ?int $replyToMessageId,
        public ChatbotMessageRole $role,
        public ChatbotMessageStatus $status,
        public ?string $content,
        public ?string $contentHash,
        public ?string $idempotencyKeyHash,
        public string $requestId,
        public ?string $provider,
        public ?string $model,
        public ?int $latencyMs,
        public int $inputTokens,
        public int $outputTokens,
        public int $embeddingTokens,
        public int $providerTokens,
        public ?array $retrieval,
        public ?array $citations,
        public ?string $errorCode,
        public string $createdAt,
        public ?string $completedAt,
    ) {
    }
}
