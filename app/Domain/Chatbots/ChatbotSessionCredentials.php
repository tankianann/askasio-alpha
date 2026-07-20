<?php

declare(strict_types=1);

namespace App\Domain\Chatbots;

final readonly class ChatbotSessionCredentials
{
    public function __construct(
        public string $publicId,
        public string $token,
        public string $tokenPrefix,
        public string $tokenHash,
    ) {
    }
}
