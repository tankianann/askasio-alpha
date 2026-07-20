<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Domain\Chatbots\Chatbot;
use App\Domain\Chatbots\ChatbotDraft;
use App\Domain\Chatbots\ChatbotListItem;
use App\Domain\Chatbots\ChatbotListQuery;
use App\Domain\Chatbots\ChatbotProviderConfiguration;
use App\Domain\Chatbots\ChatbotPublication;
use App\Domain\Chatbots\ChatbotStatus;
use App\Support\Pagination\PaginatedResult;

interface ChatbotRepositoryInterface
{
    /** @return PaginatedResult<ChatbotListItem> */
    public function paginate(ChatbotListQuery $query): PaginatedResult;

    public function findById(int $id): ?Chatbot;

    public function findByPublicId(string $publicId): ?Chatbot;

    public function findActivePublication(int $chatbotId): ?ChatbotPublication;

    public function create(string $publicId, string $name, ?string $description, ChatbotDraft $draft): Chatbot;

    public function updateDraft(
        int $id,
        int $expectedRevision,
        string $name,
        ?string $description,
        ChatbotDraft $draft,
    ): Chatbot;

    public function publish(
        int $id,
        int $expectedDraftRevision,
        ChatbotDraft $draft,
        ChatbotProviderConfiguration $provider,
        string $configurationHash,
    ): ChatbotPublication;

    public function rotatePublicId(int $id, string $publicId): Chatbot;

    public function setStatus(int $id, ChatbotStatus $status): Chatbot;

    public function permanentlyDelete(int $id): void;
}

