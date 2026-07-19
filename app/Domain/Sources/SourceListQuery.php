<?php

declare(strict_types=1);

namespace App\Domain\Sources;

use App\Support\Pagination\PageRequest;
use App\Support\SortDirection;

final readonly class SourceListQuery
{
    public function __construct(
        public PageRequest $pagination,
        public ?string $search,
        public ?SourceType $type,
        public SourceListAvailability $availability,
        public ?ProcessingStatus $processingStatus,
        public SourceListSort $sort,
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
            'type' => $this->type?->value,
            'availability' => $this->availability === SourceListAvailability::All ? null : $this->availability->value,
            'processing' => $this->processingStatus?->value,
            'sort' => $this->sort === SourceListSort::Updated ? null : $this->sort->value,
            'direction' => $this->direction === SortDirection::Descending ? null : $this->direction->value,
        ], static fn (int|string|null $value): bool => $value !== null && $value !== '');
    }

    public function hasActiveFilters(): bool
    {
        return $this->search !== null
            || $this->type !== null
            || $this->availability !== SourceListAvailability::All
            || $this->processingStatus !== null;
    }
}
