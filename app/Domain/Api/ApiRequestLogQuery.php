<?php

declare(strict_types=1);

namespace App\Domain\Api;

use App\Support\Pagination\PageRequest;
use App\Support\SortDirection;

final readonly class ApiRequestLogQuery
{
    public function __construct(
        public PageRequest $pagination,
        public ?string $dateFrom,
        public ?string $dateTo,
        public ?string $createdFromUtc,
        public ?string $createdBeforeUtc,
        public ?int $apiKeyId,
        public ?string $endpoint,
        public ?string $method,
        public ?int $statusCode,
        public ?ApiRequestStatusGroup $statusGroup,
        public ?int $minimumDurationMilliseconds,
        public ?int $maximumDurationMilliseconds,
        public ?string $requestId,
        public ApiRequestAuthenticationState $authentication,
        public ApiRequestLogSort $sort,
        public SortDirection $direction,
        public ApiAccessMethod $accessMethod = ApiAccessMethod::All,
    ) {
    }

    /** @return array<string, int|string|null> */
    public function queryParameters(): array
    {
        return array_filter([
            'page' => $this->pagination->page === 1 ? null : $this->pagination->page,
            'per_page' => $this->pagination->perPage === 25 ? null : $this->pagination->perPage,
            ...$this->filterParameters(),
            'sort' => $this->sort === ApiRequestLogSort::Date ? null : $this->sort->value,
            'direction' => $this->direction === SortDirection::Descending ? null : $this->direction->value,
        ], static fn (int|string|null $value): bool => $value !== null && $value !== '');
    }

    /** @return array<string, int|string|null> */
    public function filterParameters(): array
    {
        return array_filter([
            'date_from' => $this->dateFrom,
            'date_to' => $this->dateTo,
            'api_key_id' => $this->apiKeyId,
            'endpoint' => $this->endpoint,
            'method' => $this->method,
            'status_code' => $this->statusCode,
            'status_group' => $this->statusCode === null ? $this->statusGroup?->value : null,
            'duration_min' => $this->minimumDurationMilliseconds,
            'duration_max' => $this->maximumDurationMilliseconds,
            'request_id' => $this->requestId,
            'authentication' => $this->authentication === ApiRequestAuthenticationState::All
                ? null
                : $this->authentication->value,
            'access_method' => $this->accessMethod === ApiAccessMethod::All
                ? null
                : $this->accessMethod->value,
        ], static fn (int|string|null $value): bool => $value !== null && $value !== '');
    }

    public function hasActiveFilters(): bool
    {
        return $this->filterParameters() !== [];
    }
}
