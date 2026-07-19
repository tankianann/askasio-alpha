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

    public function testPrivacyBoundaryExcludesCredentialsBodiesPromptsRetrievedTextAndAnswers(): void
    {
        $repository = new InMemoryApiRequestLogRepository();
        $context = new ApiRequestContext();
        $context->apiKeyId = 17;
        $middleware = new ApiRequestLoggingMiddleware(
            $repository,
            $context,
            new NullLogger(),
            str_repeat('s', 32),
        );
        $request = (new Request(
            'POST',
            '/api/v1/chat?debug_secret=QUERY_SECRET',
            headers: ['authorization' => 'Bearer rag_live_AUTHORIZATION_SECRET'],
            rawBody: '{"question":"PROMPT_SECRET","private_document":"DOCUMENT_SECRET"}',
            clientIp: '198.51.100.77',
        ))->withAttribute('request_id', 'privacy-request');

        $middleware->process($request, static fn (): Response => Response::json([
            'answer' => 'ANSWER_SECRET',
            'citations' => [['excerpt' => 'RETRIEVED_TEXT_SECRET']],
            'usage' => ['retrieved_chunks' => 3, 'output_tokens' => 42],
        ]));

        self::assertCount(1, $repository->logs);
        $stored = serialize($repository->logs[0]);

        foreach ([
            'QUERY_SECRET',
            'AUTHORIZATION_SECRET',
            'PROMPT_SECRET',
            'DOCUMENT_SECRET',
            'ANSWER_SECRET',
            'RETRIEVED_TEXT_SECRET',
            '198.51.100.77',
        ] as $privateValue) {
            self::assertStringNotContainsString($privateValue, $stored);
        }

        self::assertSame('/api/v1/chat', $repository->logs[0]->endpoint);
        self::assertSame(['retrieved_chunks' => 3, 'output_tokens' => 42], $repository->logs[0]->usage);
        self::assertSame([
            'requestId',
            'apiKeyId',
            'ipHash',
            'method',
            'endpoint',
            'statusCode',
            'durationMilliseconds',
            'errorCategory',
            'usage',
            'createdAt',
            'apiKeyName',
            'apiKeyPrefix',
        ], array_keys(get_object_vars($repository->logs[0])));
    }
}
