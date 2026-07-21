<?php

declare(strict_types=1);

namespace App\Domain\Chatbots;

final readonly class ChatbotAnalyticsQuery
{
    public function __construct(
        public string $dateFrom,
        public string $dateTo,
        public string $fromUtc,
        public string $beforeUtc,
        public ?int $chatbotId = null,
        public ?bool $isTest = false,
    ) {
    }

    /** @return array<string, string|int> */
    public function queryParameters(): array
    {
        return array_filter([
            'date_from' => $this->dateFrom,
            'date_to' => $this->dateTo,
            'chatbot_id' => $this->chatbotId,
            'traffic' => $this->isTest === null ? 'all' : ($this->isTest ? 'test' : 'production'),
        ], static fn (mixed $value): bool => $value !== null);
    }
}
