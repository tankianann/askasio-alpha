<?php

declare(strict_types=1);

namespace App\Domain\Api;

final readonly class ApiRequestLogPurgeCriteria
{
    public function __construct(
        public ApiRequestLogPurgeScope $scope,
        public ?string $cutoffUtc = null,
        public ?string $createdFromUtc = null,
        public ?string $createdBeforeUtc = null,
        public ?int $apiKeyId = null,
        public ?string $endpoint = null,
        public ?string $method = null,
        public ?int $statusCode = null,
        public ?ApiRequestStatusGroup $statusGroup = null,
        public ?int $minimumDurationMilliseconds = null,
        public ?int $maximumDurationMilliseconds = null,
        public ?string $requestId = null,
        public ApiRequestAuthenticationState $authentication = ApiRequestAuthenticationState::All,
    ) {
        if ($scope === ApiRequestLogPurgeScope::MatchingFilters && !$this->hasFilters()) {
            throw new \InvalidArgumentException('A matching-filter purge requires at least one active filter.');
        }

        if (in_array($scope, [ApiRequestLogPurgeScope::BeforeDate, ApiRequestLogPurgeScope::OlderThanAge], true)
            && $cutoffUtc === null) {
            throw new \InvalidArgumentException('A time-based purge requires a UTC cutoff.');
        }
    }

    public static function matching(ApiRequestLogQuery $query): self
    {
        return new self(
            ApiRequestLogPurgeScope::MatchingFilters,
            createdFromUtc: $query->createdFromUtc,
            createdBeforeUtc: $query->createdBeforeUtc,
            apiKeyId: $query->apiKeyId,
            endpoint: $query->endpoint,
            method: $query->method,
            statusCode: $query->statusCode,
            statusGroup: $query->statusGroup,
            minimumDurationMilliseconds: $query->minimumDurationMilliseconds,
            maximumDurationMilliseconds: $query->maximumDurationMilliseconds,
            requestId: $query->requestId,
            authentication: $query->authentication,
        );
    }

    public function hasFilters(): bool
    {
        return $this->createdFromUtc !== null
            || $this->createdBeforeUtc !== null
            || $this->apiKeyId !== null
            || $this->endpoint !== null
            || $this->method !== null
            || $this->statusCode !== null
            || $this->statusGroup !== null
            || $this->minimumDurationMilliseconds !== null
            || $this->maximumDurationMilliseconds !== null
            || $this->requestId !== null
            || $this->authentication !== ApiRequestAuthenticationState::All;
    }

    /** @return array<string, int|string|null> */
    public function toArray(): array
    {
        return [
            'scope' => $this->scope->value,
            'cutoff_utc' => $this->cutoffUtc,
            'created_from_utc' => $this->createdFromUtc,
            'created_before_utc' => $this->createdBeforeUtc,
            'api_key_id' => $this->apiKeyId,
            'endpoint' => $this->endpoint,
            'method' => $this->method,
            'status_code' => $this->statusCode,
            'status_group' => $this->statusGroup?->value,
            'duration_min' => $this->minimumDurationMilliseconds,
            'duration_max' => $this->maximumDurationMilliseconds,
            'request_id' => $this->requestId,
            'authentication' => $this->authentication->value,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            ApiRequestLogPurgeScope::from((string) ($data['scope'] ?? '')),
            self::nullableString($data, 'cutoff_utc'),
            self::nullableString($data, 'created_from_utc'),
            self::nullableString($data, 'created_before_utc'),
            self::nullableInt($data, 'api_key_id'),
            self::nullableString($data, 'endpoint'),
            self::nullableString($data, 'method'),
            self::nullableInt($data, 'status_code'),
            isset($data['status_group']) && is_string($data['status_group'])
                ? ApiRequestStatusGroup::from($data['status_group'])
                : null,
            self::nullableInt($data, 'duration_min'),
            self::nullableInt($data, 'duration_max'),
            self::nullableString($data, 'request_id'),
            ApiRequestAuthenticationState::from((string) ($data['authentication'] ?? 'all')),
        );
    }

    /** @param array<string, mixed> $data */
    private static function nullableString(array $data, string $key): ?string
    {
        return isset($data[$key]) && is_string($data[$key]) ? $data[$key] : null;
    }

    /** @param array<string, mixed> $data */
    private static function nullableInt(array $data, string $key): ?int
    {
        return isset($data[$key]) && is_int($data[$key]) ? $data[$key] : null;
    }
}
