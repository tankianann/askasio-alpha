<?php

declare(strict_types=1);

namespace App\Domain\Chatbots;

final readonly class ChatbotPreviewState
{
    /** @param list<ChatbotMessage> $messages */
    public function __construct(
        public ChatbotSession $session,
        public array $messages,
    ) {
    }
}
