<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Domain\ApiKeys\ApiKey;
use App\Repositories\ApiKeyRepositoryInterface;

final class InMemoryApiKeyRepository implements ApiKeyRepositoryInterface
{
    /** @var array<int, ApiKey> */
    private array $keys = [];

    public function all(): array
    {
        return array_values($this->keys);
    }

    public function findById(int $id): ?ApiKey
    {
        return $this->keys[$id] ?? null;
    }

    public function findByHash(string $secretHash): ?ApiKey
    {
        foreach ($this->keys as $key) {
            if (hash_equals($key->secretHash, $secretHash)) {
                return $key;
            }
        }

        return null;
    }

    public function create(
        int $adminId,
        string $name,
        string $visiblePrefix,
        string $secretHash,
        ?string $expiresAt,
    ): ApiKey {
        $id = count($this->keys) + 1;
        $key = new ApiKey(
            $id,
            $adminId,
            $name,
            $visiblePrefix,
            $secretHash,
            'active',
            '2026-01-01 00:00:00',
            null,
            $expiresAt,
            null,
        );
        $this->keys[$id] = $key;

        return $key;
    }

    public function touchLastUsed(int $id): void
    {
        $key = $this->keys[$id];
        $this->keys[$id] = new ApiKey(
            $key->id,
            $key->createdByAdminId,
            $key->name,
            $key->visiblePrefix,
            $key->secretHash,
            $key->status,
            $key->createdAt,
            '2026-01-01 00:01:00',
            $key->expiresAt,
            $key->revokedAt,
        );
    }

    public function revoke(int $id): void
    {
        $key = $this->keys[$id];
        $this->keys[$id] = new ApiKey(
            $key->id,
            $key->createdByAdminId,
            $key->name,
            $key->visiblePrefix,
            $key->secretHash,
            'revoked',
            $key->createdAt,
            $key->lastUsedAt,
            $key->expiresAt,
            '2026-01-01 00:02:00',
        );
    }

    public function delete(int $id): void
    {
        unset($this->keys[$id]);
    }
}
