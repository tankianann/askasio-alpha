<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Domain\ApiKeys\ApiKey;
use App\Domain\ApiKeys\ApiKeyListQuery;
use App\Domain\ApiKeys\ApiKeyListSort;
use App\Domain\ApiKeys\ApiKeyListStatus;
use App\Repositories\ApiKeyRepositoryInterface;
use App\Support\Pagination\PaginatedResult;
use App\Support\SortDirection;

final class InMemoryApiKeyRepository implements ApiKeyRepositoryInterface
{
    /** @var array<int, ApiKey> */
    private array $keys = [];

    public function all(): array
    {
        return array_values($this->keys);
    }

    public function paginate(ApiKeyListQuery $query): PaginatedResult
    {
        $keys = array_values(array_filter($this->keys, static function (ApiKey $key) use ($query): bool {
            if ($query->search !== null
                && mb_stripos($key->name, $query->search) === false
                && mb_stripos($key->visiblePrefix, $query->search) === false) {
                return false;
            }

            return $query->status === ApiKeyListStatus::All || $key->displayStatus() === $query->status->value;
        }));
        $direction = $query->direction === SortDirection::Ascending ? 1 : -1;
        usort($keys, static function (ApiKey $left, ApiKey $right) use ($query, $direction): int {
            $comparison = match ($query->sort) {
                ApiKeyListSort::Created => strcmp($left->createdAt, $right->createdAt),
                ApiKeyListSort::Name => strcasecmp($left->name, $right->name),
                ApiKeyListSort::Status => strcmp($left->displayStatus(), $right->displayStatus()),
                ApiKeyListSort::LastUsed => strcmp((string) $left->lastUsedAt, (string) $right->lastUsedAt),
                ApiKeyListSort::Expires => strcmp((string) $left->expiresAt, (string) $right->expiresAt),
            };

            return ($comparison !== 0 ? $comparison : $left->id <=> $right->id) * $direction;
        });
        $total = count($keys);
        $pageRequest = $query->pagination->clampToTotal($total);

        return new PaginatedResult(
            array_slice($keys, $pageRequest->offset(), $pageRequest->perPage),
            $total,
            $pageRequest,
        );
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
