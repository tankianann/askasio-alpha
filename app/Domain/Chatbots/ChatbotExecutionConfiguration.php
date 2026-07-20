<?php

declare(strict_types=1);

namespace App\Domain\Chatbots;

final readonly class ChatbotExecutionConfiguration
{
    public function __construct(
        public int $chatbotId,
        public ?int $publicationId,
        public ?int $draftRevision,
        public ChatbotDraft $configuration,
        public ChatbotAssignments $assignments,
        public ChatbotProviderConfiguration $providerConfiguration,
    ) {
        if (($this->publicationId === null) === ($this->draftRevision === null)) {
            throw new \InvalidArgumentException('Execution configuration requires exactly one publication or draft binding.');
        }
    }
}
