<?php

declare(strict_types=1);

namespace App\Domain\Chatbots;

final readonly class ChatbotPublication
{
    public function __construct(
        public int $id,
        public int $chatbotId,
        public int $publicationNumber,
        public int $sourceDraftRevision,
        public string $configurationHash,
        public ChatbotDraft $configuration,
        public ChatbotProviderConfiguration $providerConfiguration,
        public string $publishedAt,
    ) {
    }
}

