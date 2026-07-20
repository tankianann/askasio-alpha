<?php

declare(strict_types=1);

namespace App\Domain\Chatbots;

final readonly class ChatbotListItem
{
    public function __construct(
        public int $id,
        public string $publicId,
        public string $name,
        public ChatbotStatus $status,
        public int $draftRevision,
        public ?int $activePublicationId,
        public ?int $publicationNumber,
        public ?string $provider,
        public ?string $chatModel,
        public string $createdAt,
        public string $updatedAt,
        public ?string $archivedAt,
    ) {
    }

    public function isPublished(): bool
    {
        return $this->activePublicationId !== null;
    }
}

