<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\Connection;
use App\Domain\Chatbots\ChatbotMessage;
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
use DateInterval;
use DateTimeImmutable;
use PDO;
use RuntimeException;
use Throwable;

final class PdoChatbotConversationRepository implements ChatbotConversationRepositoryInterface
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function createForActivePublication(
        int $chatbotId,
        ChatbotSessionCredentials $credentials,
        ChatbotSessionChannel $channel,
        ?string $normalizedOrigin,
        bool $isTest,
        DateTimeImmutable $now,
    ): ChatbotSession {
        return $this->transaction(function (PDO $pdo) use (
            $chatbotId,
            $credentials,
            $channel,
            $normalizedOrigin,
            $isTest,
            $now,
        ): ChatbotSession {
            $publication = $pdo->prepare(<<<'SQL'
                SELECT c.status AS chatbot_status, c.active_publication_id,
                       cp.maximum_messages_per_session, cp.maximum_message_characters,
                       cp.idle_expiry_minutes, cp.absolute_expiry_minutes, cp.retention_days
                FROM chatbots c
                INNER JOIN chatbot_publications cp ON cp.id = c.active_publication_id
                WHERE c.id = :chatbot_id
                FOR UPDATE
                SQL);
            $publication->execute(['chatbot_id' => $chatbotId]);
            $row = $publication->fetch();

            if (!is_array($row)) {
                throw new ChatbotSessionUnavailableException('The chatbot has no active publication.');
            }

            if ($row['chatbot_status'] === 'archived'
                || ($channel !== ChatbotSessionChannel::AdminPreview && $row['chatbot_status'] !== 'active')) {
                throw new ChatbotSessionUnavailableException('The chatbot is not available for this session channel.');
            }

            if ($channel === ChatbotSessionChannel::Browser) {
                $origin = $pdo->prepare(
                    'SELECT 1 FROM chatbot_publication_origins
                     WHERE publication_id = :publication_id AND normalized_origin = :origin',
                );
                $origin->execute([
                    'publication_id' => $row['active_publication_id'],
                    'origin' => $normalizedOrigin,
                ]);

                if ($origin->fetchColumn() === false) {
                    throw new ChatbotSessionUnavailableException('The browser origin is not allowed by the active publication.');
                }
            }

            $absoluteExpiresAt = $now->add(new DateInterval('PT' . (int) $row['absolute_expiry_minutes'] . 'M'));
            $idleExpiresAt = $now->add(new DateInterval('PT' . (int) $row['idle_expiry_minutes'] . 'M'));
            $statement = $pdo->prepare(<<<'SQL'
                INSERT INTO chatbot_sessions (
                    public_id, token_prefix, token_hash, chatbot_id, chatbot_publication_id,
                    channel, normalized_origin, is_test, status, message_count,
                    maximum_messages, maximum_message_characters, idle_timeout_minutes,
                    retention_days, input_tokens, output_tokens, embedding_tokens, provider_tokens,
                    started_at, last_activity_at, idle_expires_at, absolute_expires_at,
                    created_at, updated_at
                ) VALUES (
                    :public_id, :token_prefix, :token_hash, :chatbot_id, :publication_id,
                    :channel, :origin, :is_test, 'active', 0,
                    :maximum_messages, :maximum_characters, :idle_timeout_minutes,
                    :retention_days, 0, 0, 0, 0,
                    :started_at, :last_activity_at, :idle_expires_at, :absolute_expires_at,
                    :created_at, :updated_at
                )
                SQL);
            $timestamp = $this->format($now);
            $statement->execute([
                'public_id' => $credentials->publicId,
                'token_prefix' => $credentials->tokenPrefix,
                'token_hash' => $credentials->tokenHash,
                'chatbot_id' => $chatbotId,
                'publication_id' => $row['active_publication_id'],
                'channel' => $channel->value,
                'origin' => $normalizedOrigin,
                'is_test' => $isTest ? 1 : 0,
                'maximum_messages' => $row['maximum_messages_per_session'],
                'maximum_characters' => $row['maximum_message_characters'],
                'idle_timeout_minutes' => $row['idle_expiry_minutes'],
                'retention_days' => $row['retention_days'],
                'started_at' => $timestamp,
                'last_activity_at' => $timestamp,
                'idle_expires_at' => $this->format($idleExpiresAt),
                'absolute_expires_at' => $this->format($absoluteExpiresAt),
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);

            return $this->requireSession($pdo, (int) $pdo->lastInsertId());
        });
    }

    public function findSessionByPublicId(string $publicId): ?ChatbotSession
    {
        return $this->findSession('public_id = :value', $publicId);
    }

    public function findSessionByTokenHash(string $tokenHash): ?ChatbotSession
    {
        return $this->findSession('token_hash = :value', $tokenHash);
    }

    public function reserveUserMessage(
        int $sessionId,
        string $idempotencyKeyHash,
        string $content,
        string $contentHash,
        string $requestId,
        DateTimeImmutable $now,
    ): ChatbotMessageReservation {
        $expired = false;
        $reservation = $this->transaction(function (PDO $pdo) use (
            $sessionId,
            $idempotencyKeyHash,
            $content,
            $contentHash,
            $requestId,
            $now,
            &$expired,
        ): ?ChatbotMessageReservation {
            $sessionRow = $this->lockSessionRow($pdo, $sessionId);
            $existing = $this->messageByIdempotency($pdo, $sessionId, $idempotencyKeyHash);

            if ($existing instanceof ChatbotMessage) {
                if (!is_string($existing->contentHash) || !hash_equals($existing->contentHash, $contentHash)) {
                    throw new ChatbotIdempotencyConflictException(
                        'The idempotency key was already used for different message content.',
                    );
                }

                $assistant = $this->assistantForUserMessage($pdo, $existing->id);

                return new ChatbotMessageReservation(
                    $assistant instanceof ChatbotMessage
                        ? ChatbotMessageReservationState::Replay
                        : ChatbotMessageReservationState::InProgress,
                    $this->hydrateSession($sessionRow),
                    $existing,
                    $assistant,
                );
            }

            if ($sessionRow['status'] !== ChatbotSessionStatus::Active->value) {
                throw new ChatbotSessionUnavailableException('The chatbot session is no longer active.');
            }

            if ($this->date((string) $sessionRow['idle_expires_at']) <= $now
                || $this->date((string) $sessionRow['absolute_expires_at']) <= $now) {
                $this->markTerminal($pdo, $sessionRow, ChatbotSessionStatus::Expired, $now);
                $expired = true;

                return null;
            }

            if ((int) $sessionRow['message_count'] >= (int) $sessionRow['maximum_messages']) {
                throw new ChatbotMessageLimitException('The chatbot session message limit has been reached.');
            }

            $request = $pdo->prepare(
                "SELECT id FROM chatbot_messages
                 WHERE session_id = :session_id AND role = 'user' AND request_id = :request_id",
            );
            $request->execute(['session_id' => $sessionId, 'request_id' => $requestId]);

            if ($request->fetchColumn() !== false) {
                throw new ChatbotIdempotencyConflictException('The request ID was already used in this session.');
            }

            $createdAt = $this->format($now);
            $insert = $pdo->prepare(<<<'SQL'
                INSERT INTO chatbot_messages (
                    session_id, reply_to_message_id, role, status, content, content_hash,
                    idempotency_key_hash, request_id, input_tokens, output_tokens,
                    embedding_tokens, provider_tokens, created_at
                ) VALUES (
                    :session_id, NULL, 'user', 'pending', :content, :content_hash,
                    :idempotency_key_hash, :request_id, 0, 0, 0, 0, :created_at
                )
                SQL);
            $insert->execute([
                'session_id' => $sessionId,
                'content' => $content,
                'content_hash' => $contentHash,
                'idempotency_key_hash' => $idempotencyKeyHash,
                'request_id' => $requestId,
                'created_at' => $createdAt,
            ]);
            $messageId = (int) $pdo->lastInsertId();
            $idleExpiresAt = $this->nextIdleExpiry($sessionRow, $now);
            $update = $pdo->prepare(<<<'SQL'
                UPDATE chatbot_sessions
                SET message_count = message_count + 1,
                    last_activity_at = :last_activity_at,
                    idle_expires_at = :idle_expires_at,
                    updated_at = :updated_at
                WHERE id = :id
                SQL);
            $update->execute([
                'id' => $sessionId,
                'last_activity_at' => $createdAt,
                'idle_expires_at' => $this->format($idleExpiresAt),
                'updated_at' => $createdAt,
            ]);

            return new ChatbotMessageReservation(
                ChatbotMessageReservationState::Reserved,
                $this->requireSession($pdo, $sessionId),
                $this->requireMessage($pdo, $messageId),
                null,
            );
        });

        if ($expired || !$reservation instanceof ChatbotMessageReservation) {
            throw new ChatbotSessionUnavailableException('The chatbot session has expired.');
        }

        return $reservation;
    }

    public function completeMessage(
        int $sessionId,
        int $userMessageId,
        ChatbotMessageCompletion $completion,
        DateTimeImmutable $now,
    ): ChatbotMessage {
        return $this->transaction(function (PDO $pdo) use (
            $sessionId,
            $userMessageId,
            $completion,
            $now,
        ): ChatbotMessage {
            $sessionRow = $this->lockSessionRow($pdo, $sessionId);
            $user = $this->lockPendingUserMessage($pdo, $sessionId, $userMessageId);
            $timestamp = $this->format($now);
            $pdo->prepare(
                "UPDATE chatbot_messages SET status = 'completed', completed_at = :completed_at WHERE id = :id",
            )->execute(['completed_at' => $timestamp, 'id' => $userMessageId]);
            $insert = $pdo->prepare(<<<'SQL'
                INSERT INTO chatbot_messages (
                    session_id, reply_to_message_id, role, status, content, content_hash,
                    idempotency_key_hash, request_id, provider, model, latency_ms,
                    input_tokens, output_tokens, embedding_tokens, provider_tokens,
                    retrieval_json, citations_json, created_at, completed_at
                ) VALUES (
                    :session_id, :reply_to_message_id, 'assistant', 'completed', :content, :content_hash,
                    NULL, :request_id, :provider, :model, :latency_ms,
                    :input_tokens, :output_tokens, :embedding_tokens, :provider_tokens,
                    :retrieval_json, :citations_json, :created_at, :completed_at
                )
                SQL);
            $insert->execute([
                'session_id' => $sessionId,
                'reply_to_message_id' => $userMessageId,
                'content' => $completion->content,
                'content_hash' => hash('sha256', $completion->content),
                'request_id' => $user['request_id'],
                'provider' => $completion->provider,
                'model' => $completion->model,
                'latency_ms' => $completion->latencyMs,
                'input_tokens' => $completion->inputTokens,
                'output_tokens' => $completion->outputTokens,
                'embedding_tokens' => $completion->embeddingTokens,
                'provider_tokens' => $completion->providerTokens,
                'retrieval_json' => $this->encodeJson($completion->retrieval),
                'citations_json' => $this->encodeJson($completion->citations),
                'created_at' => $timestamp,
                'completed_at' => $timestamp,
            ]);
            $messageId = (int) $pdo->lastInsertId();
            $this->updateSessionUsage($pdo, $sessionRow, $completion, $now);

            return $this->requireMessage($pdo, $messageId);
        });
    }

    public function failMessage(
        int $sessionId,
        int $userMessageId,
        string $errorCode,
        int $providerTokens,
        DateTimeImmutable $now,
    ): ChatbotMessage {
        return $this->transaction(function (PDO $pdo) use (
            $sessionId,
            $userMessageId,
            $errorCode,
            $providerTokens,
            $now,
        ): ChatbotMessage {
            $sessionRow = $this->lockSessionRow($pdo, $sessionId);
            $user = $this->lockPendingUserMessage($pdo, $sessionId, $userMessageId);
            $timestamp = $this->format($now);
            $pdo->prepare(
                "UPDATE chatbot_messages SET status = 'completed', completed_at = :completed_at WHERE id = :id",
            )->execute(['completed_at' => $timestamp, 'id' => $userMessageId]);
            $insert = $pdo->prepare(<<<'SQL'
                INSERT INTO chatbot_messages (
                    session_id, reply_to_message_id, role, status, content, content_hash,
                    idempotency_key_hash, request_id, input_tokens, output_tokens,
                    embedding_tokens, provider_tokens, error_code, created_at, completed_at
                ) VALUES (
                    :session_id, :reply_to_message_id, 'assistant', 'failed', NULL, NULL,
                    NULL, :request_id, 0, 0, 0, :provider_tokens, :error_code, :created_at, :completed_at
                )
                SQL);
            $insert->execute([
                'session_id' => $sessionId,
                'reply_to_message_id' => $userMessageId,
                'request_id' => $user['request_id'],
                'provider_tokens' => $providerTokens,
                'error_code' => $errorCode,
                'created_at' => $timestamp,
                'completed_at' => $timestamp,
            ]);
            $messageId = (int) $pdo->lastInsertId();
            $update = $pdo->prepare(<<<'SQL'
                UPDATE chatbot_sessions
                SET provider_tokens = provider_tokens + :provider_tokens,
                    last_activity_at = :last_activity_at,
                    idle_expires_at = :idle_expires_at,
                    updated_at = :updated_at
                WHERE id = :id
                SQL);
            $update->execute([
                'provider_tokens' => $providerTokens,
                'last_activity_at' => $timestamp,
                'idle_expires_at' => $this->format($this->nextIdleExpiry($sessionRow, $now)),
                'updated_at' => $timestamp,
                'id' => $sessionId,
            ]);

            return $this->requireMessage($pdo, $messageId);
        });
    }

    public function transitionStatus(
        int $sessionId,
        ChatbotSessionStatus $status,
        DateTimeImmutable $now,
    ): ChatbotSession {
        if ($status === ChatbotSessionStatus::Active) {
            throw new \InvalidArgumentException('A terminal chatbot session cannot be reactivated.');
        }

        return $this->transaction(function (PDO $pdo) use ($sessionId, $status, $now): ChatbotSession {
            $row = $this->lockSessionRow($pdo, $sessionId);

            if ($row['status'] === ChatbotSessionStatus::Active->value) {
                $this->markTerminal($pdo, $row, $status, $now);
            }

            return $this->requireSession($pdo, $sessionId);
        });
    }

    public function expireDue(DateTimeImmutable $now, int $limit): int
    {
        $limit = max(1, min($limit, 500));

        return $this->transaction(function (PDO $pdo) use ($now, $limit): int {
            $statement = $pdo->prepare(
                "SELECT * FROM chatbot_sessions
                 WHERE status = 'active' AND (idle_expires_at <= :idle_now OR absolute_expires_at <= :absolute_now)
                 ORDER BY LEAST(idle_expires_at, absolute_expires_at), id
                 LIMIT $limit FOR UPDATE SKIP LOCKED",
            );
            $formatted = $this->format($now);
            $statement->execute(['idle_now' => $formatted, 'absolute_now' => $formatted]);
            $rows = $statement->fetchAll();

            foreach ($rows as $row) {
                $this->markTerminal($pdo, $row, ChatbotSessionStatus::Expired, $now);
            }

            return count($rows);
        });
    }

    public function purgeEligible(DateTimeImmutable $now, int $limit): int
    {
        $limit = max(1, min($limit, 500));

        return $this->transaction(function (PDO $pdo) use ($now, $limit): int {
            $statement = $pdo->prepare(
                "SELECT id FROM chatbot_sessions
                 WHERE purge_eligible_at IS NOT NULL AND purge_eligible_at <= :now
                 ORDER BY purge_eligible_at, id LIMIT $limit FOR UPDATE SKIP LOCKED",
            );
            $statement->execute(['now' => $this->format($now)]);
            $ids = array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));

            if ($ids === []) {
                return 0;
            }

            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $delete = $pdo->prepare("DELETE FROM chatbot_sessions WHERE id IN ($placeholders)");
            $delete->execute($ids);

            return $delete->rowCount();
        });
    }

    public function permanentlyDelete(int $sessionId): void
    {
        $statement = $this->connection->pdo()->prepare('DELETE FROM chatbot_sessions WHERE id = :id');
        $statement->execute(['id' => $sessionId]);
    }

    public function messagesForSession(int $sessionId): array
    {
        $statement = $this->connection->pdo()->prepare(
            'SELECT * FROM chatbot_messages WHERE session_id = :session_id ORDER BY created_at, id',
        );
        $statement->execute(['session_id' => $sessionId]);

        return array_map($this->hydrateMessage(...), $statement->fetchAll());
    }

    private function findSession(string $condition, string $value): ?ChatbotSession
    {
        $statement = $this->connection->pdo()->prepare('SELECT * FROM chatbot_sessions WHERE ' . $condition . ' LIMIT 1');
        $statement->execute(['value' => $value]);
        $row = $statement->fetch();

        return is_array($row) ? $this->hydrateSession($row) : null;
    }

    /** @return array<string, mixed> */
    private function lockSessionRow(PDO $pdo, int $sessionId): array
    {
        $statement = $pdo->prepare('SELECT * FROM chatbot_sessions WHERE id = :id FOR UPDATE');
        $statement->execute(['id' => $sessionId]);
        $row = $statement->fetch();

        if (!is_array($row)) {
            throw new ChatbotSessionUnavailableException('The chatbot session does not exist.');
        }

        return $row;
    }

    /** @return array<string, mixed> */
    private function lockPendingUserMessage(PDO $pdo, int $sessionId, int $messageId): array
    {
        $statement = $pdo->prepare(
            "SELECT * FROM chatbot_messages
             WHERE id = :id AND session_id = :session_id AND role = 'user' FOR UPDATE",
        );
        $statement->execute(['id' => $messageId, 'session_id' => $sessionId]);
        $row = $statement->fetch();

        if (!is_array($row) || $row['status'] !== ChatbotMessageStatus::Pending->value) {
            throw new ChatbotIdempotencyConflictException('The chatbot message reservation is no longer pending.');
        }

        return $row;
    }

    private function messageByIdempotency(PDO $pdo, int $sessionId, string $hash): ?ChatbotMessage
    {
        $statement = $pdo->prepare(
            'SELECT * FROM chatbot_messages
             WHERE session_id = :session_id AND idempotency_key_hash = :hash LIMIT 1',
        );
        $statement->execute(['session_id' => $sessionId, 'hash' => $hash]);
        $row = $statement->fetch();

        return is_array($row) ? $this->hydrateMessage($row) : null;
    }

    private function assistantForUserMessage(PDO $pdo, int $messageId): ?ChatbotMessage
    {
        $statement = $pdo->prepare(
            "SELECT * FROM chatbot_messages
             WHERE reply_to_message_id = :message_id AND role = 'assistant' LIMIT 1",
        );
        $statement->execute(['message_id' => $messageId]);
        $row = $statement->fetch();

        return is_array($row) ? $this->hydrateMessage($row) : null;
    }

    /** @param array<string, mixed> $sessionRow */
    private function updateSessionUsage(
        PDO $pdo,
        array $sessionRow,
        ChatbotMessageCompletion $completion,
        DateTimeImmutable $now,
    ): void {
        $timestamp = $this->format($now);
        $statement = $pdo->prepare(<<<'SQL'
            UPDATE chatbot_sessions
            SET input_tokens = input_tokens + :input_tokens,
                output_tokens = output_tokens + :output_tokens,
                embedding_tokens = embedding_tokens + :embedding_tokens,
                provider_tokens = provider_tokens + :provider_tokens,
                last_activity_at = :last_activity_at,
                idle_expires_at = :idle_expires_at,
                updated_at = :updated_at
            WHERE id = :id
            SQL);
        $statement->execute([
            'input_tokens' => $completion->inputTokens,
            'output_tokens' => $completion->outputTokens,
            'embedding_tokens' => $completion->embeddingTokens,
            'provider_tokens' => $completion->providerTokens,
            'last_activity_at' => $timestamp,
            'idle_expires_at' => $this->format($this->nextIdleExpiry($sessionRow, $now)),
            'updated_at' => $timestamp,
            'id' => $sessionRow['id'],
        ]);
    }

    /** @param array<string, mixed> $row */
    private function nextIdleExpiry(array $row, DateTimeImmutable $now): DateTimeImmutable
    {
        $candidate = $now->add(new DateInterval('PT' . (int) $row['idle_timeout_minutes'] . 'M'));
        $absolute = $this->date((string) $row['absolute_expires_at']);

        return $candidate < $absolute ? $candidate : $absolute;
    }

    /** @param array<string, mixed> $row */
    private function markTerminal(
        PDO $pdo,
        array $row,
        ChatbotSessionStatus $status,
        DateTimeImmutable $now,
    ): void {
        $completedAt = $this->format($now);
        $purgeEligibleAt = (int) $row['retention_days'] === 0
            ? $now
            : $this->date((string) $row['last_activity_at'])->add(
                new DateInterval('P' . (int) $row['retention_days'] . 'D'),
            );
        $statement = $pdo->prepare(
            'UPDATE chatbot_sessions
             SET status = :status, completed_at = :completed_at,
                 purge_eligible_at = :purge_eligible_at, updated_at = :updated_at
             WHERE id = :id',
        );
        $statement->execute([
            'status' => $status->value,
            'completed_at' => $completedAt,
            'purge_eligible_at' => $this->format($purgeEligibleAt),
            'updated_at' => $completedAt,
            'id' => $row['id'],
        ]);
    }

    private function requireSession(PDO $pdo, int $id): ChatbotSession
    {
        $statement = $pdo->prepare('SELECT * FROM chatbot_sessions WHERE id = :id');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        if (!is_array($row)) {
            throw new RuntimeException('The chatbot session could not be loaded.');
        }

        return $this->hydrateSession($row);
    }

    private function requireMessage(PDO $pdo, int $id): ChatbotMessage
    {
        $statement = $pdo->prepare('SELECT * FROM chatbot_messages WHERE id = :id');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        if (!is_array($row)) {
            throw new RuntimeException('The chatbot message could not be loaded.');
        }

        return $this->hydrateMessage($row);
    }

    /** @param array<string, mixed> $row */
    private function hydrateSession(array $row): ChatbotSession
    {
        return new ChatbotSession(
            (int) $row['id'],
            (string) $row['public_id'],
            (string) $row['token_prefix'],
            (string) $row['token_hash'],
            (int) $row['chatbot_id'],
            (int) $row['chatbot_publication_id'],
            ChatbotSessionChannel::from((string) $row['channel']),
            isset($row['normalized_origin']) ? (string) $row['normalized_origin'] : null,
            (bool) $row['is_test'],
            ChatbotSessionStatus::from((string) $row['status']),
            (int) $row['message_count'],
            (int) $row['maximum_messages'],
            (int) $row['maximum_message_characters'],
            (int) $row['idle_timeout_minutes'],
            (int) $row['retention_days'],
            (int) $row['input_tokens'],
            (int) $row['output_tokens'],
            (int) $row['embedding_tokens'],
            (int) $row['provider_tokens'],
            (string) $row['started_at'],
            (string) $row['last_activity_at'],
            (string) $row['idle_expires_at'],
            (string) $row['absolute_expires_at'],
            isset($row['completed_at']) ? (string) $row['completed_at'] : null,
            isset($row['purge_eligible_at']) ? (string) $row['purge_eligible_at'] : null,
        );
    }

    /** @param array<string, mixed> $row */
    private function hydrateMessage(array $row): ChatbotMessage
    {
        return new ChatbotMessage(
            (int) $row['id'],
            (int) $row['session_id'],
            isset($row['reply_to_message_id']) ? (int) $row['reply_to_message_id'] : null,
            ChatbotMessageRole::from((string) $row['role']),
            ChatbotMessageStatus::from((string) $row['status']),
            isset($row['content']) ? (string) $row['content'] : null,
            isset($row['content_hash']) ? (string) $row['content_hash'] : null,
            isset($row['idempotency_key_hash']) ? (string) $row['idempotency_key_hash'] : null,
            (string) $row['request_id'],
            isset($row['provider']) ? (string) $row['provider'] : null,
            isset($row['model']) ? (string) $row['model'] : null,
            isset($row['latency_ms']) ? (int) $row['latency_ms'] : null,
            (int) $row['input_tokens'],
            (int) $row['output_tokens'],
            (int) $row['embedding_tokens'],
            (int) $row['provider_tokens'],
            $this->decodeObject($row['retrieval_json'] ?? null),
            $this->decodeList($row['citations_json'] ?? null),
            isset($row['error_code']) ? (string) $row['error_code'] : null,
            (string) $row['created_at'],
            isset($row['completed_at']) ? (string) $row['completed_at'] : null,
        );
    }

    private function encodeJson(?array $value): ?string
    {
        return $value === null
            ? null
            : json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** @return array<string, mixed>|null */
    private function decodeObject(mixed $value): ?array
    {
        if (!is_string($value)) {
            return null;
        }

        $decoded = json_decode($value, true, flags: JSON_THROW_ON_ERROR);

        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new RuntimeException('Stored chatbot retrieval metadata must be an object.');
        }

        return $decoded;
    }

    /** @return list<array<string, mixed>>|null */
    private function decodeList(mixed $value): ?array
    {
        if (!is_string($value)) {
            return null;
        }

        $decoded = json_decode($value, true, flags: JSON_THROW_ON_ERROR);

        if (!is_array($decoded) || !array_is_list($decoded)) {
            throw new RuntimeException('Stored chatbot citation metadata must be a list.');
        }

        foreach ($decoded as $citation) {
            if (!is_array($citation) || array_is_list($citation)) {
                throw new RuntimeException('Stored chatbot citation metadata entries must be objects.');
            }
        }

        return $decoded;
    }

    private function format(DateTimeImmutable $date): string
    {
        return $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private function date(string $date): DateTimeImmutable
    {
        return new DateTimeImmutable($date, new \DateTimeZone('UTC'));
    }

    private function transaction(callable $operation): mixed
    {
        $pdo = $this->connection->pdo();
        $pdo->beginTransaction();

        try {
            $result = $operation($pdo);
            $pdo->commit();

            return $result;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $exception;
        }
    }
}
