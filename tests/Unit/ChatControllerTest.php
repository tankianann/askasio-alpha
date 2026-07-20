<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\Api\ChatController;
use App\Domain\RAG\RetrievedChunk;
use App\Domain\ApiKeys\ApiKey;
use App\Exceptions\HttpException;
use App\Http\JsonRequestParser;
use App\Http\Request;
use App\Ingestion\Chunking\HeuristicTokenEstimator;
use App\Providers\Chat\ChatTimeoutException;
use App\RAG\AnswerGenerator;
use App\RAG\ContextSelector;
use App\RAG\PromptBuilder;
use App\RAG\Retriever;
use App\Services\ProviderQuota\ProviderQuotaService;
use App\Services\ProviderQuota\ProviderUsageAccumulator;
use PHPUnit\Framework\TestCase;
use Tests\Fakes\FakeChatProvider;
use Tests\Fakes\FakeEmbeddingProvider;
use Tests\Fakes\InMemoryVectorStore;
use Tests\Fakes\InMemoryProviderQuotaRepository;

final class ChatControllerTest extends TestCase
{
    public function testItReturnsGroundedAnswerCitationsAndUsage(): void
    {
        $chunk = new RetrievedChunk(
            18, 1, 3, 2, 'Refunds are available within 30 days.', 0.9,
            'Refund Policy', 'url', 'https://example.com/refunds', ['section_title' => 'Eligibility'],
        );
        $request = (new Request(
            'POST',
            '/api/v1/chat',
            ['content-type' => 'application/json'],
            rawBody: '{"question":"What is the refund policy?","conversation_id":null,"top_k":5}',
        ))->withAttribute('request_id', 'request-chat-1');
        $response = ($this->controller([$chunk]))($request);
        $payload = json_decode($response->body(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->status());
        self::assertSame('Grounded answer [S1].', $payload['answer']);
        self::assertSame('S1', $payload['citations'][0]['reference']);
        self::assertSame(18, $payload['citations'][0]['chunk_id']);
        self::assertSame('Eligibility', $payload['citations'][0]['heading']);
        self::assertSame(1, $payload['usage']['retrieved_chunks']);
        self::assertSame(160, $payload['usage']['total_tokens']);
        self::assertSame('request-chat-1', $payload['request_id']);
        self::assertSame('no-store', $response->headers()['Cache-Control']);
    }

    public function testItRejectsNonNullConversationIdsUntilConversationStorageExists(): void
    {
        $request = new Request(
            'POST',
            '/api/v1/chat',
            ['content-type' => 'application/json'],
            rawBody: '{"question":"Question","conversation_id":"conversation-1"}',
        );

        try {
            ($this->controller())($request);
            self::fail('Expected conversation_id to be rejected.');
        } catch (HttpException $exception) {
            self::assertSame(422, $exception->statusCode);
            self::assertSame('invalid_request', $exception->errorCode);
        }
    }

    public function testItMapsProviderTimeoutsToSafeGatewayErrors(): void
    {
        $chunk = new RetrievedChunk(1, 1, 1, 1, 'Context', 0.9, 'Source', 'url', null, []);
        $request = new Request(
            'POST',
            '/api/v1/chat',
            ['content-type' => 'application/json'],
            rawBody: '{"question":"Question"}',
        );

        try {
            ($this->controller([$chunk], new FakeChatProvider(failure: new ChatTimeoutException('secret detail'))))($request);
            self::fail('Expected provider timeout to be mapped.');
        } catch (HttpException $exception) {
            self::assertSame(504, $exception->statusCode);
            self::assertSame('provider_timeout', $exception->errorCode);
            self::assertStringNotContainsString('secret detail', $exception->getMessage());
        }
    }

    public function testExhaustedQuotaMakesNoEmbeddingOrChatProviderCall(): void
    {
        $embedding = new FakeEmbeddingProvider();
        $chat = new FakeChatProvider();
        $generator = new AnswerGenerator(
            new Retriever($embedding, new InMemoryVectorStore(), 5, 8, 0.2),
            new ContextSelector(new HeuristicTokenEstimator(), 4000),
            new PromptBuilder(),
            $chat,
        );
        $quotas = new ProviderQuotaService(new InMemoryProviderQuotaRepository(), 100, 1_000, 100, 1_000, 900);
        $controller = new ChatController(
            $generator,
            new JsonRequestParser(65536),
            8,
            4000,
            str_repeat('s', 32),
            $quotas,
            maximumContextTokens: 4000,
            maximumOutputTokens: 600,
        );
        $request = (new Request(
            'POST',
            '/api/v1/chat',
            ['content-type' => 'application/json'],
            rawBody: '{"question":"Question"}',
        ))->withAttribute('api_key', $this->apiKey());

        try {
            $controller($request);
            self::fail('Expected the exhausted quota to reject the request.');
        } catch (HttpException $exception) {
            self::assertSame(429, $exception->statusCode);
            self::assertSame('quota_exceeded', $exception->errorCode);
        }

        self::assertSame([], $embedding->inputs);
        self::assertSame([], $chat->requests);
    }

    public function testItReconcilesAndReportsCombinedEmbeddingAndChatUsage(): void
    {
        $usage = new ProviderUsageAccumulator();
        $usage->recordEmbeddingTokens(4);
        $quotas = new ProviderQuotaService(new InMemoryProviderQuotaRepository(), 50_000, 500_000, 40_000, 400_000, 900);
        $generator = new AnswerGenerator(
            new Retriever(
                new FakeEmbeddingProvider(),
                new InMemoryVectorStore([
                    new RetrievedChunk(1, 1, 1, 1, 'Context', 0.9, 'Source', 'url', null, []),
                ]),
                5,
                8,
                0.2,
            ),
            new ContextSelector(new HeuristicTokenEstimator(), 4000),
            new PromptBuilder(),
            new FakeChatProvider(),
        );
        $controller = new ChatController(
            $generator,
            new JsonRequestParser(65536),
            8,
            4000,
            str_repeat('s', 32),
            $quotas,
            $usage,
            4000,
            600,
        );
        $request = (new Request(
            'POST',
            '/api/v1/chat',
            ['content-type' => 'application/json'],
            rawBody: '{"question":"Question"}',
        ))->withAttribute('api_key', $this->apiKey());

        $payload = json_decode($controller($request)->body(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(164, $payload['usage']['provider_total_tokens']);
        self::assertSame(164, $payload['usage']['quota_charged_tokens']);
        self::assertSame(39_836, $payload['usage']['quota_daily_remaining_tokens']);
    }

    public function testProviderFailureConservativelyChargesTheFullReservation(): void
    {
        $repository = new InMemoryProviderQuotaRepository();
        $quotas = new ProviderQuotaService($repository, 50_000, 500_000, 40_000, 400_000, 900);
        $controller = $this->quotaController(
            $quotas,
            new FakeChatProvider(failure: new ChatTimeoutException('timeout')),
        );
        $request = (new Request(
            'POST',
            '/api/v1/chat',
            ['content-type' => 'application/json'],
            rawBody: '{"question":"Question"}',
        ))->withAttribute('api_key', $this->apiKey());

        try {
            $controller($request);
            self::fail('Expected provider timeout.');
        } catch (HttpException $exception) {
            self::assertSame('provider_timeout', $exception->errorCode);
        }

        $snapshot = $quotas->snapshots([7])['api_keys'][7];
        self::assertGreaterThan(0, $snapshot->dailyConsumed);
        self::assertSame(0, $snapshot->dailyReserved);
    }

    /** @param list<RetrievedChunk> $matches */
    private function controller(array $matches = [], ?FakeChatProvider $chat = null): ChatController
    {
        $generator = new AnswerGenerator(
            new Retriever(new FakeEmbeddingProvider(), new InMemoryVectorStore($matches), 5, 8, 0.2),
            new ContextSelector(new HeuristicTokenEstimator(), 4000),
            new PromptBuilder(),
            $chat ?? new FakeChatProvider(),
        );

        return new ChatController(
            $generator,
            new JsonRequestParser(65536),
            8,
            4000,
            str_repeat('s', 32),
        );
    }

    private function apiKey(): ApiKey
    {
        return new ApiKey(7, 1, 'Test connection', 'rag_live_test', hash('sha256', 'secret'), 'active', '2026-01-01 00:00:00', null, null, null);
    }

    private function quotaController(ProviderQuotaService $quotas, FakeChatProvider $chat): ChatController
    {
        $generator = new AnswerGenerator(
            new Retriever(
                new FakeEmbeddingProvider(),
                new InMemoryVectorStore([
                    new RetrievedChunk(1, 1, 1, 1, 'Context', 0.9, 'Source', 'url', null, []),
                ]),
                5,
                8,
                0.2,
            ),
            new ContextSelector(new HeuristicTokenEstimator(), 256),
            new PromptBuilder(),
            $chat,
        );

        return new ChatController(
            $generator,
            new JsonRequestParser(65536),
            8,
            4000,
            str_repeat('s', 32),
            $quotas,
            maximumContextTokens: 256,
            maximumOutputTokens: 64,
        );
    }
}
