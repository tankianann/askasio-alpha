<?php

declare(strict_types=1);

namespace App\Domain\Chatbots;

final readonly class ChatbotAssignments
{
    /** @param list<int> $sourceIds @param list<string> $origins */
    public function __construct(
        public array $sourceIds,
        public array $origins,
    ) {
    }

    /** @return array{source_ids: list<int>, origins: list<string>} */
    public function configuration(): array
    {
        return ['source_ids' => $this->sourceIds, 'origins' => $this->origins];
    }
}

