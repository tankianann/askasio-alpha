<?php

declare(strict_types=1);

namespace App\Services\ProviderQuota;

use App\Domain\ProviderQuota\AiUsageQuery;
use App\Exceptions\ValidationException;
use App\Http\Request;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;

final readonly class AiUsageQueryParser
{
    public function parse(Request $request): AiUsageQuery
    {
        $utc = new DateTimeZone('UTC');
        $today = new DateTimeImmutable('today', $utc);
        $fromValue = $this->string($request, 'date_from') ?? $today->sub(new DateInterval('P29D'))->format('Y-m-d');
        $toValue = $this->string($request, 'date_to') ?? $today->format('Y-m-d');
        $from = $this->date($fromValue, $utc, 'date_from');
        $to = $this->date($toValue, $utc, 'date_to');
        $days = $from->diff($to)->days;

        if ($from > $to || !is_int($days) || $days > 89) {
            throw new ValidationException('AI usage date range must contain between 1 and 90 days.');
        }

        return new AiUsageQuery(
            $fromValue,
            $toValue,
            $from->format('Y-m-d H:i:s'),
            $to->modify('+1 day')->format('Y-m-d H:i:s'),
        );
    }

    private function string(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        if ($value === null || $value === '') {
            return null;
        }

        if (!is_string($value)) {
            throw new ValidationException('The ' . $key . ' parameter must be a string.');
        }

        return trim($value);
    }

    private function date(string $value, DateTimeZone $zone, string $field): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $zone);
        $errors = DateTimeImmutable::getLastErrors();

        if (!$date instanceof DateTimeImmutable
            || $date->format('Y-m-d') !== $value
            || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw new ValidationException('The ' . $field . ' parameter must use YYYY-MM-DD.');
        }

        return $date;
    }
}
