<?php

declare(strict_types=1);

namespace App\Domain\ApiKeys;

use App\Support\Pagination\PageRequest;
use App\Support\SortDirection;

final readonly class ApiKeyListQuery
{
    public function __construct(
        public PageRequest $pagination,
        public ?string $search,
        public ApiKeyListStatus $status,
        public ApiKeyListSort $sort,
        public SortDirection $direction,
    ) {
    }

    /** @return array<string, int|string|null> */
    public function queryParameters(): array
    {
        return array_filter([
            'page' => $this->pagination->page === 1 ? null : $this->pagination->page,
            'per_page' => $this->pagination->perPage === 25 ? null : $this->pagination->perPage,
            'search' => $this->search,
            'status' => $this->status === ApiKeyListStatus::All ? null : $this->status->value,
            'sort' => $this->sort === ApiKeyListSort::Created ? null : $this->sort->value,
            'direction' => $this->direction === SortDirection::Descending ? null : $this->direction->value,
        ], static fn (int|string|null $value): bool => $value !== null && $value !== '');
    }

    public function hasActiveFilters(): bool
    {
        return $this->search !== null || $this->status !== ApiKeyListStatus::All;
    }
}
