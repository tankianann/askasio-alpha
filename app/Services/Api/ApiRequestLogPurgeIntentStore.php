<?php

declare(strict_types=1);

namespace App\Services\Api;

use App\Auth\SessionStoreInterface;
use App\Domain\Api\ApiRequestLogPurgeCriteria;
use App\Domain\Api\ApiRequestLogPurgeIntent;
use App\Domain\Api\ApiRequestLogPurgeSnapshot;
use App\Exceptions\ValidationException;
use Closure;
use Throwable;

final class ApiRequestLogPurgeIntentStore
{
    private const SESSION_KEY = '_api_request_log_purge_intent';
    private readonly Closure $clock;

    public function __construct(
        private readonly SessionStoreInterface $session,
        private readonly int $lifetimeSeconds = 900,
        ?Closure $clock = null,
    ) {
        if ($lifetimeSeconds < 60 || $lifetimeSeconds > 3600) {
            throw new \InvalidArgumentException('Purge confirmation lifetime must be between 60 and 3600 seconds.');
        }

        $this->clock = $clock ?? static fn (): int => time();
    }

    public function create(ApiRequestLogPurgeSnapshot $snapshot): ApiRequestLogPurgeIntent
    {
        $intent = new ApiRequestLogPurgeIntent(bin2hex(random_bytes(32)), $snapshot, ($this->clock)());
        $this->session->put(self::SESSION_KEY, [
            'token' => $intent->token,
            'created_at' => $intent->createdAt,
            'record_count' => $snapshot->recordCount,
            'maximum_id' => $snapshot->maximumId,
            'criteria' => $snapshot->criteria->toArray(),
        ]);

        return $intent;
    }

    public function require(string $token): ApiRequestLogPurgeIntent
    {
        $stored = $this->session->get(self::SESSION_KEY);

        if (!is_array($stored)
            || !isset($stored['token'], $stored['created_at'], $stored['record_count'], $stored['criteria'])
            || !is_string($stored['token'])
            || strlen($stored['token']) !== 64
            || strlen($token) !== 64
            || !hash_equals($stored['token'], $token)
            || !is_int($stored['created_at'])
            || !is_int($stored['record_count'])
            || !is_array($stored['criteria'])) {
            throw new ValidationException('This purge confirmation is invalid. Review the purge scope again.');
        }

        if (($this->clock)() - $stored['created_at'] > $this->lifetimeSeconds) {
            $this->forget();
            throw new ValidationException('This purge confirmation expired. Review the purge scope again.');
        }

        $maximumId = $stored['maximum_id'] ?? null;

        if ($maximumId !== null && !is_int($maximumId)) {
            $this->forget();
            throw new ValidationException('This purge confirmation is invalid. Review the purge scope again.');
        }

        try {
            $criteria = ApiRequestLogPurgeCriteria::fromArray($stored['criteria']);
            $snapshot = new ApiRequestLogPurgeSnapshot($criteria, $stored['record_count'], $maximumId);
        } catch (Throwable) {
            $this->forget();
            throw new ValidationException('This purge confirmation is invalid. Review the purge scope again.');
        }

        return new ApiRequestLogPurgeIntent($stored['token'], $snapshot, $stored['created_at']);
    }

    public function forget(): void
    {
        $this->session->remove(self::SESSION_KEY);
    }
}
