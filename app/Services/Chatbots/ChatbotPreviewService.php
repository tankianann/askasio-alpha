<?php

declare(strict_types=1);

namespace App\Services\Chatbots;

use App\Auth\SessionStoreInterface;
use App\Domain\Chatbots\Chatbot;
use App\Domain\Chatbots\ChatbotExecutionAudience;
use App\Domain\Chatbots\ChatbotExecutionResult;
use App\Domain\Chatbots\ChatbotPreviewState;
use App\Domain\Chatbots\ChatbotProviderConfiguration;
use App\Domain\Chatbots\ChatbotSessionChannel;
use App\Domain\Chatbots\ChatbotSessionStatus;
use DateTimeImmutable;
use Throwable;

final readonly class ChatbotPreviewService
{
    private const SESSION_KEY = '_chatbot_preview_credentials';

    public function __construct(
        private ChatbotConversationService $conversations,
        private SharedChatExecutionService $executor,
        private SessionStoreInterface $session,
        private ChatbotProviderConfiguration $provider,
    ) {
    }

    public function current(Chatbot $chatbot): ?ChatbotPreviewState
    {
        $credentials = $this->credentials($chatbot->id);

        if ($credentials === null) {
            return null;
        }

        try {
            $session = $this->conversations->authenticate($credentials['public_id'], $credentials['token']);

            if ($session->chatbotId !== $chatbot->id
                || $session->channel !== ChatbotSessionChannel::AdminPreview
                || !$session->isTest) {
                throw new \RuntimeException('Stored preview credentials have an invalid scope.');
            }

            return new ChatbotPreviewState(
                $session,
                $this->conversations->messages($credentials['public_id'], $credentials['token']),
            );
        } catch (Throwable) {
            $this->forget($chatbot->id);

            return null;
        }
    }

    public function send(
        Chatbot $chatbot,
        string $question,
        string $idempotencyKey,
        string $requestId,
        string $safetyIdentifier,
        DateTimeImmutable $now,
    ): ChatbotExecutionResult {
        $state = $this->current($chatbot);

        if ($state === null
            || $state->session->status !== ChatbotSessionStatus::Active
            || $state->session->previewDraftRevision !== $chatbot->draft->revision) {
            $state = $this->restart($chatbot, $now);
        }

        $credentials = $this->credentials($chatbot->id)
            ?? throw new \LogicException('Preview credentials were not stored.');
        $reservation = $this->conversations->reserveMessage(
            $state->session->publicId,
            $credentials['token'],
            $idempotencyKey,
            $question,
            $requestId,
            $now,
        );

        return $this->executor->execute(
            $reservation,
            ChatbotExecutionAudience::AdminPreview,
            $safetyIdentifier,
            $now,
        );
    }

    public function restart(Chatbot $chatbot, DateTimeImmutable $now): ChatbotPreviewState
    {
        $credentials = $this->credentials($chatbot->id);

        if ($credentials !== null) {
            try {
                $this->conversations->completeSession($credentials['public_id'], $credentials['token'], $now);
            } catch (Throwable) {
                // A missing/expired old preview must not prevent a fresh administrator test session.
            }
        }

        $created = $this->conversations->createDraftPreviewSession($chatbot, $this->provider, $now);
        $this->store($chatbot->id, $created->session->publicId, $created->token);

        return new ChatbotPreviewState($created->session, []);
    }

    /** @return array{public_id: string, token: string}|null */
    private function credentials(int $chatbotId): ?array
    {
        $all = $this->session->get(self::SESSION_KEY, []);

        if (!is_array($all) || !is_array($all[$chatbotId] ?? null)) {
            return null;
        }

        $credentials = $all[$chatbotId];

        return is_string($credentials['public_id'] ?? null) && is_string($credentials['token'] ?? null)
            ? ['public_id' => $credentials['public_id'], 'token' => $credentials['token']]
            : null;
    }

    private function store(int $chatbotId, string $publicId, string $token): void
    {
        $all = $this->session->get(self::SESSION_KEY, []);
        $all = is_array($all) ? $all : [];
        $all[$chatbotId] = ['public_id' => $publicId, 'token' => $token];
        $this->session->put(self::SESSION_KEY, $all);
    }

    private function forget(int $chatbotId): void
    {
        $all = $this->session->get(self::SESSION_KEY, []);

        if (!is_array($all)) {
            return;
        }

        unset($all[$chatbotId]);
        $this->session->put(self::SESSION_KEY, $all);
    }
}
