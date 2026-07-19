<?php

declare(strict_types=1);

namespace App\Services\Api;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;

final class ApiRequestLogRetentionPolicy
{
    public const KEEP_FOREVER = 0;

    /** @var list<int> */
    public const ALLOWED_DAYS = [self::KEEP_FOREVER, 30, 90, 180, 365];

    public function __construct(public readonly int $days)
    {
        if (!in_array($days, self::ALLOWED_DAYS, true)) {
            throw new \InvalidArgumentException('API request log retention must be 0, 30, 90, 180, or 365 days.');
        }
    }

    public function keepsForever(): bool
    {
        return $this->days === self::KEEP_FOREVER;
    }

    public function cutoff(DateTimeImmutable $now): ?DateTimeImmutable
    {
        if ($this->keepsForever()) {
            return null;
        }

        return $now
            ->setTimezone(new DateTimeZone('UTC'))
            ->sub(new DateInterval(sprintf('P%dD', $this->days)));
    }
}
