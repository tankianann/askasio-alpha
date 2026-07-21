<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\Connection;
use App\Domain\Chatbots\Chatbot;
use App\Domain\Chatbots\ChatbotAssignments;
use App\Domain\Chatbots\ChatbotDraft;
use App\Domain\Chatbots\ChatbotListItem;
use App\Domain\Chatbots\ChatbotListQuery;
use App\Domain\Chatbots\ChatbotProviderConfiguration;
use App\Domain\Chatbots\ChatbotPublication;
use App\Domain\Chatbots\ChatbotStatus;
use App\Domain\Chatbots\ChatbotSourceDependency;
use App\Domain\Chatbots\ChatbotSourceReadiness;
use App\Domain\Chatbots\ChatbotSourceReadinessStatus;
use App\Exceptions\ChatbotPublicationReadinessException;
use App\Exceptions\InvalidChatbotSourceAssignmentException;
use App\Exceptions\StaleChatbotDraftException;
use App\Exceptions\UnchangedChatbotPublicationException;
use App\Support\Pagination\PaginatedResult;
use PDO;
use RuntimeException;
use Throwable;

final class PdoChatbotRepository implements ChatbotRepositoryInterface, SourceDependencyRepositoryInterface
{
    private readonly ChatbotListSqlQueryBuilder $listQueries;

    public function __construct(
        private readonly Connection $connection,
        ?ChatbotListSqlQueryBuilder $listQueries = null,
    ) {
        $this->listQueries = $listQueries ?? new ChatbotListSqlQueryBuilder();
    }

    public function listOptions(): array
    {
        $rows = $this->connection->pdo()->query('SELECT id, name FROM chatbots ORDER BY name ASC, id ASC')->fetchAll();

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
        ], $rows);
    }

    public function paginate(ChatbotListQuery $query): PaginatedResult
    {
        $pdo = $this->connection->pdo();
        $where = $this->listQueries->where($query);
        $from = $this->listFrom();
        $count = $pdo->prepare('SELECT COUNT(*)' . $from . $where['sql']);
        $count->execute($where['parameters']);
        $total = (int) $count->fetchColumn();
        $page = $query->pagination->clampToTotal($total);
        $statement = $pdo->prepare(
            $this->listSelect()
            . $where['sql']
            . $this->listQueries->orderBy($query)
            . ' LIMIT ' . $page->perPage
            . ' OFFSET ' . $page->offset(),
        );
        $statement->execute($where['parameters']);

        return new PaginatedResult(
            array_map($this->hydrateListItem(...), $statement->fetchAll()),
            $total,
            $page,
        );
    }

    public function findById(int $id): ?Chatbot
    {
        return $this->find('c.id = :identifier', ['identifier' => $id]);
    }

    public function findByPublicId(string $publicId): ?Chatbot
    {
        return $this->find('c.public_id = :identifier', ['identifier' => $publicId]);
    }

    public function findActivePublication(int $chatbotId): ?ChatbotPublication
    {
        $statement = $this->connection->pdo()->prepare(
            $this->publicationSelect()
            . ' INNER JOIN chatbots c ON c.active_publication_id = cp.id'
            . ' WHERE c.id = :chatbot_id LIMIT 1',
        );
        $statement->execute(['chatbot_id' => $chatbotId]);
        $row = $statement->fetch();

        return is_array($row) ? $this->hydratePublication($row) : null;
    }

    public function sourceReadiness(array $sourceIds, ChatbotProviderConfiguration $provider): array
    {
        return $this->resolveSourceReadiness($this->connection->pdo(), $sourceIds, $provider, false);
    }

    public function create(string $publicId, string $name, ?string $description, ChatbotDraft $draft): Chatbot
    {
        return $this->transaction(function (PDO $pdo) use ($publicId, $name, $description, $draft): Chatbot {
            $statement = $pdo->prepare(
                "INSERT INTO chatbots (public_id, name, description, status, created_at, updated_at)
                 VALUES (:public_id, :name, :description, 'active', UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))",
            );
            $statement->execute([
                'public_id' => $publicId,
                'name' => $name,
                'description' => $description,
            ]);
            $id = (int) $pdo->lastInsertId();
            $this->insertDraft($pdo, $id, $draft);
            $chatbot = $this->findById($id);

            if (!$chatbot instanceof Chatbot) {
                throw new RuntimeException('The chatbot could not be loaded after creation.');
            }

            return $chatbot;
        });
    }

    public function updateDraft(
        int $id,
        int $expectedRevision,
        string $name,
        ?string $description,
        ChatbotDraft $draft,
    ): Chatbot {
        return $this->transaction(function (PDO $pdo) use (
            $id,
            $expectedRevision,
            $name,
            $description,
            $draft,
        ): Chatbot {
            $this->lockChatbot($pdo, $id);
            $values = $this->draftValues($draft);
            $statement = $pdo->prepare(
                'UPDATE chatbot_drafts SET
                    schema_version = :schema_version,
                    revision = revision + 1,
                    system_instructions = :system_instructions,
                    fallback_message = :fallback_message,
                    retrieval_top_k = :retrieval_top_k,
                    minimum_similarity = :minimum_similarity,
                    citations_enabled = :citations_enabled,
                    maximum_message_characters = :maximum_message_characters,
                    maximum_messages_per_session = :maximum_messages_per_session,
                    idle_expiry_minutes = :idle_expiry_minutes,
                    absolute_expiry_minutes = :absolute_expiry_minutes,
                    retention_days = :retention_days,
                    privacy_notice_url = :privacy_notice_url,
                    disclosure_text = :disclosure_text,
                    presentation_json = :presentation_json,
                    appearance_json = :appearance_json,
                    updated_at = UTC_TIMESTAMP(6)
                 WHERE chatbot_id = :chatbot_id AND revision = :expected_revision',
            );
            $statement->execute([
                ...$values,
                'chatbot_id' => $id,
                'expected_revision' => $expectedRevision,
            ]);

            if ($statement->rowCount() !== 1) {
                throw new StaleChatbotDraftException('The chatbot draft was changed by another request.');
            }

            $identity = $pdo->prepare(
                'UPDATE chatbots
                 SET name = :name, description = :description, updated_at = UTC_TIMESTAMP(6)
                 WHERE id = :id',
            );
            $identity->execute(['id' => $id, 'name' => $name, 'description' => $description]);
            $chatbot = $this->findById($id);

            if (!$chatbot instanceof Chatbot) {
                throw new RuntimeException('The chatbot could not be loaded after its draft update.');
            }

            return $chatbot;
        });
    }

    public function replaceDraftAssignments(
        int $id,
        int $expectedRevision,
        ChatbotAssignments $assignments,
    ): Chatbot {
        return $this->transaction(function (PDO $pdo) use ($id, $expectedRevision, $assignments): Chatbot {
            $this->lockChatbot($pdo, $id);
            $draft = $pdo->prepare(
                'SELECT revision FROM chatbot_drafts WHERE chatbot_id = :chatbot_id FOR UPDATE',
            );
            $draft->execute(['chatbot_id' => $id]);

            if ((int) $draft->fetchColumn() !== $expectedRevision) {
                throw new StaleChatbotDraftException('The chatbot draft was changed by another request.');
            }

            $this->validateAssignableSources($pdo, $assignments->sourceIds);
            $stored = $this->draftAssignments($pdo, $id);

            if ($stored->configuration() === $assignments->configuration()) {
                return $this->requireReload($id, 'unchanged assignment update');
            }

            $pdo->prepare('DELETE FROM chatbot_draft_sources WHERE chatbot_id = :chatbot_id')
                ->execute(['chatbot_id' => $id]);
            $pdo->prepare('DELETE FROM chatbot_draft_origins WHERE chatbot_id = :chatbot_id')
                ->execute(['chatbot_id' => $id]);
            $this->insertDraftAssignments($pdo, $id, $assignments);
            $revision = $pdo->prepare(
                'UPDATE chatbot_drafts SET revision = revision + 1, updated_at = UTC_TIMESTAMP(6)
                 WHERE chatbot_id = :chatbot_id AND revision = :expected_revision',
            );
            $revision->execute(['chatbot_id' => $id, 'expected_revision' => $expectedRevision]);

            if ($revision->rowCount() !== 1) {
                throw new StaleChatbotDraftException('The chatbot draft was changed by another request.');
            }

            $pdo->prepare('UPDATE chatbots SET updated_at = UTC_TIMESTAMP(6) WHERE id = :id')
                ->execute(['id' => $id]);

            return $this->requireReload($id, 'assignment update');
        });
    }

    public function publish(
        int $id,
        int $expectedDraftRevision,
        ChatbotDraft $draft,
        ChatbotAssignments $assignments,
        ChatbotProviderConfiguration $provider,
        string $configurationHash,
    ): ChatbotPublication {
        return $this->transaction(function (PDO $pdo) use (
            $id,
            $expectedDraftRevision,
            $draft,
            $assignments,
            $provider,
            $configurationHash,
        ): ChatbotPublication {
            $this->lockChatbot($pdo, $id);
            $activeHash = $pdo->prepare(
                'SELECT cp.configuration_hash
                 FROM chatbots c
                 LEFT JOIN chatbot_publications cp ON cp.id = c.active_publication_id
                 WHERE c.id = :chatbot_id',
            );
            $activeHash->execute(['chatbot_id' => $id]);
            $storedHash = $activeHash->fetchColumn();

            if (is_string($storedHash) && hash_equals($storedHash, $configurationHash)) {
                throw new UnchangedChatbotPublicationException(
                    'The active chatbot publication already has this configuration.',
                );
            }

            $draftLock = $pdo->prepare(
                'SELECT revision FROM chatbot_drafts WHERE chatbot_id = :chatbot_id FOR UPDATE',
            );
            $draftLock->execute(['chatbot_id' => $id]);

            if ((int) $draftLock->fetchColumn() !== $expectedDraftRevision) {
                throw new StaleChatbotDraftException('The chatbot draft changed before it could be published.');
            }

            $storedAssignments = $this->draftAssignments($pdo, $id);

            if ($storedAssignments->configuration() !== $assignments->configuration()) {
                throw new StaleChatbotDraftException('The chatbot source or origin assignments changed before publication.');
            }

            if ($assignments->sourceIds === [] || $assignments->origins === []) {
                throw new InvalidChatbotSourceAssignmentException(
                    'Publishing requires at least one assigned source and one allowed origin.',
                );
            }

            $readiness = $this->resolveSourceReadiness($pdo, $assignments->sourceIds, $provider, true);

            $hasUnreadySource = false;

            foreach ($readiness as $source) {
                if (!$source->isReady()) {
                    $hasUnreadySource = true;
                    break;
                }
            }

            if ($hasUnreadySource) {
                throw new ChatbotPublicationReadinessException($readiness);
            }

            $next = $pdo->prepare(
                'SELECT COALESCE(MAX(publication_number), 0) + 1
                 FROM chatbot_publications WHERE chatbot_id = :chatbot_id',
            );
            $next->execute(['chatbot_id' => $id]);
            $publicationNumber = (int) $next->fetchColumn();
            $statement = $pdo->prepare(
                'INSERT INTO chatbot_publications (
                    chatbot_id, publication_number, source_draft_revision, schema_version,
                    configuration_hash, system_instructions, fallback_message, retrieval_top_k,
                    minimum_similarity, citations_enabled, maximum_message_characters,
                    maximum_messages_per_session, idle_expiry_minutes, absolute_expiry_minutes,
                    retention_days, privacy_notice_url, disclosure_text, presentation_json,
                    appearance_json, chat_provider, chat_model, embedding_provider, embedding_model,
                    embedding_dimensions,
                    published_at
                 ) VALUES (
                    :chatbot_id, :publication_number, :source_draft_revision, :schema_version,
                    :configuration_hash, :system_instructions, :fallback_message, :retrieval_top_k,
                    :minimum_similarity, :citations_enabled, :maximum_message_characters,
                    :maximum_messages_per_session, :idle_expiry_minutes, :absolute_expiry_minutes,
                    :retention_days, :privacy_notice_url, :disclosure_text, :presentation_json,
                    :appearance_json, :chat_provider, :chat_model, :embedding_provider, :embedding_model,
                    :embedding_dimensions,
                    UTC_TIMESTAMP(6)
                 )',
            );
            $statement->execute([
                ...$this->draftValues($draft),
                'chatbot_id' => $id,
                'publication_number' => $publicationNumber,
                'source_draft_revision' => $expectedDraftRevision,
                'configuration_hash' => $configurationHash,
                'chat_provider' => $provider->chatProvider,
                'chat_model' => $provider->chatModel,
                'embedding_provider' => $provider->embeddingProvider,
                'embedding_model' => $provider->embeddingModel,
                'embedding_dimensions' => $provider->embeddingDimensions,
            ]);
            $publicationId = (int) $pdo->lastInsertId();
            $this->insertPublicationAssignments($pdo, $publicationId, $assignments);
            $activate = $pdo->prepare(
                "UPDATE chatbots
                 SET active_publication_id = :publication_id, updated_at = UTC_TIMESTAMP(6)
                 WHERE id = :chatbot_id",
            );
            $activate->execute(['publication_id' => $publicationId, 'chatbot_id' => $id]);
            $publication = $this->findPublicationById($publicationId);

            if (!$publication instanceof ChatbotPublication) {
                throw new RuntimeException('The chatbot publication could not be loaded after creation.');
            }

            return $publication;
        });
    }

    /** @return list<ChatbotSourceDependency> */
    public function sourceDependencies(int $sourceId): array
    {
        $statement = $this->connection->pdo()->prepare(<<<'SQL'
            SELECT c.id, c.name,
                   EXISTS(
                       SELECT 1 FROM chatbot_draft_sources cds
                       WHERE cds.chatbot_id = c.id AND cds.source_id = :draft_source_id
                   ) AS in_draft,
                   EXISTS(
                       SELECT 1 FROM chatbot_publication_sources active_cps
                       WHERE active_cps.publication_id = c.active_publication_id
                         AND active_cps.source_id = :active_source_id
                   ) AS in_active_publication,
                   (
                       SELECT COUNT(*)
                       FROM chatbot_publications history_cp
                       INNER JOIN chatbot_publication_sources history_cps
                           ON history_cps.publication_id = history_cp.id
                       WHERE history_cp.chatbot_id = c.id
                         AND history_cps.source_id = :history_source_id
                   ) AS publication_count
            FROM chatbots c
            WHERE EXISTS(
                SELECT 1 FROM chatbot_draft_sources any_cds
                WHERE any_cds.chatbot_id = c.id AND any_cds.source_id = :any_draft_source_id
            ) OR EXISTS(
                SELECT 1
                FROM chatbot_publications any_cp
                INNER JOIN chatbot_publication_sources any_cps ON any_cps.publication_id = any_cp.id
                WHERE any_cp.chatbot_id = c.id AND any_cps.source_id = :any_publication_source_id
            )
            ORDER BY c.name ASC, c.id ASC
            SQL);
        $statement->execute([
            'draft_source_id' => $sourceId,
            'active_source_id' => $sourceId,
            'history_source_id' => $sourceId,
            'any_draft_source_id' => $sourceId,
            'any_publication_source_id' => $sourceId,
        ]);

        return array_map(
            static fn (array $row): ChatbotSourceDependency => new ChatbotSourceDependency(
                (int) $row['id'],
                (string) $row['name'],
                (bool) $row['in_draft'],
                (bool) $row['in_active_publication'],
                (int) $row['publication_count'],
            ),
            $statement->fetchAll(),
        );
    }

    public function rotatePublicId(int $id, string $publicId): Chatbot
    {
        $statement = $this->connection->pdo()->prepare(
            'UPDATE chatbots SET public_id = :public_id, updated_at = UTC_TIMESTAMP(6) WHERE id = :id',
        );
        $statement->execute(['id' => $id, 'public_id' => $publicId]);

        return $this->requireReload($id, 'public ID rotation');
    }

    public function setStatus(int $id, ChatbotStatus $status): Chatbot
    {
        $archivedAt = $status === ChatbotStatus::Archived ? 'UTC_TIMESTAMP(6)' : 'NULL';
        $statement = $this->connection->pdo()->prepare(
            sprintf(
                'UPDATE chatbots SET status = :status, archived_at = %s, updated_at = UTC_TIMESTAMP(6) WHERE id = :id',
                $archivedAt,
            ),
        );
        $statement->execute(['id' => $id, 'status' => $status->value]);

        return $this->requireReload($id, 'status change');
    }

    public function permanentlyDelete(int $id): void
    {
        $this->transaction(function (PDO $pdo) use ($id): void {
            $this->lockChatbot($pdo, $id);
            $clear = $pdo->prepare('UPDATE chatbots SET active_publication_id = NULL WHERE id = :id');
            $clear->execute(['id' => $id]);
            $delete = $pdo->prepare("DELETE FROM chatbots WHERE id = :id AND status = 'archived'");
            $delete->execute(['id' => $id]);

            if ($delete->rowCount() !== 1) {
                throw new RuntimeException('The archived chatbot could not be permanently deleted.');
            }
        });
    }

    /** @param array<string, int|string> $parameters */
    private function find(string $condition, array $parameters): ?Chatbot
    {
        $statement = $this->connection->pdo()->prepare($this->chatbotSelect() . ' WHERE ' . $condition . ' LIMIT 1');
        $statement->execute($parameters);
        $row = $statement->fetch();

        return is_array($row) ? $this->hydrateChatbot($row) : null;
    }

    public function findPublicationById(int $id): ?ChatbotPublication
    {
        $statement = $this->connection->pdo()->prepare($this->publicationSelect() . ' WHERE cp.id = :id LIMIT 1');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        return is_array($row) ? $this->hydratePublication($row) : null;
    }

    private function insertDraft(PDO $pdo, int $chatbotId, ChatbotDraft $draft): void
    {
        $statement = $pdo->prepare(
            'INSERT INTO chatbot_drafts (
                chatbot_id, schema_version, revision, system_instructions, fallback_message,
                retrieval_top_k, minimum_similarity, citations_enabled, maximum_message_characters,
                maximum_messages_per_session, idle_expiry_minutes, absolute_expiry_minutes,
                retention_days, privacy_notice_url, disclosure_text, presentation_json,
                appearance_json, updated_at
             ) VALUES (
                :chatbot_id, :schema_version, 1, :system_instructions, :fallback_message,
                :retrieval_top_k, :minimum_similarity, :citations_enabled, :maximum_message_characters,
                :maximum_messages_per_session, :idle_expiry_minutes, :absolute_expiry_minutes,
                :retention_days, :privacy_notice_url, :disclosure_text, :presentation_json,
                :appearance_json, UTC_TIMESTAMP(6)
             )',
        );
        $statement->execute(['chatbot_id' => $chatbotId, ...$this->draftValues($draft)]);
    }

    /** @param list<int> $sourceIds */
    private function validateAssignableSources(PDO $pdo, array $sourceIds): void
    {
        if ($sourceIds === []) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($sourceIds), '?'));
        $statement = $pdo->prepare(
            "SELECT id FROM sources WHERE id IN ($placeholders) AND deleted_at IS NULL FOR SHARE",
        );
        $statement->execute($sourceIds);
        $found = array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
        sort($found, SORT_NUMERIC);

        if ($found !== $sourceIds) {
            throw new InvalidChatbotSourceAssignmentException(
                'Draft source assignments must reference existing, non-deleted sources.',
            );
        }
    }

    private function insertDraftAssignments(PDO $pdo, int $chatbotId, ChatbotAssignments $assignments): void
    {
        $source = $pdo->prepare(
            'INSERT INTO chatbot_draft_sources (chatbot_id, source_id) VALUES (:chatbot_id, :source_id)',
        );

        foreach ($assignments->sourceIds as $sourceId) {
            $source->execute(['chatbot_id' => $chatbotId, 'source_id' => $sourceId]);
        }

        $origin = $pdo->prepare(
            'INSERT INTO chatbot_draft_origins (chatbot_id, normalized_origin)
             VALUES (:chatbot_id, :normalized_origin)',
        );

        foreach ($assignments->origins as $normalizedOrigin) {
            $origin->execute(['chatbot_id' => $chatbotId, 'normalized_origin' => $normalizedOrigin]);
        }
    }

    private function insertPublicationAssignments(
        PDO $pdo,
        int $publicationId,
        ChatbotAssignments $assignments,
    ): void {
        $source = $pdo->prepare(
            'INSERT INTO chatbot_publication_sources (publication_id, source_id)
             VALUES (:publication_id, :source_id)',
        );

        foreach ($assignments->sourceIds as $sourceId) {
            $source->execute(['publication_id' => $publicationId, 'source_id' => $sourceId]);
        }

        $origin = $pdo->prepare(
            'INSERT INTO chatbot_publication_origins (publication_id, normalized_origin)
             VALUES (:publication_id, :normalized_origin)',
        );

        foreach ($assignments->origins as $normalizedOrigin) {
            $origin->execute(['publication_id' => $publicationId, 'normalized_origin' => $normalizedOrigin]);
        }
    }

    private function draftAssignments(PDO $pdo, int $chatbotId): ChatbotAssignments
    {
        $sources = $pdo->prepare(
            'SELECT source_id FROM chatbot_draft_sources WHERE chatbot_id = :chatbot_id ORDER BY source_id',
        );
        $sources->execute(['chatbot_id' => $chatbotId]);
        $origins = $pdo->prepare(
            'SELECT normalized_origin FROM chatbot_draft_origins
             WHERE chatbot_id = :chatbot_id ORDER BY normalized_origin',
        );
        $origins->execute(['chatbot_id' => $chatbotId]);

        return new ChatbotAssignments(
            array_map('intval', $sources->fetchAll(PDO::FETCH_COLUMN)),
            array_map('strval', $origins->fetchAll(PDO::FETCH_COLUMN)),
        );
    }

    private function publicationAssignments(PDO $pdo, int $publicationId): ChatbotAssignments
    {
        $sources = $pdo->prepare(
            'SELECT source_id FROM chatbot_publication_sources
             WHERE publication_id = :publication_id ORDER BY source_id',
        );
        $sources->execute(['publication_id' => $publicationId]);
        $origins = $pdo->prepare(
            'SELECT normalized_origin FROM chatbot_publication_origins
             WHERE publication_id = :publication_id ORDER BY normalized_origin',
        );
        $origins->execute(['publication_id' => $publicationId]);

        return new ChatbotAssignments(
            array_map('intval', $sources->fetchAll(PDO::FETCH_COLUMN)),
            array_map('strval', $origins->fetchAll(PDO::FETCH_COLUMN)),
        );
    }

    /** @param list<int> $sourceIds @return list<ChatbotSourceReadiness> */
    private function resolveSourceReadiness(
        PDO $pdo,
        array $sourceIds,
        ChatbotProviderConfiguration $provider,
        bool $lock,
    ): array {
        if ($sourceIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($sourceIds), '?'));
        $lockClause = $lock ? ' FOR SHARE' : '';
        $statement = $pdo->prepare(<<<SQL
            SELECT s.id, s.name, s.status, s.deleted_at, s.active_version_id,
                   sv.processing_status,
                   EXISTS(
                       SELECT 1 FROM source_chunks sc
                       WHERE sc.source_version_id = s.active_version_id
                         AND sc.embedding IS NOT NULL
                         AND sc.embedding_model = ?
                         AND sc.embedding_dimensions IS NOT NULL
                         AND sc.embedding_dimensions > 0
                         AND (? IS NULL OR sc.embedding_dimensions = ?)
                   ) AS compatible_embedding
            FROM sources s
            LEFT JOIN source_versions sv ON sv.id = s.active_version_id
            WHERE s.id IN ($placeholders)
            ORDER BY s.id
            $lockClause
            SQL);
        $statement->execute([
            $provider->embeddingModel,
            $provider->embeddingDimensions,
            $provider->embeddingDimensions,
            ...$sourceIds,
        ]);
        $rows = [];

        foreach ($statement->fetchAll() as $row) {
            $rows[(int) $row['id']] = $row;
        }

        $readiness = [];

        foreach ($sourceIds as $sourceId) {
            $row = $rows[$sourceId] ?? null;
            $status = match (true) {
                !is_array($row) => ChatbotSourceReadinessStatus::Missing,
                $row['deleted_at'] !== null => ChatbotSourceReadinessStatus::Deleted,
                $row['status'] !== 'enabled' => ChatbotSourceReadinessStatus::Disabled,
                $row['active_version_id'] === null => ChatbotSourceReadinessStatus::NoActiveVersion,
                $row['processing_status'] !== 'ready' => ChatbotSourceReadinessStatus::ActiveVersionNotReady,
                !(bool) $row['compatible_embedding'] => ChatbotSourceReadinessStatus::EmbeddingIncompatible,
                default => ChatbotSourceReadinessStatus::Ready,
            };
            $readiness[] = new ChatbotSourceReadiness(
                $sourceId,
                is_array($row) ? (string) $row['name'] : null,
                $status,
                is_array($row) && $row['active_version_id'] !== null ? (int) $row['active_version_id'] : null,
            );
        }

        return $readiness;
    }

    /** @return array<string, int|string|null> */
    private function draftValues(ChatbotDraft $draft): array
    {
        return [
            'schema_version' => $draft->schemaVersion,
            'system_instructions' => $draft->systemInstructions,
            'fallback_message' => $draft->fallbackMessage,
            'retrieval_top_k' => $draft->retrievalTopK,
            'minimum_similarity' => number_format($draft->minimumSimilarity, 5, '.', ''),
            'citations_enabled' => $draft->citationsEnabled ? 1 : 0,
            'maximum_message_characters' => $draft->maximumMessageCharacters,
            'maximum_messages_per_session' => $draft->maximumMessagesPerSession,
            'idle_expiry_minutes' => $draft->idleExpiryMinutes,
            'absolute_expiry_minutes' => $draft->absoluteExpiryMinutes,
            'retention_days' => $draft->retentionDays,
            'privacy_notice_url' => $draft->privacyNoticeUrl,
            'disclosure_text' => $draft->disclosureText,
            'presentation_json' => $this->encodeJson($draft->presentation),
            'appearance_json' => $this->encodeJson($draft->appearance),
        ];
    }

    private function lockChatbot(PDO $pdo, int $id): void
    {
        $statement = $pdo->prepare('SELECT id FROM chatbots WHERE id = :id FOR UPDATE');
        $statement->execute(['id' => $id]);

        if ($statement->fetchColumn() === false) {
            throw new RuntimeException('The chatbot does not exist.');
        }
    }

    private function requireReload(int $id, string $operation): Chatbot
    {
        $chatbot = $this->findById($id);

        if (!$chatbot instanceof Chatbot) {
            throw new RuntimeException(sprintf('The chatbot could not be loaded after %s.', $operation));
        }

        return $chatbot;
    }

    private function chatbotSelect(): string
    {
        return <<<'SQL'
            SELECT c.id, c.public_id, c.name, c.description, c.status, c.active_publication_id,
                   c.created_at, c.updated_at, c.archived_at,
                   d.schema_version, d.revision, d.system_instructions, d.fallback_message,
                   d.retrieval_top_k, d.minimum_similarity, d.citations_enabled,
                   d.maximum_message_characters, d.maximum_messages_per_session,
                   d.idle_expiry_minutes, d.absolute_expiry_minutes, d.retention_days,
                   d.privacy_notice_url, d.disclosure_text, d.presentation_json,
                   d.appearance_json, d.updated_at AS draft_updated_at
            FROM chatbots c
            INNER JOIN chatbot_drafts d ON d.chatbot_id = c.id
            SQL;
    }

    private function listSelect(): string
    {
        return <<<'SQL'
            SELECT c.id, c.public_id, c.name, c.status, c.active_publication_id,
                   c.created_at, c.updated_at, c.archived_at, d.revision AS draft_revision,
                   cp.publication_number, cp.chat_provider AS provider, cp.chat_model
            SQL . $this->listFrom();
    }

    private function listFrom(): string
    {
        return <<<'SQL'
             FROM chatbots c
             INNER JOIN chatbot_drafts d ON d.chatbot_id = c.id
             LEFT JOIN chatbot_publications cp ON cp.id = c.active_publication_id
            SQL;
    }

    private function publicationSelect(): string
    {
        return <<<'SQL'
            SELECT cp.id, cp.chatbot_id, cp.publication_number, cp.source_draft_revision,
                   cp.schema_version, cp.configuration_hash, cp.system_instructions,
                   cp.fallback_message, cp.retrieval_top_k, cp.minimum_similarity,
                   cp.citations_enabled, cp.maximum_message_characters,
                   cp.maximum_messages_per_session, cp.idle_expiry_minutes,
                   cp.absolute_expiry_minutes, cp.retention_days, cp.privacy_notice_url,
                   cp.disclosure_text, cp.presentation_json, cp.appearance_json,
                   cp.chat_provider, cp.chat_model, cp.embedding_provider, cp.embedding_model,
                   cp.embedding_dimensions,
                   cp.published_at
            FROM chatbot_publications cp
            SQL;
    }

    /** @param array<string, mixed> $row */
    private function hydrateChatbot(array $row): Chatbot
    {
        $id = (int) $row['id'];

        return new Chatbot(
            $id,
            (string) $row['public_id'],
            (string) $row['name'],
            isset($row['description']) ? (string) $row['description'] : null,
            ChatbotStatus::from((string) $row['status']),
            isset($row['active_publication_id']) ? (int) $row['active_publication_id'] : null,
            $this->hydrateDraft($row, (int) $row['revision'], isset($row['draft_updated_at']) ? (string) $row['draft_updated_at'] : null),
            $this->draftAssignments($this->connection->pdo(), $id),
            (string) $row['created_at'],
            (string) $row['updated_at'],
            isset($row['archived_at']) ? (string) $row['archived_at'] : null,
        );
    }

    /** @param array<string, mixed> $row */
    private function hydrateListItem(array $row): ChatbotListItem
    {
        return new ChatbotListItem(
            (int) $row['id'],
            (string) $row['public_id'],
            (string) $row['name'],
            ChatbotStatus::from((string) $row['status']),
            (int) $row['draft_revision'],
            isset($row['active_publication_id']) ? (int) $row['active_publication_id'] : null,
            isset($row['publication_number']) ? (int) $row['publication_number'] : null,
            isset($row['provider']) ? (string) $row['provider'] : null,
            isset($row['chat_model']) ? (string) $row['chat_model'] : null,
            (string) $row['created_at'],
            (string) $row['updated_at'],
            isset($row['archived_at']) ? (string) $row['archived_at'] : null,
        );
    }

    /** @param array<string, mixed> $row */
    private function hydratePublication(array $row): ChatbotPublication
    {
        $id = (int) $row['id'];

        return new ChatbotPublication(
            $id,
            (int) $row['chatbot_id'],
            (int) $row['publication_number'],
            (int) $row['source_draft_revision'],
            (string) $row['configuration_hash'],
            $this->hydrateDraft($row, (int) $row['source_draft_revision'], null),
            $this->publicationAssignments($this->connection->pdo(), $id),
            new ChatbotProviderConfiguration(
                (string) $row['chat_provider'],
                (string) $row['chat_model'],
                (string) $row['embedding_provider'],
                (string) $row['embedding_model'],
                isset($row['embedding_dimensions']) ? (int) $row['embedding_dimensions'] : null,
            ),
            (string) $row['published_at'],
        );
    }

    /** @param array<string, mixed> $row */
    private function hydrateDraft(array $row, int $revision, ?string $updatedAt): ChatbotDraft
    {
        return new ChatbotDraft(
            (int) $row['schema_version'],
            $revision,
            (string) $row['system_instructions'],
            (string) $row['fallback_message'],
            (int) $row['retrieval_top_k'],
            (float) $row['minimum_similarity'],
            (bool) $row['citations_enabled'],
            (int) $row['maximum_message_characters'],
            (int) $row['maximum_messages_per_session'],
            (int) $row['idle_expiry_minutes'],
            (int) $row['absolute_expiry_minutes'],
            (int) $row['retention_days'],
            isset($row['privacy_notice_url']) ? (string) $row['privacy_notice_url'] : null,
            (string) $row['disclosure_text'],
            $this->decodeJson((string) $row['presentation_json']),
            $this->decodeJson((string) $row['appearance_json']),
            $updatedAt,
        );
    }

    /** @param array<string, mixed> $value */
    private function encodeJson(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** @return array<string, mixed> */
    private function decodeJson(string $value): array
    {
        $decoded = json_decode($value, true, flags: JSON_THROW_ON_ERROR);

        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new RuntimeException('Stored chatbot configuration JSON must be an object.');
        }

        return $decoded;
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
