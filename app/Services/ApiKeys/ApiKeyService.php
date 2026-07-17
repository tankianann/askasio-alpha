<?php

declare(strict_types=1);

namespace App\Services\ApiKeys;

use App\Domain\ApiKeys\ApiKey;
use App\Domain\ApiKeys\CreatedApiKey;
use App\Exceptions\ValidationException;
use App\Repositories\ApiKeyRepositoryInterface;
use DateTimeImmutable;
use DateTimeZone;

final class ApiKeyService
{
    private const KEY_PREFIX = 'rag_live_';

    public function __construct(private readonly ApiKeyRepositoryInterface $keys)
    {
    }

    public function create(int $adminId, string $name, ?string $expiresAt): CreatedApiKey
    {
        $name = trim($name);

        if ($name === '' || mb_strlen($name) > 190) {
            throw new ValidationException('API key name must contain between 1 and 190 characters.');
        }

        if ($adminId < 1) {
            throw new \InvalidArgumentException('A valid administrator ID is required.');
        }

        if ($expiresAt !== null) {
            $expiry = new DateTimeImmutable($expiresAt, new DateTimeZone('UTC'));

            if ($expiry <= new DateTimeImmutable('now', new DateTimeZone('UTC'))) {
                throw new ValidationException('API key expiry must be in the future.');
            }

            $expiresAt = $expiry->format('Y-m-d H:i:s');
        }

        $secret = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $plaintext = self::KEY_PREFIX . $secret;
        $visiblePrefix = self::KEY_PREFIX . substr($secret, 0, 8);
        $hash = hash('sha256', $plaintext);
        $apiKey = $this->keys->create($adminId, $name, $visiblePrefix, $hash, $expiresAt);

        return new CreatedApiKey($apiKey, $plaintext);
    }

    public function authenticate(string $plaintextKey): ?ApiKey
    {
        if (!str_starts_with($plaintextKey, self::KEY_PREFIX) || strlen($plaintextKey) > 128) {
            return null;
        }

        $computedHash = hash('sha256', $plaintextKey);
        $apiKey = $this->keys->findByHash($computedHash);

        if (!$apiKey instanceof ApiKey
            || !hash_equals($apiKey->secretHash, $computedHash)
            || !$apiKey->isUsable()) {
            return null;
        }

        $this->keys->touchLastUsed($apiKey->id);

        return $apiKey;
    }
}
