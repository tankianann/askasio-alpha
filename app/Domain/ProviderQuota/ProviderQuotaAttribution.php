<?php

declare(strict_types=1);

namespace App\Domain\ProviderQuota;

use App\Domain\Api\ApiAccessMethod;

final readonly class ProviderQuotaAttribution
{
    public function __construct(
        public ApiAccessMethod $accessMethod,
        public ?int $apiKeyId = null,
        public ?int $chatbotApiKeyId = null,
        public ?int $chatbotId = null,
    ) {
        if ($accessMethod === ApiAccessMethod::All) {
            throw new \InvalidArgumentException('A concrete AI usage access method is required.');
        }

        foreach ([$apiKeyId, $chatbotApiKeyId, $chatbotId] as $id) {
            if ($id !== null && $id < 1) {
                throw new \InvalidArgumentException('AI usage attribution identifiers must be positive.');
            }
        }

        if ($accessMethod === ApiAccessMethod::GeneralApiKey && $apiKeyId === null) {
            throw new \InvalidArgumentException('General API key usage requires a General API key identifier.');
        }

        if ($accessMethod === ApiAccessMethod::ChatbotApiKey && $chatbotApiKeyId === null) {
            throw new \InvalidArgumentException('Chatbot API key usage requires a Chatbot API key identifier.');
        }
    }
}
