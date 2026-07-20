<?php

declare(strict_types=1);

namespace App\Domain\Chatbots;

final readonly class CreatedChatbotSession
{
    public function __construct(
        public ChatbotSession $session,
        public string $token,
    ) {
    }
}
