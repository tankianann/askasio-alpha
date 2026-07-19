<?php

declare(strict_types=1);

namespace App\Services\Api;

use App\Domain\Api\ApiRequestLogPurgeCriteria;
use App\Domain\Api\ApiRequestLogPurgeScope;
use App\Domain\Api\ApiRequestLogQuery;
use App\Exceptions\ValidationException;
use App\Http\Request;
use Closure;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;
use ValueError;

final class ApiRequestLogPurgeRequestParser
{
    private const ALLOWED_AGES = [30, 90, 180, 365];

    private readonly DateTimeZone $timezone;
    private readonly DateTimeZone $utc;
    private readonly Closure $clock;

    public function __construct(string $timezone, ?Closure $clock = null)
    {
        try {
            $this->timezone = new DateTimeZone($timezone);
        } catch (Throwable $exception) {
            throw new \InvalidArgumentException('The API Activity timezone is invalid.', previous: $exception);
        }

        $this->utc = new DateTimeZone('UTC');
        $this->clock = $clock ?? fn (): DateTimeImmutable => new DateTimeImmutable('now', $this->utc);
    }

    public function parse(Request $request, ApiRequestLogQuery $query): ApiRequestLogPurgeCriteria
    {
        $scopeValue = $request->input('scope');

        if (!is_string($scopeValue)) {
            throw new ValidationException('Choose which API Activity records to purge.');
        }

        try {
            $scope = ApiRequestLogPurgeScope::from($scopeValue);
        } catch (ValueError) {
            throw new ValidationException('Choose a valid API Activity purge scope.');
        }

        return match ($scope) {
            ApiRequestLogPurgeScope::MatchingFilters => $this->matchingFilters($query),
            ApiRequestLogPurgeScope::BeforeDate => new ApiRequestLogPurgeCriteria(
                $scope,
                $this->dateCutoff($request),
            ),
            ApiRequestLogPurgeScope::OlderThanAge => new ApiRequestLogPurgeCriteria(
                $scope,
                $this->ageCutoff($request),
            ),
            ApiRequestLogPurgeScope::All => new ApiRequestLogPurgeCriteria($scope),
        };
    }

    private function matchingFilters(ApiRequestLogQuery $query): ApiRequestLogPurgeCriteria
    {
        if (!$query->hasActiveFilters()) {
            throw new ValidationException('Apply at least one filter before choosing “matching current filters”.');
        }

        return ApiRequestLogPurgeCriteria::matching($query);
    }

    private function dateCutoff(Request $request): string
    {
        $value = $request->input('before_date');

        if (!is_string($value) || trim($value) === '') {
            throw new ValidationException('Choose a date for the purge cutoff.');
        }

        $value = trim($value);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $this->timezone);
        $errors = DateTimeImmutable::getLastErrors();

        if (!$date instanceof DateTimeImmutable
            || $date->format('Y-m-d') !== $value
            || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw new ValidationException('The purge cutoff date must use YYYY-MM-DD.');
        }

        return $date->setTimezone($this->utc)->format('Y-m-d H:i:s.u');
    }

    private function ageCutoff(Request $request): string
    {
        $value = $request->input('age_days');
        $days = is_string($value) && ctype_digit($value) ? (int) $value : null;

        if ($days === null || !in_array($days, self::ALLOWED_AGES, true)) {
            throw new ValidationException('The purge age must be 30, 90, 180, or 365 days.');
        }

        $now = ($this->clock)()->setTimezone($this->utc);

        return $now->sub(new DateInterval(sprintf('P%dD', $days)))->format('Y-m-d H:i:s.u');
    }
}
