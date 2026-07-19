<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Domain\ApiKeys\ApiKey;
use App\Domain\ApiKeys\ApiKeyListQuery;
use App\Support\Pagination\PaginatedResult;

interface ApiKeyRepositoryInterface
{
    /** @return list<ApiKey> */
    public function all(): array;

    /** @return PaginatedResult<ApiKey> */
    public function paginate(ApiKeyListQuery $query): PaginatedResult;

    public function findById(int $id): ?ApiKey;

    public function findByHash(string $secretHash): ?ApiKey;

    public function create(
        int $adminId,
        string $name,
        string $visiblePrefix,
        string $secretHash,
        ?string $expiresAt,
    ): ApiKey;

    public function touchLastUsed(int $id): void;

    public function revoke(int $id): void;

    public function delete(int $id): void;
}
