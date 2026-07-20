<?php

declare(strict_types=1);

namespace App\Domain\Chatbots;

final readonly class Chatbot
{
    public function __construct(
        public int $id,
        public string $publicId,
        public string $name,
        public ?string $description,
        public ChatbotStatus $status,
        public ?int $activePublicationId,
        public ChatbotDraft $draft,
        public ChatbotAssignments $assignments,
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
