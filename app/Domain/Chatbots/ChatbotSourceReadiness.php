<?php

declare(strict_types=1);

namespace App\Domain\Chatbots;

final readonly class ChatbotSourceReadiness
{
    public function __construct(
        public int $sourceId,
        public ?string $sourceName,
        public ChatbotSourceReadinessStatus $status,
        public ?int $activeVersionId,
    ) {
    }

    public function isReady(): bool
    {
        return $this->status === ChatbotSourceReadinessStatus::Ready;
    }
}

