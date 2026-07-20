<?php

declare(strict_types=1);

namespace App\Domain\Chatbots;

final readonly class ChatbotSelectedHistory
{
    /** @param list<array{role: 'user'|'assistant', content: string}> $messages */
    public function __construct(
        public array $messages,
        public int $estimatedTokens,
        public string $policy = 'recent_completed_turns_v1',
    ) {
    }
}
