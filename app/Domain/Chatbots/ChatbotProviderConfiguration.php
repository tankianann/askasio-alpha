<?php

declare(strict_types=1);

namespace App\Domain\Chatbots;

final readonly class ChatbotProviderConfiguration
{
    public function __construct(
        public string $chatProvider,
        public string $chatModel,
        public string $embeddingProvider,
        public string $embeddingModel,
        public ?int $embeddingDimensions,
    ) {
    }

    /** @return array<string, int|string|null> */
    public function configuration(): array
    {
        return [
            'chat_provider' => $this->chatProvider,
            'chat_model' => $this->chatModel,
            'embedding_provider' => $this->embeddingProvider,
            'embedding_model' => $this->embeddingModel,
            'embedding_dimensions' => $this->embeddingDimensions,
        ];
    }
}
