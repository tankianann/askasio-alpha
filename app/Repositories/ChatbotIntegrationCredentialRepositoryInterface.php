<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Domain\Chatbots\ChatbotIntegrationCredential;
use App\Support\Pagination\PageRequest;
use App\Support\Pagination\PaginatedResult;

interface ChatbotIntegrationCredentialRepositoryInterface
{
    /** @return PaginatedResult<ChatbotIntegrationCredential> */
    public function paginate(PageRequest $page): PaginatedResult;
    public function findById(int $id): ?ChatbotIntegrationCredential;
    public function findByHash(string $hash): ?ChatbotIntegrationCredential;
    /** @param list<int> $chatbotIds */
    public function create(int $adminId, string $name, string $prefix, string $hash, ?string $expiresAt, array $chatbotIds): ChatbotIntegrationCredential;
    public function touchUsage(int $id, int $providerTokens = 0): void;
    public function addProviderTokens(int $id, int $providerTokens): void;
    public function revoke(int $id): void;
    public function delete(int $id): void;
}
