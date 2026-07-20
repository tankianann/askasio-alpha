<?php

declare(strict_types=1);

namespace App\Services\Chatbots;

use App\Domain\Chatbots\ChatbotSessionCredentials;

final class RandomChatbotSessionCredentialGenerator implements ChatbotSessionCredentialGeneratorInterface
{
    public function generate(): ChatbotSessionCredentials
    {
        $publicId = 'cs_' . $this->base64Url(random_bytes(32));
        $secret = $this->base64Url(random_bytes(32));
        $token = 'cst_v1_' . $secret;

        return new ChatbotSessionCredentials(
            $publicId,
            $token,
            'cst_v1_' . substr($secret, 0, 8),
            hash('sha256', $token),
        );
    }

    private function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
