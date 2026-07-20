<?php

declare(strict_types=1);

namespace App\Domain\Chatbots;

final readonly class ChatbotExecutionResult
{
    /**
     * @param list<array<string, mixed>> $citations
     * @param array<string, mixed> $diagnostics
     * @param array<string, int> $usage
     */
    public function __construct(
        public ChatbotMessage $message,
        public string $answer,
        public array $citations,
        public array $diagnostics,
        public array $usage,
        public bool $fallback,
        public bool $replayed,
    ) {
    }
}
