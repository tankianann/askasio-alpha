<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Domain\Chatbots\Chatbot;
use App\Domain\Chatbots\ChatbotAssignments;
use App\Domain\Chatbots\ChatbotDraft;
use App\Domain\Chatbots\ChatbotListItem;
use App\Domain\Chatbots\ChatbotListQuery;
use App\Domain\Chatbots\ChatbotProviderConfiguration;
use App\Domain\Chatbots\ChatbotPublication;
use App\Domain\Chatbots\ChatbotStatus;
use App\Domain\Chatbots\ChatbotSourceReadiness;
use App\Domain\Chatbots\ChatbotSourceReadinessStatus;
use App\Exceptions\ChatbotPublicationReadinessException;
use App\Exceptions\InvalidChatbotSourceAssignmentException;
use App\Exceptions\StaleChatbotDraftException;
use App\Exceptions\UnchangedChatbotPublicationException;
use App\Repositories\ChatbotRepositoryInterface;
use App\Support\Pagination\PaginatedResult;
use RuntimeException;

final class InMemoryChatbotRepository implements ChatbotRepositoryInterface
{
    /** @var array<int, Chatbot> */
    private array $chatbots = [];

    /** @var array<int, ChatbotPublication> */
    private array $publications = [];

    private int $nextChatbotId = 1;
    private int $nextPublicationId = 1;

    /** @var array<int, ChatbotSourceReadinessStatus> */
    private array $sourceStatuses = [];

    public function defineSource(int $sourceId, ChatbotSourceReadinessStatus $status = ChatbotSourceReadinessStatus::Ready): void
    {
        $this->sourceStatuses[$sourceId] = $status;
    }

    public function paginate(ChatbotListQuery $query): PaginatedResult
    {
        $items = array_map(
            fn (Chatbot $chatbot): ChatbotListItem => $this->listItem($chatbot),
            array_values($this->chatbots),
        );
        $page = $query->pagination->clampToTotal(count($items));

        return new PaginatedResult(
            array_slice($items, $page->offset(), $page->perPage),
            count($items),
            $page,
        );
    }

    public function findById(int $id): ?Chatbot
    {
        return $this->chatbots[$id] ?? null;
    }

    public function findByPublicId(string $publicId): ?Chatbot
    {
        foreach ($this->chatbots as $chatbot) {
            if ($chatbot->publicId === $publicId) {
                return $chatbot;
            }
        }

        return null;
    }

    public function findActivePublication(int $chatbotId): ?ChatbotPublication
    {
        $chatbot = $this->findById($chatbotId);

        return $chatbot?->activePublicationId === null
            ? null
            : ($this->publications[$chatbot->activePublicationId] ?? null);
    }

    public function create(string $publicId, string $name, ?string $description, ChatbotDraft $draft): Chatbot
    {
        if ($this->findByPublicId($publicId) instanceof Chatbot) {
            throw new RuntimeException('Duplicate chatbot public ID.');
        }

        $id = $this->nextChatbotId++;
        $now = '2026-07-20 00:00:00.000000';
        $storedDraft = $this->draftWithRevision($draft, 1, $now);

        return $this->chatbots[$id] = new Chatbot(
            $id,
            $publicId,
            $name,
            $description,
            ChatbotStatus::Active,
            null,
            $storedDraft,
            new ChatbotAssignments([], []),
            $now,
            $now,
            null,
        );
    }

    public function replaceDraftAssignments(
        int $id,
        int $expectedRevision,
        ChatbotAssignments $assignments,
    ): Chatbot {
        $chatbot = $this->requireChatbot($id);

        if ($chatbot->draft->revision !== $expectedRevision) {
            throw new StaleChatbotDraftException('The chatbot draft was changed by another request.');
        }

        foreach ($assignments->sourceIds as $sourceId) {
            if (!isset($this->sourceStatuses[$sourceId])) {
                throw new InvalidChatbotSourceAssignmentException('Draft source assignments must reference existing sources.');
            }
        }

        if ($chatbot->assignments->configuration() === $assignments->configuration()) {
            return $chatbot;
        }

        $now = '2026-07-20 00:01:00.000000';

        return $this->chatbots[$id] = new Chatbot(
            $chatbot->id,
            $chatbot->publicId,
            $chatbot->name,
            $chatbot->description,
            $chatbot->status,
            $chatbot->activePublicationId,
            $this->draftWithRevision($chatbot->draft, $expectedRevision + 1, $now),
            $assignments,
            $chatbot->createdAt,
            $now,
            $chatbot->archivedAt,
        );
    }

    public function updateDraft(
        int $id,
        int $expectedRevision,
        string $name,
        ?string $description,
        ChatbotDraft $draft,
    ): Chatbot {
        $chatbot = $this->requireChatbot($id);

        if ($chatbot->draft->revision !== $expectedRevision) {
            throw new StaleChatbotDraftException('The chatbot draft was changed by another request.');
        }

        $now = '2026-07-20 00:01:00.000000';

        return $this->chatbots[$id] = new Chatbot(
            $chatbot->id,
            $chatbot->publicId,
            $name,
            $description,
            $chatbot->status,
            $chatbot->activePublicationId,
            $this->draftWithRevision($draft, $expectedRevision + 1, $now),
            $chatbot->assignments,
            $chatbot->createdAt,
            $now,
            $chatbot->archivedAt,
        );
    }

    public function publish(
        int $id,
        int $expectedDraftRevision,
        ChatbotDraft $draft,
        ChatbotAssignments $assignments,
        ChatbotProviderConfiguration $provider,
        string $configurationHash,
    ): ChatbotPublication {
        $chatbot = $this->requireChatbot($id);

        if ($chatbot->draft->revision !== $expectedDraftRevision) {
            throw new StaleChatbotDraftException('The chatbot draft changed before publication.');
        }

        if ($chatbot->assignments->configuration() !== $assignments->configuration()) {
            throw new StaleChatbotDraftException('The chatbot assignments changed before publication.');
        }

        if ($assignments->sourceIds === [] || $assignments->origins === []) {
            throw new InvalidChatbotSourceAssignmentException(
                'Publishing requires at least one assigned source and one allowed origin.',
            );
        }

        $readiness = array_map(
            fn (int $sourceId): ChatbotSourceReadiness => new ChatbotSourceReadiness(
                $sourceId,
                'Source ' . $sourceId,
                $this->sourceStatuses[$sourceId] ?? ChatbotSourceReadinessStatus::Missing,
                isset($this->sourceStatuses[$sourceId]) ? $sourceId * 10 : null,
            ),
            $assignments->sourceIds,
        );

        foreach ($readiness as $source) {
            if (!$source->isReady()) {
                throw new ChatbotPublicationReadinessException($readiness);
            }
        }

        $active = $this->findActivePublication($id);

        if ($active instanceof ChatbotPublication && hash_equals($active->configurationHash, $configurationHash)) {
            throw new UnchangedChatbotPublicationException(
                'The active chatbot publication already has this configuration.',
            );
        }

        $number = 1;

        foreach ($this->publications as $publication) {
            if ($publication->chatbotId === $id) {
                $number = max($number, $publication->publicationNumber + 1);
            }
        }

        $publicationId = $this->nextPublicationId++;
        $publication = new ChatbotPublication(
            $publicationId,
            $id,
            $number,
            $expectedDraftRevision,
            $configurationHash,
            $this->draftWithRevision($draft, $expectedDraftRevision, null),
            $assignments,
            $provider,
            '2026-07-20 00:02:00.000000',
        );
        $this->publications[$publicationId] = $publication;
        $this->chatbots[$id] = new Chatbot(
            $chatbot->id,
            $chatbot->publicId,
            $chatbot->name,
            $chatbot->description,
            $chatbot->status,
            $publicationId,
            $chatbot->draft,
            $chatbot->assignments,
            $chatbot->createdAt,
            '2026-07-20 00:02:00.000000',
            $chatbot->archivedAt,
        );

        return $publication;
    }

    public function rotatePublicId(int $id, string $publicId): Chatbot
    {
        $chatbot = $this->requireChatbot($id);

        return $this->chatbots[$id] = $this->copy($chatbot, publicId: $publicId);
    }

    public function setStatus(int $id, ChatbotStatus $status): Chatbot
    {
        $chatbot = $this->requireChatbot($id);

        return $this->chatbots[$id] = $this->copy(
            $chatbot,
            status: $status,
            archivedAt: $status === ChatbotStatus::Archived ? '2026-07-20 00:03:00.000000' : null,
        );
    }

    public function permanentlyDelete(int $id): void
    {
        $chatbot = $this->requireChatbot($id);

        if ($chatbot->status !== ChatbotStatus::Archived) {
            throw new RuntimeException('The chatbot is not archived.');
        }

        foreach ($this->publications as $publicationId => $publication) {
            if ($publication->chatbotId === $id) {
                unset($this->publications[$publicationId]);
            }
        }

        unset($this->chatbots[$id]);
    }

    private function requireChatbot(int $id): Chatbot
    {
        return $this->chatbots[$id] ?? throw new RuntimeException('The chatbot does not exist.');
    }

    private function draftWithRevision(ChatbotDraft $draft, int $revision, ?string $updatedAt): ChatbotDraft
    {
        return new ChatbotDraft(
            $draft->schemaVersion,
            $revision,
            $draft->systemInstructions,
            $draft->fallbackMessage,
            $draft->retrievalTopK,
            $draft->minimumSimilarity,
            $draft->citationsEnabled,
            $draft->maximumMessageCharacters,
            $draft->maximumMessagesPerSession,
            $draft->idleExpiryMinutes,
            $draft->absoluteExpiryMinutes,
            $draft->retentionDays,
            $draft->privacyNoticeUrl,
            $draft->disclosureText,
            $draft->presentation,
            $draft->appearance,
            $updatedAt,
        );
    }

    private function copy(
        Chatbot $chatbot,
        ?string $publicId = null,
        ?ChatbotStatus $status = null,
        ?string $archivedAt = null,
    ): Chatbot {
        return new Chatbot(
            $chatbot->id,
            $publicId ?? $chatbot->publicId,
            $chatbot->name,
            $chatbot->description,
            $status ?? $chatbot->status,
            $chatbot->activePublicationId,
            $chatbot->draft,
            $chatbot->assignments,
            $chatbot->createdAt,
            '2026-07-20 00:03:00.000000',
            $archivedAt,
        );
    }

    private function listItem(Chatbot $chatbot): ChatbotListItem
    {
        $publication = $this->findActivePublication($chatbot->id);

        return new ChatbotListItem(
            $chatbot->id,
            $chatbot->publicId,
            $chatbot->name,
            $chatbot->status,
            $chatbot->draft->revision,
            $chatbot->activePublicationId,
            $publication?->publicationNumber,
            $publication?->providerConfiguration->chatProvider,
            $publication?->providerConfiguration->chatModel,
            $chatbot->createdAt,
            $chatbot->updatedAt,
            $chatbot->archivedAt,
        );
    }
}
