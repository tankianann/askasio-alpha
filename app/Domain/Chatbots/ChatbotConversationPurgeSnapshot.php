<?php

declare(strict_types=1);

namespace App\Domain\Chatbots;

final readonly class ChatbotConversationPurgeSnapshot
{
    public function __construct(
        public ChatbotConversationListQuery $query,
        public int $recordCount,
        public ?int $maximumId,
        public string $eligibleBefore,
    ) {
    }
}
