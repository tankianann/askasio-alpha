<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Exceptions\ValidationException;
use App\Http\Request;
use App\Support\Pagination\PageRequest;
use App\Support\SortDirection;
use BackedEnum;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;
use ValueError;

final class AdminListQueryInput
{
    private const MAXIMUM_PAGE = 1_000_000;
    private const ALLOWED_PAGE_SIZES = [25, 50, 100];

    private readonly DateTimeZone $timezone;
    private readonly DateTimeZone $utc;

    public function __construct(
        private readonly Request $request,
        string $timezone,
    ) {
        try {
            $this->timezone = new DateTimeZone($timezone);
        } catch (Throwable $exception) {
            throw new \InvalidArgumentException('The administrator list timezone is invalid.', previous: $exception);
        }

        $this->utc = new DateTimeZone('UTC');
    }

    public function pagination(): PageRequest
    {
        $page = $this->integer('page', 1, 1, self::MAXIMUM_PAGE);
        $perPage = $this->integer('per_page', 25, 1, PHP_INT_MAX);

        if (!in_array($perPage, self::ALLOWED_PAGE_SIZES, true)) {
            throw new ValidationException('The per_page parameter must be 25, 50, or 100.');
        }

        return new PageRequest($page, $perPage, self::ALLOWED_PAGE_SIZES);
    }

    public function text(string $parameter, int $maximumCharacters): ?string
    {
        $value = $this->string($parameter);

        if ($value === null) {
            return null;
        }

        if (mb_strlen($value) > $maximumCharacters || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new ValidationException(sprintf('The %s parameter is invalid.', $parameter));
        }

        return $value;
    }

    public function integer(string $parameter, ?int $default, int $minimum, int $maximum): ?int
    {
        $value = $this->string($parameter);

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

    /** @template T of BackedEnum
     *  @param class-string<T> $enum
     *  @param T $default
     *  @return T
     */
    public function enum(string $parameter, string $enum, BackedEnum $default): BackedEnum
    {
        $value = $this->string($parameter);

        if ($value === null) {
            return $default;
        }

        try {
            return $enum::from($value);
        } catch (ValueError) {
            throw new ValidationException(sprintf('The %s parameter is invalid.', $parameter));
        }
    }

    public function direction(): SortDirection
    {
        /** @var SortDirection */
        return $this->enum('direction', SortDirection::class, SortDirection::Descending);
    }

    /** @return array{?string, ?string, ?string, ?string} */
    public function dateRange(): array
    {
        [$from, $fromUtc] = $this->dateBoundary('date_from', false);
        [$to, $beforeUtc] = $this->dateBoundary('date_to', true);

        if ($from !== null && $to !== null && $from > $to) {
            throw new ValidationException('The date_from parameter must not be after date_to.');
        }

        return [$from, $to, $fromUtc, $beforeUtc];
    }

    /** @return array{?string, ?string} */
    private function dateBoundary(string $parameter, bool $exclusiveEnd): array
    {
        $value = $this->string($parameter);

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

    private function string(string $parameter): ?string
    {
        $value = $this->request->query($parameter);

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
