<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Domain\Chatbots\ChatbotMessage;
use App\Domain\Chatbots\ChatbotMessageCompletion;
use App\Domain\Chatbots\ChatbotMessageReservation;
use App\Domain\Chatbots\ChatbotExecutionConfiguration;
use App\Domain\Chatbots\ChatbotSession;
use App\Domain\Chatbots\ChatbotSessionChannel;
use App\Domain\Chatbots\ChatbotSessionCredentials;
use App\Domain\Chatbots\ChatbotSessionStatus;
use DateTimeImmutable;
use App\Domain\Chatbots\ChatbotConversationListQuery;
use App\Domain\Chatbots\ChatbotConversationPurgeSnapshot;
use App\Domain\Chatbots\ChatbotAnalytics;
use App\Domain\Chatbots\ChatbotAnalyticsQuery;
use App\Support\Pagination\PaginatedResult;

interface ChatbotConversationRepositoryInterface
{
    public function createForActivePublication(
        int $chatbotId,
        ChatbotSessionCredentials $credentials,
        ChatbotSessionChannel $channel,
        ?string $normalizedOrigin,
        bool $isTest,
        DateTimeImmutable $now,
    ): ChatbotSession;

    public function createForDraftPreview(
        ChatbotExecutionConfiguration $configuration,
        ChatbotSessionCredentials $credentials,
        DateTimeImmutable $now,
    ): ChatbotSession;

    public function previewExecutionConfiguration(int $sessionId): ?ChatbotExecutionConfiguration;

    public function findSessionByPublicId(string $publicId): ?ChatbotSession;

    public function findSessionByTokenHash(string $tokenHash): ?ChatbotSession;

    public function recoverStalePendingMessage(
        int $sessionId,
        string $idempotencyKeyHash,
        DateTimeImmutable $staleBefore,
        DateTimeImmutable $now,
    ): bool;

    public function reserveUserMessage(
        int $sessionId,
        string $idempotencyKeyHash,
        string $content,
        string $contentHash,
        string $requestId,
        DateTimeImmutable $now,
    ): ChatbotMessageReservation;

    public function completeMessage(
        int $sessionId,
        int $userMessageId,
        ChatbotMessageCompletion $completion,
        DateTimeImmutable $now,
    ): ChatbotMessage;

    public function failMessage(
        int $sessionId,
        int $userMessageId,
        string $errorCode,
        int $providerTokens,
        DateTimeImmutable $now,
    ): ChatbotMessage;

    public function transitionStatus(
        int $sessionId,
        ChatbotSessionStatus $status,
        DateTimeImmutable $now,
    ): ChatbotSession;

    public function expireDue(DateTimeImmutable $now, int $limit): int;

    public function purgeEligible(DateTimeImmutable $now, int $limit): int;

    public function permanentlyDelete(int $sessionId): void;

    /** @return PaginatedResult<\App\Domain\Chatbots\ChatbotConversationListItem> */
    public function paginateSessions(ChatbotConversationListQuery $query): PaginatedResult;

    public function purgeSnapshot(ChatbotConversationListQuery $query, DateTimeImmutable $now): ChatbotConversationPurgeSnapshot;

    public function purgeSnapshotBatch(ChatbotConversationPurgeSnapshot $snapshot, DateTimeImmutable $now, int $limit): int;

    public function analytics(ChatbotAnalyticsQuery $query): ChatbotAnalytics;

    /** @return list<ChatbotMessage> */
    public function messagesForSession(int $sessionId): array;
}
