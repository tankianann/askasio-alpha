<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Domain\Chatbots\ChatbotMessage;
use App\Domain\Chatbots\ChatbotExecutionConfiguration;
use App\Domain\Chatbots\ChatbotMessageCompletion;
use App\Domain\Chatbots\ChatbotMessageReservation;
use App\Domain\Chatbots\ChatbotMessageReservationState;
use App\Domain\Chatbots\ChatbotMessageRole;
use App\Domain\Chatbots\ChatbotMessageStatus;
use App\Domain\Chatbots\ChatbotSession;
use App\Domain\Chatbots\ChatbotSessionChannel;
use App\Domain\Chatbots\ChatbotSessionCredentials;
use App\Domain\Chatbots\ChatbotSessionStatus;
use App\Exceptions\ChatbotIdempotencyConflictException;
use App\Exceptions\ChatbotMessageLimitException;
use App\Exceptions\ChatbotSessionUnavailableException;
use App\Repositories\ChatbotConversationRepositoryInterface;
use DateInterval;
use DateTimeImmutable;
use App\Domain\Chatbots\ChatbotConversationListQuery;
use App\Domain\Chatbots\ChatbotConversationPurgeSnapshot;
use App\Domain\Chatbots\ChatbotAnalytics;
use App\Domain\Chatbots\ChatbotAnalyticsQuery;
use App\Support\Pagination\PaginatedResult;

final class InMemoryChatbotConversationRepository implements ChatbotConversationRepositoryInterface
{
    /** @var array<int, array{publication_id: int, status: string, origins: list<string>, maximum_messages: int, maximum_characters: int, idle_minutes: int, absolute_minutes: int, retention_days: int}> */
    private array $chatbots = [];

    /** @var array<int, ChatbotSession> */
    private array $sessions = [];

    /** @var array<int, ChatbotMessage> */
    private array $messages = [];

    /** @var array<int, ChatbotExecutionConfiguration> */
    private array $previewConfigurations = [];

    private int $nextSessionId = 1;
    private int $nextMessageId = 1;

    /** @param list<string> $origins */
    public function defineChatbot(
        int $chatbotId,
        int $publicationId = 10,
        string $status = 'active',
        array $origins = ['https://example.com'],
        int $maximumMessages = 3,
        int $maximumCharacters = 2_000,
        int $idleMinutes = 30,
        int $absoluteMinutes = 120,
        int $retentionDays = 30,
    ): void {
        $this->chatbots[$chatbotId] = [
            'publication_id' => $publicationId,
            'status' => $status,
            'origins' => $origins,
            'maximum_messages' => $maximumMessages,
            'maximum_characters' => $maximumCharacters,
            'idle_minutes' => $idleMinutes,
            'absolute_minutes' => $absoluteMinutes,
            'retention_days' => $retentionDays,
        ];
    }

    public function createForActivePublication(
        int $chatbotId,
        ChatbotSessionCredentials $credentials,
        ChatbotSessionChannel $channel,
        ?string $normalizedOrigin,
        bool $isTest,
        DateTimeImmutable $now,
    ): ChatbotSession {
        $definition = $this->chatbots[$chatbotId] ?? null;

        if ($definition === null || $definition['status'] === 'archived'
            || ($channel !== ChatbotSessionChannel::AdminPreview && $definition['status'] !== 'active')) {
            throw new ChatbotSessionUnavailableException('The chatbot is not available.');
        }

        if ($channel === ChatbotSessionChannel::Browser
            && !in_array($normalizedOrigin, $definition['origins'], true)) {
            throw new ChatbotSessionUnavailableException('The browser origin is not allowed.');
        }

        $absolute = $now->add(new DateInterval('PT' . $definition['absolute_minutes'] . 'M'));
        $idle = $now->add(new DateInterval('PT' . $definition['idle_minutes'] . 'M'));
        $idle = $idle < $absolute ? $idle : $absolute;
        $session = new ChatbotSession(
            $this->nextSessionId++,
            $credentials->publicId,
            $credentials->tokenPrefix,
            $credentials->tokenHash,
            $chatbotId,
            $definition['publication_id'],
            $channel,
            $normalizedOrigin,
            $isTest,
            ChatbotSessionStatus::Active,
            0,
            $definition['maximum_messages'],
            $definition['maximum_characters'],
            $definition['idle_minutes'],
            $definition['retention_days'],
            0,
            0,
            0,
            0,
            $this->format($now),
            $this->format($now),
            $this->format($idle),
            $this->format($absolute),
            null,
            null,
        );

        return $this->sessions[$session->id] = $session;
    }

    public function findSessionByPublicId(string $publicId): ?ChatbotSession
    {
        foreach ($this->sessions as $session) {
            if ($session->publicId === $publicId) {
                return $session;
            }
        }

        return null;
    }

    public function createForDraftPreview(
        ChatbotExecutionConfiguration $configuration,
        ChatbotSessionCredentials $credentials,
        DateTimeImmutable $now,
    ): ChatbotSession {
        $definition = $this->chatbots[$configuration->chatbotId] ?? null;

        if ($definition === null || $definition['status'] === 'archived' || $configuration->draftRevision === null) {
            throw new ChatbotSessionUnavailableException('The chatbot draft is unavailable for preview.');
        }

        $draft = $configuration->configuration;
        $absolute = $now->add(new DateInterval('PT' . $draft->absoluteExpiryMinutes . 'M'));
        $idle = $now->add(new DateInterval('PT' . $draft->idleExpiryMinutes . 'M'));
        $idle = $idle < $absolute ? $idle : $absolute;
        $session = new ChatbotSession(
            $this->nextSessionId++, $credentials->publicId, $credentials->tokenPrefix, $credentials->tokenHash,
            $configuration->chatbotId, null, ChatbotSessionChannel::AdminPreview, null, true,
            ChatbotSessionStatus::Active, 0, $draft->maximumMessagesPerSession,
            $draft->maximumMessageCharacters, $draft->idleExpiryMinutes, $draft->retentionDays,
            0, 0, 0, 0, $this->format($now), $this->format($now), $this->format($idle),
            $this->format($absolute), null, null, $configuration->draftRevision,
        );
        $this->sessions[$session->id] = $session;
        $this->previewConfigurations[$session->id] = $configuration;

        return $session;
    }

    public function previewExecutionConfiguration(int $sessionId): ?ChatbotExecutionConfiguration
    {
        return $this->previewConfigurations[$sessionId] ?? null;
    }

    public function findSessionByTokenHash(string $tokenHash): ?ChatbotSession
    {
        foreach ($this->sessions as $session) {
            if (hash_equals($session->tokenHash, $tokenHash)) {
                return $session;
            }
        }

        return null;
    }

    public function recoverStalePendingMessage(
        int $sessionId,
        string $idempotencyKeyHash,
        DateTimeImmutable $staleBefore,
        DateTimeImmutable $now,
    ): bool {
        foreach ($this->messages as $id => $message) {
            if ($message->sessionId !== $sessionId
                || $message->role !== ChatbotMessageRole::User
                || $message->status !== ChatbotMessageStatus::Pending
                || $message->idempotencyKeyHash !== $idempotencyKeyHash
                || $this->date($message->createdAt) > $staleBefore
                || $this->assistantFor($message->id) !== null) {
                continue;
            }

            $this->messages[$id] = $this->copyMessageStatus($message, ChatbotMessageStatus::Completed, $now);
            $assistant = new ChatbotMessage(
                $this->nextMessageId++, $sessionId, $message->id, ChatbotMessageRole::Assistant,
                ChatbotMessageStatus::Failed, null, null, null, $message->requestId, null, null, null,
                0, 0, 0, 0, null, null, 'stale_message_recovered',
                $this->format($now), $this->format($now),
            );
            $this->messages[$assistant->id] = $assistant;

            return true;
        }

        return false;
    }

    public function reserveUserMessage(
        int $sessionId,
        string $idempotencyKeyHash,
        string $content,
        string $contentHash,
        string $requestId,
        DateTimeImmutable $now,
    ): ChatbotMessageReservation {
        $session = $this->requireSession($sessionId);

        foreach ($this->messages as $message) {
            if ($message->sessionId !== $sessionId || $message->idempotencyKeyHash !== $idempotencyKeyHash) {
                continue;
            }

            if ($message->contentHash === null || !hash_equals($message->contentHash, $contentHash)) {
                throw new ChatbotIdempotencyConflictException('The key was used for different content.');
            }

            $assistant = $this->assistantFor($message->id);

            return new ChatbotMessageReservation(
                $assistant === null ? ChatbotMessageReservationState::InProgress : ChatbotMessageReservationState::Replay,
                $session,
                $message,
                $assistant,
            );
        }

        if ($session->status !== ChatbotSessionStatus::Active) {
            throw new ChatbotSessionUnavailableException('The session is no longer active.');
        }

        if ($this->date($session->idleExpiresAt) <= $now || $this->date($session->absoluteExpiresAt) <= $now) {
            $this->transitionStatus($sessionId, ChatbotSessionStatus::Expired, $now);
            throw new ChatbotSessionUnavailableException('The session has expired.');
        }

        if ($session->messageCount >= $session->maximumMessages) {
            throw new ChatbotMessageLimitException('The session message limit has been reached.');
        }

        foreach ($this->messages as $message) {
            if ($message->sessionId === $sessionId
                && $message->role === ChatbotMessageRole::User
                && $message->requestId === $requestId) {
                throw new ChatbotIdempotencyConflictException('The request ID was already used.');
            }
        }

        $message = new ChatbotMessage(
            $this->nextMessageId++, $sessionId, null, ChatbotMessageRole::User, ChatbotMessageStatus::Pending,
            $content, $contentHash, $idempotencyKeyHash, $requestId, null, null, null,
            0, 0, 0, 0, null, null, null, $this->format($now), null,
        );
        $this->messages[$message->id] = $message;
        $this->sessions[$sessionId] = $this->copySessionActivity($session, $session->messageCount + 1, $now);

        return new ChatbotMessageReservation(
            ChatbotMessageReservationState::Reserved,
            $this->sessions[$sessionId],
            $message,
            null,
        );
    }

    public function completeMessage(
        int $sessionId,
        int $userMessageId,
        ChatbotMessageCompletion $completion,
        DateTimeImmutable $now,
    ): ChatbotMessage {
        $user = $this->requirePendingUser($sessionId, $userMessageId);
        $this->messages[$userMessageId] = $this->copyMessageStatus($user, ChatbotMessageStatus::Completed, $now);
        $assistant = new ChatbotMessage(
            $this->nextMessageId++, $sessionId, $userMessageId, ChatbotMessageRole::Assistant,
            ChatbotMessageStatus::Completed, $completion->content, hash('sha256', $completion->content), null,
            $user->requestId, $completion->provider, $completion->model, $completion->latencyMs,
            $completion->inputTokens, $completion->outputTokens, $completion->embeddingTokens,
            $completion->providerTokens, $completion->retrieval, $completion->citations, null,
            $this->format($now), $this->format($now),
        );
        $this->messages[$assistant->id] = $assistant;
        $session = $this->requireSession($sessionId);
        $session = $this->copySessionActivity($session, $session->messageCount, $now);
        $this->sessions[$sessionId] = $this->copySession($session,
            inputTokens: $session->inputTokens + $completion->inputTokens,
            outputTokens: $session->outputTokens + $completion->outputTokens,
            embeddingTokens: $session->embeddingTokens + $completion->embeddingTokens,
            providerTokens: $session->providerTokens + $completion->providerTokens,
        );

        return $assistant;
    }

    public function failMessage(
        int $sessionId,
        int $userMessageId,
        string $errorCode,
        int $providerTokens,
        DateTimeImmutable $now,
    ): ChatbotMessage {
        $user = $this->requirePendingUser($sessionId, $userMessageId);
        $this->messages[$userMessageId] = $this->copyMessageStatus($user, ChatbotMessageStatus::Completed, $now);
        $assistant = new ChatbotMessage(
            $this->nextMessageId++, $sessionId, $userMessageId, ChatbotMessageRole::Assistant,
            ChatbotMessageStatus::Failed, null, null, null, $user->requestId, null, null, null,
            0, 0, 0, $providerTokens, null, null, $errorCode, $this->format($now), $this->format($now),
        );
        $this->messages[$assistant->id] = $assistant;
        $session = $this->copySessionActivity($this->requireSession($sessionId), $this->requireSession($sessionId)->messageCount, $now);
        $this->sessions[$sessionId] = $this->copySession($session, providerTokens: $session->providerTokens + $providerTokens);

        return $assistant;
    }

    public function transitionStatus(int $sessionId, ChatbotSessionStatus $status, DateTimeImmutable $now): ChatbotSession
    {
        if ($status === ChatbotSessionStatus::Active) {
            throw new \InvalidArgumentException('A terminal session cannot be reactivated.');
        }

        $session = $this->requireSession($sessionId);

        if ($session->status !== ChatbotSessionStatus::Active) {
            return $session;
        }

        $purgeAt = $session->retentionDays === 0
            ? $now
            : $this->date($session->lastActivityAt)->add(new DateInterval('P' . $session->retentionDays . 'D'));

        return $this->sessions[$sessionId] = $this->copySession(
            $session,
            status: $status,
            completedAt: $this->format($now),
            purgeEligibleAt: $this->format($purgeAt),
        );
    }

    public function expireDue(DateTimeImmutable $now, int $limit): int
    {
        $count = 0;

        foreach ($this->sessions as $session) {
            if ($count >= max(1, min($limit, 500))) {
                break;
            }

            if ($session->status === ChatbotSessionStatus::Active
                && ($this->date($session->idleExpiresAt) <= $now || $this->date($session->absoluteExpiresAt) <= $now)) {
                $this->transitionStatus($session->id, ChatbotSessionStatus::Expired, $now);
                ++$count;
            }
        }

        return $count;
    }

    public function purgeEligible(DateTimeImmutable $now, int $limit): int
    {
        $count = 0;

        foreach ($this->sessions as $session) {
            if ($count >= max(1, min($limit, 500))) {
                break;
            }

            if ($session->purgeEligibleAt !== null && $this->date($session->purgeEligibleAt) <= $now) {
                $this->permanentlyDelete($session->id);
                ++$count;
            }
        }

        return $count;
    }

    public function permanentlyDelete(int $sessionId): void
    {
        unset($this->sessions[$sessionId]);
        unset($this->previewConfigurations[$sessionId]);

        foreach ($this->messages as $id => $message) {
            if ($message->sessionId === $sessionId) {
                unset($this->messages[$id]);
            }
        }
    }

    public function messagesForSession(int $sessionId): array
    {
        return array_values(array_filter(
            $this->messages,
            static fn (ChatbotMessage $message): bool => $message->sessionId === $sessionId,
        ));
    }

    public function paginateSessions(ChatbotConversationListQuery $query): PaginatedResult
    {
        return new PaginatedResult([], 0, $query->pagination->clampToTotal(0));
    }

    public function purgeSnapshot(ChatbotConversationListQuery $query, DateTimeImmutable $now): ChatbotConversationPurgeSnapshot
    {
        return new ChatbotConversationPurgeSnapshot($query, 0, null, $this->format($now));
    }

    public function purgeSnapshotBatch(ChatbotConversationPurgeSnapshot $snapshot, DateTimeImmutable $now, int $limit): int
    {
        return 0;
    }

    public function analytics(ChatbotAnalyticsQuery $query): ChatbotAnalytics
    {
        $sessions = array_filter($this->sessions, static function (ChatbotSession $session) use ($query): bool {
            return $session->lastActivityAt >= $query->fromUtc && $session->lastActivityAt < $query->beforeUtc
                && ($query->chatbotId === null || $session->chatbotId === $query->chatbotId)
                && ($query->isTest === null || $session->isTest === $query->isTest);
        });
        return new ChatbotAnalytics(
            count($sessions), count(array_filter($sessions, static fn (ChatbotSession $s): bool => !$s->isTest)),
            count(array_filter($sessions, static fn (ChatbotSession $s): bool => $s->isTest)),
            count(array_filter($sessions, static fn (ChatbotSession $s): bool => $s->status === ChatbotSessionStatus::Active)),
            array_sum(array_map(static fn (ChatbotSession $s): int => $s->messageCount, $sessions)),
            array_sum(array_map(static fn (ChatbotSession $s): int => $s->providerTokens, $sessions)), 0,
            count(array_unique(array_map(static fn (ChatbotSession $s): int => $s->chatbotId, $sessions))), [], [],
        );
    }

    private function requireSession(int $id): ChatbotSession
    {
        return $this->sessions[$id] ?? throw new ChatbotSessionUnavailableException('The session does not exist.');
    }

    private function requirePendingUser(int $sessionId, int $messageId): ChatbotMessage
    {
        $message = $this->messages[$messageId] ?? null;

        if (!$message instanceof ChatbotMessage || $message->sessionId !== $sessionId
            || $message->role !== ChatbotMessageRole::User || $message->status !== ChatbotMessageStatus::Pending) {
            throw new ChatbotIdempotencyConflictException('The reservation is no longer pending.');
        }

        return $message;
    }

    private function assistantFor(int $messageId): ?ChatbotMessage
    {
        foreach ($this->messages as $message) {
            if ($message->replyToMessageId === $messageId) {
                return $message;
            }
        }

        return null;
    }

    private function copySessionActivity(ChatbotSession $session, int $messageCount, DateTimeImmutable $now): ChatbotSession
    {
        $candidate = $now->add(new DateInterval('PT' . $session->idleTimeoutMinutes . 'M'));
        $absolute = $this->date($session->absoluteExpiresAt);

        return $this->copySession(
            $session,
            messageCount: $messageCount,
            lastActivityAt: $this->format($now),
            idleExpiresAt: $this->format($candidate < $absolute ? $candidate : $absolute),
        );
    }

    private function copySession(
        ChatbotSession $session,
        ?ChatbotSessionStatus $status = null,
        ?int $messageCount = null,
        ?int $inputTokens = null,
        ?int $outputTokens = null,
        ?int $embeddingTokens = null,
        ?int $providerTokens = null,
        ?string $lastActivityAt = null,
        ?string $idleExpiresAt = null,
        ?string $completedAt = null,
        ?string $purgeEligibleAt = null,
    ): ChatbotSession {
        return new ChatbotSession(
            $session->id, $session->publicId, $session->tokenPrefix, $session->tokenHash,
            $session->chatbotId, $session->publicationId, $session->channel, $session->normalizedOrigin,
            $session->isTest, $status ?? $session->status, $messageCount ?? $session->messageCount,
            $session->maximumMessages, $session->maximumMessageCharacters, $session->idleTimeoutMinutes,
            $session->retentionDays, $inputTokens ?? $session->inputTokens, $outputTokens ?? $session->outputTokens,
            $embeddingTokens ?? $session->embeddingTokens, $providerTokens ?? $session->providerTokens,
            $session->startedAt, $lastActivityAt ?? $session->lastActivityAt,
            $idleExpiresAt ?? $session->idleExpiresAt, $session->absoluteExpiresAt,
            $completedAt ?? $session->completedAt, $purgeEligibleAt ?? $session->purgeEligibleAt,
            $session->previewDraftRevision,
        );
    }

    private function copyMessageStatus(
        ChatbotMessage $message,
        ChatbotMessageStatus $status,
        DateTimeImmutable $now,
    ): ChatbotMessage {
        return new ChatbotMessage(
            $message->id, $message->sessionId, $message->replyToMessageId, $message->role, $status,
            $message->content, $message->contentHash, $message->idempotencyKeyHash, $message->requestId,
            $message->provider, $message->model, $message->latencyMs, $message->inputTokens,
            $message->outputTokens, $message->embeddingTokens, $message->providerTokens,
            $message->retrieval, $message->citations, $message->errorCode, $message->createdAt, $this->format($now),
        );
    }

    private function format(DateTimeImmutable $date): string
    {
        return $date->format('Y-m-d H:i:s.u');
    }

    private function date(string $date): DateTimeImmutable
    {
        return new DateTimeImmutable($date);
    }
}
