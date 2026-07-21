<?php

declare(strict_types=1);

namespace App\Services\Chatbots;

use App\Domain\Chatbots\ChatbotProviderConfiguration;
use App\Domain\Chatbots\ChatbotStatus;
use App\Domain\Chatbots\PublicChatbotContext;
use App\Exceptions\HttpException;
use App\Exceptions\ValidationException;
use App\Repositories\ChatbotRepositoryInterface;

final readonly class PublicChatbotAccessService
{
    public function __construct(
        private ChatbotRepositoryInterface $chatbots,
        private ChatbotOriginNormalizer $origins,
        private ChatbotProviderConfiguration $installationProvider,
        private bool $providerConfigured,
    ) {
    }

    public function authorize(string $publicId, ?string $origin): PublicChatbotContext
    {
        if (preg_match('/\Acb_[A-Za-z0-9_-]{43}\z/', $publicId) !== 1) {
            throw new HttpException(404, 'The chatbot was not found.', 'chatbot_not_found');
        }

        if (!is_string($origin) || $origin === '') {
            throw new HttpException(403, 'This browser origin is not allowed.', 'origin_not_allowed');
        }

        try {
            $normalizedOrigin = $this->origins->normalize($origin);
        } catch (ValidationException) {
            throw new HttpException(403, 'This browser origin is not allowed.', 'origin_not_allowed');
        }

        $chatbot = $this->chatbots->findByPublicId($publicId);

        if ($chatbot === null
            || $chatbot->status !== ChatbotStatus::Active
            || $chatbot->activePublicationId === null) {
            throw new HttpException(404, 'The chatbot was not found.', 'chatbot_not_found');
        }

        $publication = $this->chatbots->findActivePublication($chatbot->id);

        if ($publication === null
            || $publication->id !== $chatbot->activePublicationId
            || !in_array($normalizedOrigin, $publication->assignments->origins, true)) {
            throw new HttpException(403, 'This browser origin is not allowed.', 'origin_not_allowed');
        }

        return new PublicChatbotContext(
            $chatbot,
            $publication,
            $normalizedOrigin,
            $this->providerConfigured
                && $publication->providerConfiguration->configuration()
                    === $this->installationProvider->configuration(),
        );
    }
}
