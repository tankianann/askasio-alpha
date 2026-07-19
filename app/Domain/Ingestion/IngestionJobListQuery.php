<?php

declare(strict_types=1);

namespace App\Domain\Ingestion;

use App\Support\Pagination\PageRequest;
use App\Support\SortDirection;

final readonly class IngestionJobListQuery
{
    public function __construct(
        public PageRequest $pagination,
        public ?JobStatus $status,
        public ?int $sourceId,
        public ?string $dateFrom,
        public ?string $dateTo,
        public ?string $createdFromUtc,
        public ?string $createdBeforeUtc,
        public ?int $minimumAttempts,
        public ?int $maximumAttempts,
        public IngestionJobListSort $sort,
        public SortDirection $direction,
    ) {
    }

    /** @return array<string, int|string|null> */
    public function queryParameters(): array
    {
        return array_filter([
            'page' => $this->pagination->page === 1 ? null : $this->pagination->page,
            'per_page' => $this->pagination->perPage === 25 ? null : $this->pagination->perPage,
            'status' => $this->status?->value,
            'source_id' => $this->sourceId,
            'date_from' => $this->dateFrom,
            'date_to' => $this->dateTo,
            'attempts_min' => $this->minimumAttempts,
            'attempts_max' => $this->maximumAttempts,
            'sort' => $this->sort === IngestionJobListSort::Date ? null : $this->sort->value,
            'direction' => $this->direction === SortDirection::Descending ? null : $this->direction->value,
        ], static fn (int|string|null $value): bool => $value !== null && $value !== '');
    }

    public function hasActiveFilters(): bool
    {
        return $this->status !== null
            || $this->sourceId !== null
            || $this->dateFrom !== null
            || $this->dateTo !== null
            || $this->minimumAttempts !== null
            || $this->maximumAttempts !== null;
    }
}
