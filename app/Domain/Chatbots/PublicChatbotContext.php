<?php

declare(strict_types=1);

namespace App\Domain\Chatbots;

final readonly class PublicChatbotContext
{
    public function __construct(
        public Chatbot $chatbot,
        public ChatbotPublication $publication,
        public string $normalizedOrigin,
        public bool $executionAvailable,
    ) {
    }
}
