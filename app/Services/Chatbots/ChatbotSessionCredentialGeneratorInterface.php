<?php

declare(strict_types=1);

namespace App\Services\Chatbots;

use App\Domain\Chatbots\ChatbotSessionCredentials;

interface ChatbotSessionCredentialGeneratorInterface
{
    public function generate(): ChatbotSessionCredentials;
}
