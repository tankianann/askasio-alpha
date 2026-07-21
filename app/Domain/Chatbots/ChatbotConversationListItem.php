<?php

declare(strict_types=1);

namespace App\Domain\Chatbots;

final readonly class ChatbotConversationListItem
{
    public function __construct(
        public int $id,
        public string $publicId,
        public int $chatbotId,
        public string $chatbotName,
        public ChatbotSessionChannel $channel,
        public ?string $origin,
        public bool $isTest,
        public ChatbotSessionStatus $status,
        public int $messageCount,
        public int $providerTokens,
        public string $startedAt,
        public string $lastActivityAt,
        public ?string $purgeEligibleAt,
    ) {
    }
}
