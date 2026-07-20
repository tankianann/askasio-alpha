<?php

declare(strict_types=1);

namespace App\Services\Chatbots;

final class RandomChatbotPublicIdGenerator implements ChatbotPublicIdGeneratorInterface
{
    private const PREFIX = 'cb_';

    public function generate(): string
    {
        return self::PREFIX . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}

