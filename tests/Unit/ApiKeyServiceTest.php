<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exceptions\ValidationException;
use App\Services\ApiKeys\ApiKeyService;
use PHPUnit\Framework\TestCase;
use Tests\Fakes\InMemoryApiKeyRepository;

final class ApiKeyServiceTest extends TestCase
{
    public function testItGeneratesKeyAndStoresOnlyPrefixAndHash(): void
    {
        $repository = new InMemoryApiKeyRepository();
        $service = new ApiKeyService($repository);
        $created = $service->create(1, 'Website', null);

        self::assertMatchesRegularExpression('/^rag_live_[A-Za-z0-9_-]{43}$/', $created->plaintextKey);
        self::assertStringStartsWith('rag_live_', $created->apiKey->visiblePrefix);
        self::assertSame(64, strlen($created->apiKey->secretHash));
        self::assertSame(hash('sha256', $created->plaintextKey), $created->apiKey->secretHash);
        self::assertStringNotContainsString($created->plaintextKey, $created->apiKey->visiblePrefix);
    }

    public function testItAuthenticatesValidKeyAndRejectsRevokedKey(): void
    {
        $repository = new InMemoryApiKeyRepository();
        $service = new ApiKeyService($repository);
        $created = $service->create(1, 'Website', null);

        self::assertSame($created->apiKey->id, $service->authenticate($created->plaintextKey)?->id);
        self::assertNotNull($repository->findById($created->apiKey->id)?->lastUsedAt);

        $repository->revoke($created->apiKey->id);
        self::assertNull($service->authenticate($created->plaintextKey));
    }

    public function testItRejectsInvalidName(): void
    {
        $this->expectException(ValidationException::class);
        (new ApiKeyService(new InMemoryApiKeyRepository()))->create(1, '   ', null);
    }
}
