<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\Api\ChatController;
use App\Domain\RAG\RetrievedChunk;
use App\Exceptions\HttpException;
use App\Http\JsonRequestParser;
use App\Http\Request;
use App\Ingestion\Chunking\HeuristicTokenEstimator;
use App\Providers\Chat\ChatTimeoutException;
use App\RAG\AnswerGenerator;
use App\RAG\ContextSelector;
use App\RAG\PromptBuilder;
use App\RAG\Retriever;
use PHPUnit\Framework\TestCase;
use Tests\Fakes\FakeChatProvider;
use Tests\Fakes\FakeEmbeddingProvider;
use Tests\Fakes\InMemoryVectorStore;

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
}
