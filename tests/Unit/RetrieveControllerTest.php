<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\Api\RetrieveController;
use App\Domain\RAG\RetrievedChunk;
use App\Exceptions\HttpException;
use App\Http\Request;
use App\RAG\Retriever;
use PHPUnit\Framework\TestCase;
use Tests\Fakes\FakeEmbeddingProvider;
use Tests\Fakes\InMemoryVectorStore;

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

        return new RetrieveController($retriever, 20, 4000, 65536);
    }
}
