<?php

declare(strict_types=1);

namespace App\Domain\Chatbots;

use App\Support\Pagination\PageRequest;
use App\Support\SortDirection;

final readonly class ChatbotListQuery
{
    public function __construct(
        public PageRequest $pagination,
        public ?string $search,
        public ChatbotListStatus $status,
        public ChatbotPublicationFilter $publication,
        public ChatbotListSort $sort,
        public SortDirection $direction,
        public ?string $model = null,
        public ?string $sourceSearch = null,
    ) {
    }

    /** @return array<string, int|string|null> */
    public function queryParameters(): array
    {
        return array_filter([
            'page' => $this->pagination->page === 1 ? null : $this->pagination->page,
            'per_page' => $this->pagination->perPage === 25 ? null : $this->pagination->perPage,
            'search' => $this->search,
            'status' => $this->status === ChatbotListStatus::All ? null : $this->status->value,
            'publication' => $this->publication === ChatbotPublicationFilter::All ? null : $this->publication->value,
            'sort' => $this->sort === ChatbotListSort::Updated ? null : $this->sort->value,
            'direction' => $this->direction === SortDirection::Descending ? null : $this->direction->value,
            'model' => $this->model,
            'source' => $this->sourceSearch,
        ], static fn (int|string|null $value): bool => $value !== null && $value !== '');
    }

    public function hasActiveFilters(): bool
    {
        return $this->search !== null
            || $this->status !== ChatbotListStatus::All
            || $this->publication !== ChatbotPublicationFilter::All
            || $this->model !== null
            || $this->sourceSearch !== null;
    }
}
