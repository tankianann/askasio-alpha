<?php

declare(strict_types=1);

namespace App\Services\Chatbots;

use App\Domain\Chatbots\ChatbotMessage;
use App\Domain\Chatbots\ChatbotMessageCompletion;
use App\Domain\Chatbots\ChatbotMessageReservation;
use App\Domain\Chatbots\Chatbot;
use App\Domain\Chatbots\ChatbotExecutionConfiguration;
use App\Domain\Chatbots\ChatbotProviderConfiguration;
use App\Domain\Chatbots\ChatbotSession;
use App\Domain\Chatbots\ChatbotSessionChannel;
use App\Domain\Chatbots\ChatbotSessionStatus;
use App\Domain\Chatbots\CreatedChatbotSession;
use App\Exceptions\InvalidChatbotSessionTokenException;
use App\Exceptions\ValidationException;
use App\Repositories\ChatbotConversationRepositoryInterface;
use DateTimeImmutable;
use DateInterval;
use JsonException;

final readonly class ChatbotConversationService
{
    public function __construct(
        private ChatbotConversationRepositoryInterface $conversations,
        private ChatbotSessionCredentialGeneratorInterface $credentials,
        private ChatbotOriginNormalizer $origins = new ChatbotOriginNormalizer(),
        private int $pendingTimeoutSeconds = 120,
    ) {
        if ($this->pendingTimeoutSeconds < 30 || $this->pendingTimeoutSeconds > 3_600) {
            throw new \InvalidArgumentException('The chatbot pending-message timeout is invalid.');
        }
    }

    public function createSession(
        int $chatbotId,
        ChatbotSessionChannel $channel,
        ?string $origin,
        bool $isTest,
        DateTimeImmutable $now,
    ): CreatedChatbotSession {
        if ($chatbotId < 1) {
            throw new \InvalidArgumentException('A valid chatbot ID is required.');
        }

        if ($channel === ChatbotSessionChannel::Browser) {
            if ($isTest || $origin === null) {
                throw new ValidationException('Browser sessions require an origin and cannot be classified as tests.');
            }

            $origin = $this->origins->normalize($origin);
        } elseif ($origin !== null) {
            throw new ValidationException('Only browser sessions may store an allowed origin.');
        }

        if ($channel === ChatbotSessionChannel::AdminPreview && !$isTest) {
            throw new ValidationException('Administrator preview sessions must be classified as tests.');
        }

        $credentials = $this->credentials->generate();
        $session = $this->conversations->createForActivePublication(
            $chatbotId,
            $credentials,
            $channel,
            $origin,
            $isTest,
            $now,
        );

        return new CreatedChatbotSession($session, $credentials->token);
    }

    public function authenticate(string $publicId, string $token): ChatbotSession
    {
        if (preg_match('/\Acs_[A-Za-z0-9_-]{43}\z/', $publicId) !== 1
            || preg_match('/\Acst_v1_[A-Za-z0-9_-]{43}\z/', $token) !== 1) {
            throw new InvalidChatbotSessionTokenException('The chatbot session credentials are invalid.');
        }

        $calculatedHash = hash('sha256', $token);
        $session = $this->conversations->findSessionByTokenHash($calculatedHash);

        if (!$session instanceof ChatbotSession
            || !hash_equals($session->tokenHash, $calculatedHash)
            || !hash_equals($session->publicId, $publicId)) {
            throw new InvalidChatbotSessionTokenException('The chatbot session credentials are invalid.');
        }

        return $session;
    }

    public function createDraftPreviewSession(
        Chatbot $chatbot,
        ChatbotProviderConfiguration $provider,
        DateTimeImmutable $now,
    ): CreatedChatbotSession {
        if ($chatbot->status === \App\Domain\Chatbots\ChatbotStatus::Archived) {
            throw new ValidationException('Archived chatbots cannot be previewed.');
        }

        $credentials = $this->credentials->generate();
        $session = $this->conversations->createForDraftPreview(
            new ChatbotExecutionConfiguration(
                $chatbot->id,
                null,
                $chatbot->draft->revision,
                $chatbot->draft,
                $chatbot->assignments,
                $provider,
            ),
            $credentials,
            $now,
        );

        return new CreatedChatbotSession($session, $credentials->token);
    }

    /** @return list<ChatbotMessage> */
    public function messages(string $publicId, string $token): array
    {
        $session = $this->authenticate($publicId, $token);

        return $this->conversations->messagesForSession($session->id);
    }

    public function reserveMessage(
        string $publicId,
        string $token,
        string $idempotencyKey,
        string $content,
        string $requestId,
        DateTimeImmutable $now,
    ): ChatbotMessageReservation {
        $session = $this->authenticate($publicId, $token);
        $idempotencyKey = trim($idempotencyKey);
        $content = trim($content);

        if ($idempotencyKey === '' || strlen($idempotencyKey) > 200
            || preg_match('/[^\x21-\x7E]/', $idempotencyKey) === 1) {
            throw new ValidationException('The idempotency key must contain 1 to 200 visible ASCII characters.');
        }

        if ($content === '' || mb_strlen($content) > $session->maximumMessageCharacters
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $content) === 1) {
            throw new ValidationException(sprintf(
                'The message must contain between 1 and %d valid characters.',
                $session->maximumMessageCharacters,
            ));
        }

        if (preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/i', $requestId) !== 1) {
            throw new ValidationException('The request ID must be a valid UUID.');
        }

        $idempotencyKeyHash = hash('sha256', $idempotencyKey);
        $this->conversations->recoverStalePendingMessage(
            $session->id,
            $idempotencyKeyHash,
            $now->sub(new DateInterval('PT' . $this->pendingTimeoutSeconds . 'S')),
            $now,
        );

        return $this->conversations->reserveUserMessage(
            $session->id,
            $idempotencyKeyHash,
            $content,
            hash('sha256', $content),
            strtolower($requestId),
            $now,
        );
    }

    public function completeMessage(
        ChatbotMessageReservation $reservation,
        ChatbotMessageCompletion $completion,
        DateTimeImmutable $now,
    ): ChatbotMessage {
        $this->validateCompletion($completion);

        return $this->conversations->completeMessage(
            $reservation->session->id,
            $reservation->userMessage->id,
            $completion,
            $now,
        );
    }

    public function failMessage(
        ChatbotMessageReservation $reservation,
        string $errorCode,
        int $providerTokens,
        DateTimeImmutable $now,
    ): ChatbotMessage {
        if (preg_match('/\A[a-z0-9_]{1,80}\z/', $errorCode) !== 1 || $providerTokens < 0) {
            throw new ValidationException('The safe chatbot message failure outcome is invalid.');
        }

        return $this->conversations->failMessage(
            $reservation->session->id,
            $reservation->userMessage->id,
            $errorCode,
            $providerTokens,
            $now,
        );
    }

    public function completeSession(string $publicId, string $token, DateTimeImmutable $now): ChatbotSession
    {
        $session = $this->authenticate($publicId, $token);

        return $this->conversations->transitionStatus($session->id, ChatbotSessionStatus::Completed, $now);
    }

    public function deleteSession(string $publicId, string $token): void
    {
        $session = $this->authenticate($publicId, $token);
        $this->conversations->permanentlyDelete($session->id);
    }

    private function validateCompletion(ChatbotMessageCompletion $completion): void
    {
        if (trim($completion->content) === '' || mb_strlen($completion->content) > 50_000
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $completion->content) === 1
            || trim($completion->provider) === '' || strlen($completion->provider) > 50
            || trim($completion->model) === '' || strlen($completion->model) > 190
            || $completion->latencyMs < 0
            || min(
                $completion->inputTokens,
                $completion->outputTokens,
                $completion->embeddingTokens,
                $completion->providerTokens,
            ) < 0) {
            throw new ValidationException('The completed chatbot message outcome is invalid.');
        }

        if (($completion->retrieval !== null && array_is_list($completion->retrieval))
            || ($completion->citations !== null && !$this->isObjectList($completion->citations))) {
            throw new ValidationException('Chatbot message metadata has an invalid shape.');
        }

        foreach ([$completion->retrieval, $completion->citations] as $metadata) {
            try {
                if ($metadata !== null && strlen(json_encode($metadata, JSON_THROW_ON_ERROR)) > 65_536) {
                    throw new ValidationException('Chatbot message metadata may not exceed 65536 bytes.');
                }
            } catch (JsonException) {
                throw new ValidationException('Chatbot message metadata must be valid UTF-8 JSON data.');
            }
        }
    }

    /** @param array<mixed> $value */
    private function isObjectList(array $value): bool
    {
        if (!array_is_list($value)) {
            return false;
        }

        foreach ($value as $item) {
            if (!is_array($item) || array_is_list($item)) {
                return false;
            }
        }

        return true;
    }
}
