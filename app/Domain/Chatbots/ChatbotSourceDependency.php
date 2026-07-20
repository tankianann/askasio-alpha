<?php

declare(strict_types=1);

namespace App\Domain\Chatbots;

final readonly class ChatbotSourceDependency
{
    public function __construct(
        public int $chatbotId,
        public string $chatbotName,
        public bool $inDraft,
        public bool $inActivePublication,
        public int $publicationCount,
    ) {
    }
}

