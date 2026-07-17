<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\ApiKeys\ApiKey;
use App\Http\Middleware\ApiKeyAuthenticationMiddleware;
use App\Http\Request;
use App\Http\Response;
use App\Services\Api\ApiRequestContext;
use App\Services\ApiKeys\ApiKeyService;
use PHPUnit\Framework\TestCase;
use Tests\Fakes\InMemoryApiKeyRepository;

final class ApiKeyAuthenticationMiddlewareTest extends TestCase
{
    public function testItAuthenticatesBearerKeyAndAddsRequestAttribute(): void
    {
        $repository = new InMemoryApiKeyRepository();
        $service = new ApiKeyService($repository);
        $created = $service->create(1, 'Client', null);
        $context = new ApiRequestContext();
        $middleware = new ApiKeyAuthenticationMiddleware($service, $context);
        $request = (new Request(
            'POST',
            '/api/v1/retrieve',
            ['authorization' => 'Bearer ' . $created->plaintextKey],
        ))->withAttribute('request_id', 'request-1');

        $response = $middleware->process($request, static function (Request $request): Response {
            $key = $request->attribute('api_key');
            self::assertInstanceOf(ApiKey::class, $key);

            return Response::json(['ok' => true]);
        });

        self::assertSame(200, $response->status());
        self::assertSame($created->apiKey->id, $context->apiKeyId);
    }

    public function testItReturnsConsistentUnauthorizedResponseWithoutKey(): void
    {
        $middleware = new ApiKeyAuthenticationMiddleware(
            new ApiKeyService(new InMemoryApiKeyRepository()),
            new ApiRequestContext(),
        );
        $request = (new Request('POST', '/api/v1/retrieve'))->withAttribute('request_id', 'request-2');
        $response = $middleware->process($request, static fn (): Response => Response::json(['unexpected' => true]));
        $payload = json_decode($response->body(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(401, $response->status());
        self::assertSame('unauthorized', $payload['error']['code']);
        self::assertSame('request-2', $payload['error']['request_id']);
        self::assertArrayHasKey('WWW-Authenticate', $response->headers());
    }
}
