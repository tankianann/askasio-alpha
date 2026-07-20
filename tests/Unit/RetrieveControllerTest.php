<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\Api\RetrieveController;
use App\Domain\RAG\RetrievedChunk;
use App\Domain\ApiKeys\ApiKey;
use App\Exceptions\HttpException;
use App\Http\Request;
use App\Http\JsonRequestParser;
use App\RAG\Retriever;
use App\Services\ProviderQuota\ProviderQuotaService;
use App\Services\ProviderQuota\ProviderUsageAccumulator;
use PHPUnit\Framework\TestCase;
use Tests\Fakes\FakeEmbeddingProvider;
use Tests\Fakes\InMemoryVectorStore;
use Tests\Fakes\InMemoryProviderQuotaRepository;

final class RetrieveControllerTest extends TestCase
{
    public function testItReturnsStructuredRetrievalMatches(): void
    {
        $match = new RetrievedChunk(
            18, 1, 3, 2, 'Refunds are available within 30 days.', 0.87654321,
            'Refund Policy', 'url', 'https://example.com/refunds', ['section_title' => 'Eligibility'],
        );
        $controller = $this->controller([$match]);
        $request = (new Request(
            'POST',
            '/api/v1/retrieve',
            ['content-type' => 'application/json'],
            rawBody: '{"query":"refund conditions","top_k":10}',
        ))->withAttribute('request_id', 'request-123');
        $response = $controller($request);
        $payload = json_decode($response->body(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->status());
        self::assertSame('request-123', $payload['request_id']);
        self::assertSame(18, $payload['matches'][0]['chunk_id']);
        self::assertSame('Eligibility', $payload['matches'][0]['heading']);
        self::assertSame(0.876543, $payload['matches'][0]['similarity']);
    }

    public function testItRejectsInvalidJson(): void
    {
        $controller = $this->controller();
        $request = new Request(
            'POST',
            '/api/v1/retrieve',
            ['content-type' => 'application/json'],
            rawBody: '{invalid',
        );

        try {
            $controller($request);
            self::fail('Expected invalid JSON to be rejected.');
        } catch (HttpException $exception) {
            self::assertSame(400, $exception->statusCode);
            self::assertSame('invalid_json', $exception->errorCode);
        }
    }

    public function testItRequiresJsonContentType(): void
    {
        $this->expectException(HttpException::class);
        ($this->controller())(new Request('POST', '/api/v1/retrieve', rawBody: '{}'));
    }

    public function testExhaustedQuotaDoesNotCallTheEmbeddingProvider(): void
    {
        $embedding = new FakeEmbeddingProvider();
        $retriever = new Retriever($embedding, new InMemoryVectorStore(), 8, 20, 0.2);
        $quotas = new ProviderQuotaService(new InMemoryProviderQuotaRepository(), 5, 50, 5, 50, 900);
        $controller = new RetrieveController(
            $retriever,
            20,
            4000,
            new JsonRequestParser(65536),
            $quotas,
        );
        $request = (new Request(
            'POST',
            '/api/v1/retrieve',
            ['content-type' => 'application/json'],
            rawBody: '{"query":"refund conditions"}',
        ))->withAttribute('api_key', $this->apiKey());

        try {
            $controller($request);
            self::fail('Expected the exhausted quota to reject the request.');
        } catch (HttpException $exception) {
            self::assertSame(429, $exception->statusCode);
            self::assertSame('quota_exceeded', $exception->errorCode);
        }

        self::assertSame([], $embedding->inputs);
    }

    public function testItReportsReconciledProviderUsage(): void
    {
        $usage = new ProviderUsageAccumulator();
        $usage->recordEmbeddingTokens(7);
        $quotas = new ProviderQuotaService(new InMemoryProviderQuotaRepository(), 1_000, 5_000, 500, 2_500, 900);
        $controller = new RetrieveController(
            new Retriever(new FakeEmbeddingProvider(), new InMemoryVectorStore(), 8, 20, 0.2),
            20,
            4000,
            new JsonRequestParser(65536),
            $quotas,
            $usage,
        );
        $request = (new Request(
            'POST',
            '/api/v1/retrieve',
            ['content-type' => 'application/json'],
            rawBody: '{"query":"refund conditions"}',
        ))->withAttribute('api_key', $this->apiKey());

        $payload = json_decode($controller($request)->body(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(7, $payload['usage']['provider_total_tokens']);
        self::assertSame(7, $payload['usage']['quota_charged_tokens']);
        self::assertSame(493, $payload['usage']['quota_daily_remaining_tokens']);
    }

    /** @param list<RetrievedChunk> $matches */
    private function controller(array $matches = []): RetrieveController
    {
        $retriever = new Retriever(
            new FakeEmbeddingProvider([[1.0, 0.0]]),
            new InMemoryVectorStore($matches),
            8,
            20,
            0.2,
        );

        return new RetrieveController($retriever, 20, 4000, new JsonRequestParser(65536));
    }

    private function apiKey(): ApiKey
    {
        return new ApiKey(7, 1, 'Test connection', 'rag_live_test', hash('sha256', 'secret'), 'active', '2026-01-01 00:00:00', null, null, null);
    }
}
