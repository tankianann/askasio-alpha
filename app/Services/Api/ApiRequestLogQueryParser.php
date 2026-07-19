<?php

declare(strict_types=1);

namespace App\Services\Api;

use App\Domain\Api\ApiRequestAuthenticationState;
use App\Domain\Api\ApiRequestLogQuery;
use App\Domain\Api\ApiRequestLogSort;
use App\Domain\Api\ApiRequestStatusGroup;
use App\Exceptions\ValidationException;
use App\Http\Request;
use App\Support\Pagination\PageRequest;
use App\Support\SortDirection;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;
use ValueError;

final class ApiRequestLogQueryParser
{
    private const MAXIMUM_PAGE = 1_000_000;
    private const MAXIMUM_DURATION_MILLISECONDS = 4_294_967_295;
    private const ALLOWED_PAGE_SIZES = [25, 50, 100];
    private const ALLOWED_METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'];

    private readonly DateTimeZone $timezone;
    private readonly DateTimeZone $utc;

    public function __construct(string $timezone)
    {
        try {
            $this->timezone = new DateTimeZone($timezone);
        } catch (Throwable $exception) {
            throw new \InvalidArgumentException('The API Activity timezone is invalid.', previous: $exception);
        }

        $this->utc = new DateTimeZone('UTC');
    }

    public function parse(Request $request): ApiRequestLogQuery
    {
        $page = $this->integer($request, 'page', 1, 1, self::MAXIMUM_PAGE);
        $perPage = $this->integer($request, 'per_page', 25, 1, 100);

        if (!in_array($perPage, self::ALLOWED_PAGE_SIZES, true)) {
            throw new ValidationException('The per_page parameter must be 25, 50, or 100.');
        }

        [$dateFrom, $createdFromUtc] = $this->dateBoundary($request, 'date_from', false);
        [$dateTo, $createdBeforeUtc] = $this->dateBoundary($request, 'date_to', true);

        if ($dateFrom !== null && $dateTo !== null && $dateFrom > $dateTo) {
            throw new ValidationException('The date_from parameter must not be after date_to.');
        }

        $apiKeyId = $this->integer($request, 'api_key_id', null, 1, PHP_INT_MAX);
        $endpoint = $this->boundedText($request, 'endpoint', 255);

        if ($endpoint !== null
            && (!str_starts_with($endpoint, '/')
                || str_starts_with($endpoint, '//')
                || str_contains($endpoint, '?')
                || str_contains($endpoint, '#'))) {
            throw new ValidationException('The endpoint parameter must be a valid application path.');
        }

        $method = $this->boundedText($request, 'method', 10);

        if ($method !== null) {
            $method = strtoupper($method);

            if (!in_array($method, self::ALLOWED_METHODS, true)) {
                throw new ValidationException('The method parameter is not supported.');
            }
        }

        $statusCode = $this->integer($request, 'status_code', null, 100, 599);
        $statusGroup = $this->statusGroup($request);
        $minimumDuration = $this->integer(
            $request,
            'duration_min',
            null,
            0,
            self::MAXIMUM_DURATION_MILLISECONDS,
        );
        $maximumDuration = $this->integer(
            $request,
            'duration_max',
            null,
            0,
            self::MAXIMUM_DURATION_MILLISECONDS,
        );

        if ($minimumDuration !== null
            && $maximumDuration !== null
            && $minimumDuration > $maximumDuration) {
            throw new ValidationException('The duration_min parameter must not exceed duration_max.');
        }

        $requestId = $this->boundedText($request, 'request_id', 64);

        if ($requestId !== null && preg_match('/^[A-Za-z0-9_-]+$/', $requestId) !== 1) {
            throw new ValidationException('The request_id parameter is invalid.');
        }

        $authentication = $this->authentication($request);

        if ($apiKeyId !== null && $authentication === ApiRequestAuthenticationState::Unauthenticated) {
            throw new ValidationException('An API connection cannot be combined with unauthenticated requests.');
        }

        return new ApiRequestLogQuery(
            new PageRequest($page, $perPage, self::ALLOWED_PAGE_SIZES),
            $dateFrom,
            $dateTo,
            $createdFromUtc,
            $createdBeforeUtc,
            $apiKeyId,
            $endpoint,
            $method,
            $statusCode,
            $statusCode === null ? $statusGroup : null,
            $minimumDuration,
            $maximumDuration,
            $requestId,
            $authentication,
            $this->sort($request),
            $this->direction($request),
        );
    }

    /** @return array{?string, ?string} */
    private function dateBoundary(Request $request, string $parameter, bool $exclusiveEnd): array
    {
        $value = $this->string($request, $parameter);

        if ($value === null) {
            return [null, null];
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $this->timezone);
        $errors = DateTimeImmutable::getLastErrors();

        if (!$date instanceof DateTimeImmutable
            || $date->format('Y-m-d') !== $value
            || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw new ValidationException(sprintf('The %s parameter must use YYYY-MM-DD.', $parameter));
        }

        if ($exclusiveEnd) {
            $date = $date->modify('+1 day');
        }

        return [$value, $date->setTimezone($this->utc)->format('Y-m-d H:i:s')];
    }

    private function statusGroup(Request $request): ?ApiRequestStatusGroup
    {
        $value = $this->string($request, 'status_group');

        if ($value === null) {
            return null;
        }

        try {
            return ApiRequestStatusGroup::from($value);
        } catch (ValueError) {
            throw new ValidationException('The status_group parameter is invalid.');
        }
    }

    private function authentication(Request $request): ApiRequestAuthenticationState
    {
        $value = $this->string($request, 'authentication') ?? ApiRequestAuthenticationState::All->value;

        try {
            return ApiRequestAuthenticationState::from($value);
        } catch (ValueError) {
            throw new ValidationException('The authentication parameter is invalid.');
        }
    }

    private function sort(Request $request): ApiRequestLogSort
    {
        $value = $this->string($request, 'sort') ?? ApiRequestLogSort::Date->value;

        try {
            return ApiRequestLogSort::from($value);
        } catch (ValueError) {
            throw new ValidationException('The sort parameter is invalid.');
        }
    }

    private function direction(Request $request): SortDirection
    {
        $value = $this->string($request, 'direction') ?? SortDirection::Descending->value;

        try {
            return SortDirection::from($value);
        } catch (ValueError) {
            throw new ValidationException('The direction parameter is invalid.');
        }
    }

    private function boundedText(Request $request, string $parameter, int $maximumCharacters): ?string
    {
        $value = $this->string($request, $parameter);

        if ($value === null) {
            return null;
        }

        if (mb_strlen($value) > $maximumCharacters || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new ValidationException(sprintf('The %s parameter is invalid.', $parameter));
        }

        return $value;
    }

    private function integer(
        Request $request,
        string $parameter,
        ?int $default,
        int $minimum,
        int $maximum,
    ): ?int {
        $value = $this->string($request, $parameter);

        if ($value === null) {
            return $default;
        }

        $validated = filter_var($value, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => $minimum, 'max_range' => $maximum],
        ]);

        if (!is_int($validated)) {
            throw new ValidationException(sprintf('The %s parameter is invalid.', $parameter));
        }

        return $validated;
    }

    private function string(Request $request, string $parameter): ?string
    {
        $value = $request->query($parameter);

        if ($value === null || $value === '') {
            return null;
        }

        if (!is_string($value)) {
            throw new ValidationException(sprintf('The %s parameter must be a string.', $parameter));
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
