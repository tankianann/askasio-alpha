<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\RAG\EmbeddableChunk;
use App\RAG\EmbeddingBackfillService;
use PHPUnit\Framework\TestCase;
use Tests\Fakes\FakeEmbeddingProvider;
use Tests\Fakes\InMemoryVectorStore;

final class EmbeddingBackfillServiceTest extends TestCase
{
    public function testItEmbedsAndUpsertsPendingActiveChunks(): void
    {
        $provider = new FakeEmbeddingProvider([[1.0, 0.0], [0.0, 1.0]], 'model-a');
        $store = new InMemoryVectorStore(pending: [
            new EmbeddableChunk(10, 'first'),
            new EmbeddableChunk(11, 'second'),
        ]);
        $service = new EmbeddingBackfillService($provider, $store, 50);

        self::assertSame(2, $service->runBatch());
        self::assertSame(['first', 'second'], $provider->inputs);
        self::assertSame(10, $store->upserts[0]->chunkId);
        self::assertSame([0.0, 1.0], $store->upserts[1]->vector);
        self::assertSame('model-a', $store->upserts[1]->model);
    }
}
