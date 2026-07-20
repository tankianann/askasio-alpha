<?php

declare(strict_types=1);

namespace App\Domain\Chatbots;

final readonly class ChatbotMessageReservation
{
    public function __construct(
        public ChatbotMessageReservationState $state,
        public ChatbotSession $session,
        public ChatbotMessage $userMessage,
        public ?ChatbotMessage $assistantMessage,
    ) {
    }
}
