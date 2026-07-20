<?php

declare(strict_types=1);

namespace App\Domain\Chatbots;

final readonly class ChatbotMessageCompletion
{
    /** @param array<string, mixed>|null $retrieval @param list<array<string, mixed>>|null $citations */
    public function __construct(
        public string $content,
        public string $provider,
        public string $model,
        public int $latencyMs,
        public int $inputTokens,
        public int $outputTokens,
        public int $embeddingTokens,
        public int $providerTokens,
        public ?array $retrieval = null,
        public ?array $citations = null,
    ) {
    }
}
