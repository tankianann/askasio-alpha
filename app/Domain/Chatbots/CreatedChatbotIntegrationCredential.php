<?php

declare(strict_types=1);

namespace App\Domain\Chatbots;

final readonly class CreatedChatbotIntegrationCredential
{
    public function __construct(public ChatbotIntegrationCredential $credential, public string $plaintext)
    {
    }
}
