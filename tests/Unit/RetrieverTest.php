<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\RAG\RetrievedChunk;
use App\RAG\Retriever;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Tests\Fakes\FakeEmbeddingProvider;
use Tests\Fakes\InMemoryVectorStore;

final class RetrieverTest extends TestCase
{
    public function testItEmbedsQueryAndAppliesModelThresholdAndCallerFilters(): void
    {
        $match = new RetrievedChunk(1, 2, 3, 1, 'content', 0.9, 'Source', 'url', 'https://example.com', []);
        $provider = new FakeEmbeddingProvider([[0.5, 0.5]], 'configured-model');
        $store = new InMemoryVectorStore([$match]);
        $retriever = new Retriever($provider, $store, 8, 20, 0.25);

        self::assertSame([$match], $retriever->retrieve('refund conditions', 5, ['source_ids' => [2]]));
        self::assertSame(['refund conditions'], $provider->inputs);
        self::assertSame([0.5, 0.5], $store->queryEmbedding);
        self::assertSame(5, $store->limit);
        self::assertSame([
            'source_ids' => [2],
            'embedding_model' => 'configured-model',
            'minimum_similarity' => 0.25,
        ], $store->filters);
    }

    public function testItRejectsTopKAboveConfiguredMaximum(): void
    {
        $retriever = new Retriever(new FakeEmbeddingProvider(), new InMemoryVectorStore(), 8, 20, 0.2);
        $this->expectException(InvalidArgumentException::class);
        $retriever->retrieve('query', 21);
    }
}
