<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\Middleware\ApiRequestLoggingMiddleware;
use App\Domain\Api\ApiAccessMethod;
use App\Domain\Chatbots\ChatbotIntegrationCredential;
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
        self::assertSame(ApiAccessMethod::GeneralApiKey, $log->accessMethod);
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
        self::assertSame(ApiAccessMethod::Unauthenticated, $repository->logs[0]->accessMethod);
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
            'accessMethod',
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
            'chatbotApiKeyId',
            'chatbotApiKeyName',
            'chatbotApiKeyPrefix',
            'chatbotId',
            'chatbotName',
        ], array_keys(get_object_vars($repository->logs[0])));
    }

    public function testItStoresTheMatchedRouteTemplateInsteadOfPublicResourceIdentifiers(): void
    {
        $repository = new InMemoryApiRequestLogRepository();
        $middleware = new ApiRequestLoggingMiddleware(
            $repository,
            new ApiRequestContext(),
            new NullLogger(),
            str_repeat('s', 32),
        );
        $request = (new Request(
            'POST',
            '/api/public/v1/chatbots/cb_PUBLIC_IDENTIFIER/sessions/cs_SESSION_IDENTIFIER/messages',
        ))
            ->withAttribute('request_id', 'route-template-request')
            ->withAttribute(
                'route_pattern',
                '/api/public/v1/chatbots/{chatbotPublicId}/sessions/{sessionId}/messages',
            );

        $middleware->process($request, static fn (): Response => Response::json(['ok' => true]));

        self::assertSame(
            '/api/public/v1/chatbots/{chatbotPublicId}/sessions/{sessionId}/messages',
            $repository->logs[0]->endpoint,
        );
        self::assertSame(ApiAccessMethod::BrowserChatbot, $repository->logs[0]->accessMethod);
        self::assertStringNotContainsString('PUBLIC_IDENTIFIER', serialize($repository->logs[0]));
        self::assertStringNotContainsString('SESSION_IDENTIFIER', serialize($repository->logs[0]));
    }

    public function testItAttributesAuthenticatedChatbotApiKeysWithoutLoggingTheSecret(): void
    {
        $repository = new InMemoryApiRequestLogRepository();
        $middleware = new ApiRequestLoggingMiddleware(
            $repository,
            new ApiRequestContext(),
            new NullLogger(),
            str_repeat('s', 32),
        );
        $credential = new ChatbotIntegrationCredential(
            14, 1, 'CRM', 'chatint_live_safe', hash('sha256', 'secret'), 'active', [3], 0, 0,
            '2026-07-21 00:00:00', null, null, null,
        );
        $request = (new Request(
            'POST',
            '/api/integrations/v1/chatbots/cb_secret/sessions',
            headers: ['authorization' => 'Bearer chatint_live_PRIVATE'],
        ))
            ->withAttribute('request_id', 'chatbot-key-request')
            ->withAttribute('chatbot_integration_credential', $credential);

        $middleware->process($request, static fn (): Response => Response::json(['ok' => true]));

        self::assertSame(ApiAccessMethod::ChatbotApiKey, $repository->logs[0]->accessMethod);
        self::assertSame(14, $repository->logs[0]->chatbotApiKeyId);
        self::assertStringNotContainsString('chatint_live_PRIVATE', serialize($repository->logs[0]));
    }
}
