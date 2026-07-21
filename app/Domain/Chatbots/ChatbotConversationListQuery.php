<?php

declare(strict_types=1);

namespace App\Domain\Chatbots;

use App\Support\Pagination\PageRequest;
use App\Support\SortDirection;

final readonly class ChatbotConversationListQuery
{
    public function __construct(
        public PageRequest $pagination,
        public ?string $search = null,
        public ?int $chatbotId = null,
        public ?ChatbotSessionStatus $status = null,
        public ?bool $isTest = null,
        public ?string $dateFrom = null,
        public ?string $dateTo = null,
        public ?string $dateFromUtc = null,
        public ?string $dateBeforeUtc = null,
        public string $sort = 'activity',
        public SortDirection $direction = SortDirection::Descending,
    ) {
    }

    /** @return array<string, string|int> */
    public function queryParameters(): array
    {
        return array_filter([
            'search' => $this->search,
            'chatbot_id' => $this->chatbotId,
            'status' => $this->status?->value,
            'traffic' => $this->isTest === null ? null : ($this->isTest ? 'test' : 'production'),
            'date_from' => $this->dateFrom,
            'date_to' => $this->dateTo,
            'sort' => $this->sort === 'activity' ? null : $this->sort,
            'direction' => $this->direction === SortDirection::Descending ? null : $this->direction->value,
            'page' => $this->pagination->page === 1 ? null : $this->pagination->page,
            'per_page' => $this->pagination->perPage === 25 ? null : $this->pagination->perPage,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }
}
