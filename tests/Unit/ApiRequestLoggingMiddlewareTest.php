<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\Middleware\ApiRequestLoggingMiddleware;
use App\Http\Request;
use App\Http\Response;
use App\Services\Api\ApiRequestContext;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Tests\Fakes\InMemoryApiRequestLogRepository;

final class ApiRequestLoggingMiddlewareTest extends TestCase
{
    public function testItRecordsMetadataAndNumericUsageWithoutRequestBody(): void
    {
        $repository = new InMemoryApiRequestLogRepository();
        $context = new ApiRequestContext();
        $context->apiKeyId = 7;
        $middleware = new ApiRequestLoggingMiddleware(
            $repository,
            $context,
            new NullLogger(),
            str_repeat('s', 32),
        );
        $request = (new Request(
            'POST',
            '/api/v1/retrieve',
            rawBody: '{"query":"private question"}',
            clientIp: '203.0.113.20',
        ))->withAttribute('request_id', 'request-3');
        $response = $middleware->process(
            $request,
            static fn (): Response => Response::json([
                'usage' => ['retrieved_chunks' => 4, 'ignored' => 'not numeric'],
            ]),
        );

        self::assertSame(200, $response->status());
        self::assertCount(1, $repository->logs);
        $log = $repository->logs[0];
        self::assertSame(7, $log->apiKeyId);
        self::assertSame('/api/v1/retrieve', $log->endpoint);
        self::assertSame(['retrieved_chunks' => 4], $log->usage);
        self::assertSame(64, strlen($log->ipHash));
        self::assertObjectNotHasProperty('question', $log);
    }

    public function testItRecordsReturnedAuthenticationErrors(): void
    {
        $repository = new InMemoryApiRequestLogRepository();
        $middleware = new ApiRequestLoggingMiddleware(
            $repository,
            new ApiRequestContext(),
            new NullLogger(),
            str_repeat('s', 32),
        );
        $request = (new Request('POST', '/api/v1/retrieve'))->withAttribute('request_id', 'request-4');
        $middleware->process($request, static fn (): Response => Response::json([
            'error' => ['code' => 'unauthorized', 'message' => 'No.'],
        ], 401));

        self::assertSame(401, $repository->logs[0]->statusCode);
        self::assertSame('unauthorized', $repository->logs[0]->errorCategory);
        self::assertNull($repository->logs[0]->apiKeyId);
    }
}
