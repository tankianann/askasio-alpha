<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\Api\PublicChatbotController;
use App\Domain\Chatbots\ChatbotAssignments;
use App\Domain\Chatbots\ChatbotProviderConfiguration;
use App\Domain\Chatbots\ChatbotSessionCredentials;
use App\Domain\Chatbots\ChatbotStatus;
use App\Http\ErrorHandler;
use App\Http\JsonRequestParser;
use App\Http\Middleware\PublicChatbotCorsMiddleware;
use App\Http\Middleware\PublicChatbotRateLimitMiddleware;
use App\Http\Middleware\RequestIdMiddleware;
use App\Http\Middleware\SecurityHeadersMiddleware;
use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use App\Services\Chatbots\ChatbotConversationService;
use App\Services\Chatbots\ChatbotOriginNormalizer;
use App\Services\Chatbots\ChatbotSessionCredentialGeneratorInterface;
use App\Services\Chatbots\PublicChatbotAccessService;
use App\Services\Chatbots\PublicChatbotRateLimiter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Tests\Fakes\InMemoryApiRateLimitRepository;
use Tests\Fakes\InMemoryChatbotConversationRepository;
use Tests\Fakes\InMemoryChatbotRepository;
use Tests\Support\ChatbotFixtures;

final class PublicChatbotApiTest extends TestCase
{
    private const PUBLIC_ID = 'cb_AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';
    private const ORIGIN = 'https://example.com';

    public function testConfigurationReturnsOnlyThePublishedPublicDtoWithExactCorsHeaders(): void
    {
        $fixture = $this->fixture();
        $response = $fixture['router']->dispatch($this->request(
            'GET',
            '/api/public/v1/chatbots/' . self::PUBLIC_ID . '/config',
            ['origin' => 'https://EXAMPLE.com:443'],
            requestId: 'public-config-1',
        ));
        $payload = $this->payload($response);

        self::assertSame(200, $response->status());
        self::assertSame('https://EXAMPLE.com:443', $response->headers()['Access-Control-Allow-Origin']);
        self::assertNotSame('*', $response->headers()['Access-Control-Allow-Origin']);
        self::assertArrayNotHasKey('Access-Control-Allow-Credentials', $response->headers());
        self::assertSame('Origin', $response->headers()['Vary']);
        self::assertSame('cross-origin', $response->headers()['Cross-Origin-Resource-Policy']);
        self::assertSame('no-store', $response->headers()['Cache-Control']);
        self::assertSame('v1', $payload['api_version']);
        self::assertSame('public-config-1', $payload['request_id']);
        self::assertSame([
            'id', 'configuration_version', 'display_name', 'welcome_message',
            'suggested_questions', 'input_placeholder', 'appearance', 'capabilities',
            'maximum_message_characters', 'privacy_notice_url', 'disclosure_text',
        ], array_keys($payload['chatbot']));
        self::assertSame(self::PUBLIC_ID, $payload['chatbot']['id']);
        self::assertSame('Support assistant', $payload['chatbot']['display_name']);
        self::assertFalse($payload['chatbot']['capabilities']['streaming']);
        self::assertStringNotContainsString('system_instructions', $response->body());
        self::assertStringNotContainsString('chat_model', $response->body());
        self::assertStringNotContainsString('source_ids', $response->body());
    }

    public function testSessionCreationReturnsTheBearerOnceAndPersistsOnlyItsHash(): void
    {
        $fixture = $this->fixture();
        $response = $fixture['router']->dispatch($this->request(
            'POST',
            '/api/public/v1/chatbots/' . self::PUBLIC_ID . '/sessions',
            ['origin' => self::ORIGIN, 'content-type' => 'application/json'],
            '{}',
            'public-session-1',
        ));
        $payload = $this->payload($response);

        self::assertSame(201, $response->status());
        self::assertMatchesRegularExpression('/\Acs_[A-Za-z0-9_-]{43}\z/', $payload['session_id']);
        self::assertMatchesRegularExpression('/\Acst_v1_[A-Za-z0-9_-]{43}\z/', $payload['session_token']);
        self::assertSame('2026-07-20T10:30:00Z', $payload['idle_expires_at']);
        self::assertSame('2026-07-20T18:00:00Z', $payload['absolute_expires_at']);
        self::assertSame('no-cache', $response->headers()['Pragma']);
        $stored = $fixture['conversations']->findSessionByPublicId($payload['session_id']);
        self::assertNotNull($stored);
        self::assertSame(hash('sha256', $payload['session_token']), $stored->tokenHash);
        self::assertNotSame($payload['session_token'], $stored->tokenHash);
        self::assertSame(self::ORIGIN, $stored->normalizedOrigin);
        self::assertFalse($stored->isTest);
        self::assertNotNull($stored->publicationId);
    }

    /** @return iterable<string, array{string, string, int, string}> */
    public static function invalidSessionRequests(): iterable
    {
        yield 'wrong media type' => ['text/plain', '{}', 415, 'unsupported_media_type'];
        yield 'malformed JSON' => ['application/json', '{', 400, 'invalid_json'];
        yield 'list body' => ['application/json', '[]', 400, 'invalid_request'];
        yield 'unknown field' => ['application/json', '{"external_user_id":"visitor"}', 422, 'invalid_request'];
        yield 'oversized body' => ['application/json', '{"padding":"' . str_repeat('x', 140) . '"}', 413, 'request_too_large'];
    }

    #[DataProvider('invalidSessionRequests')]
    public function testSessionCreationUsesAStrictEmptyObjectSchema(
        string $contentType,
        string $body,
        int $status,
        string $code,
    ): void {
        $fixture = $this->fixture();
        $response = $fixture['router']->dispatch($this->request(
            'POST',
            '/api/public/v1/chatbots/' . self::PUBLIC_ID . '/sessions',
            ['origin' => self::ORIGIN, 'content-type' => $contentType],
            $body,
            'strict-session',
        ));
        $payload = $this->payload($response);

        self::assertSame($status, $response->status());
        self::assertSame($code, $payload['error']['code']);
        self::assertSame('strict-session', $payload['error']['request_id']);
        self::assertSame(self::ORIGIN, $response->headers()['Access-Control-Allow-Origin']);
    }

    /** @return iterable<string, array{?string}> */
    public static function forbiddenOrigins(): iterable
    {
        yield 'missing' => [null];
        yield 'opaque' => ['null'];
        yield 'HTTP remote' => ['http://example.com'];
        yield 'subdomain' => ['https://support.example.com'];
        yield 'suffix confusion' => ['https://example.com.attacker.test'];
        yield 'wrong port' => ['https://example.com:8443'];
    }

    #[DataProvider('forbiddenOrigins')]
    public function testOriginPolicyRejectsMissingInvalidAndNonExactOrigins(?string $origin): void
    {
        $fixture = $this->fixture();
        $headers = $origin === null ? [] : ['origin' => $origin];
        $response = $fixture['router']->dispatch($this->request(
            'GET',
            '/api/public/v1/chatbots/' . self::PUBLIC_ID . '/config',
            $headers,
            requestId: 'origin-denied',
        ));
        $payload = $this->payload($response);

        self::assertSame(403, $response->status());
        self::assertSame('origin_not_allowed', $payload['error']['code']);
        self::assertArrayNotHasKey('Access-Control-Allow-Origin', $response->headers());
        self::assertSame('Origin', $response->headers()['Vary']);
    }

    public function testUnknownDisabledAndUnpublishedChatbotsUseTheSameSafeNotFoundError(): void
    {
        $fixture = $this->fixture();
        $disabled = $fixture['chatbots']->setStatus(1, ChatbotStatus::Disabled);

        foreach ([self::PUBLIC_ID, 'cb_BBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB'] as $publicId) {
            $response = $fixture['router']->dispatch($this->request(
                'GET',
                '/api/public/v1/chatbots/' . $publicId . '/config',
                ['origin' => self::ORIGIN],
                requestId: 'hidden-chatbot',
            ));
            self::assertSame(404, $response->status());
            self::assertSame('chatbot_not_found', $this->payload($response)['error']['code']);
        }

        self::assertSame(ChatbotStatus::Disabled, $disabled->status);
    }

    public function testAllowedPreflightIsContentFreeAndRejectsUnsupportedHeaders(): void
    {
        $fixture = $this->fixture();
        $path = '/api/public/v1/chatbots/' . self::PUBLIC_ID . '/sessions';
        $allowed = $fixture['router']->dispatch($this->request('OPTIONS', $path, [
            'origin' => self::ORIGIN,
            'access-control-request-method' => 'POST',
            'access-control-request-headers' => 'content-type',
        ], requestId: 'preflight-allowed'));

        self::assertSame(204, $allowed->status());
        self::assertSame('', $allowed->body());
        self::assertSame('POST', $allowed->headers()['Access-Control-Allow-Methods']);
        self::assertSame('Content-Type', $allowed->headers()['Access-Control-Allow-Headers']);
        self::assertSame(self::ORIGIN, $allowed->headers()['Access-Control-Allow-Origin']);

        $denied = $fixture['router']->dispatch($this->request('OPTIONS', $path, [
            'origin' => self::ORIGIN,
            'access-control-request-method' => 'POST',
            'access-control-request-headers' => 'authorization',
        ], requestId: 'preflight-denied'));

        self::assertSame(403, $denied->status());
        self::assertSame('cors_preflight_rejected', $this->payload($denied)['error']['code']);
        self::assertSame(self::ORIGIN, $denied->headers()['Access-Control-Allow-Origin']);

        $wrongMethod = $fixture['router']->dispatch($this->request('OPTIONS', $path, [
            'origin' => self::ORIGIN,
            'access-control-request-method' => 'GET',
        ], requestId: 'preflight-method'));
        self::assertSame(403, $wrongMethod->status());
        self::assertSame('cors_preflight_rejected', $this->payload($wrongMethod)['error']['code']);
    }

    public function testStaleProviderConfigurationFailsSafelyInsideTheAllowedCorsBoundary(): void
    {
        $fixture = $this->fixture(installationProvider: new ChatbotProviderConfiguration(
            'openai',
            'changed-chat-model',
            'openai',
            'text-embedding-test',
            1536,
        ));
        $response = $fixture['router']->dispatch($this->request(
            'GET',
            '/api/public/v1/chatbots/' . self::PUBLIC_ID . '/config',
            ['origin' => self::ORIGIN],
            requestId: 'stale-provider',
        ));

        self::assertSame(503, $response->status());
        self::assertSame('knowledge_unavailable', $this->payload($response)['error']['code']);
        self::assertSame(self::ORIGIN, $response->headers()['Access-Control-Allow-Origin']);
        self::assertStringNotContainsString('changed-chat-model', $response->body());
    }

    public function testRateLimitIsReturnedInsideTheApprovedCorsBoundary(): void
    {
        $fixture = $this->fixture(perIpLimit: 1, perChatbotLimit: 10);
        $path = '/api/public/v1/chatbots/' . self::PUBLIC_ID . '/config';
        $fixture['router']->dispatch($this->request(
            'GET', $path, ['origin' => self::ORIGIN], requestId: 'rate-first', clientIp: '192.0.2.5',
        ));
        $limited = $fixture['router']->dispatch($this->request(
            'GET', $path, ['origin' => self::ORIGIN], requestId: 'rate-second', clientIp: '192.0.2.5',
        ));

        self::assertSame(429, $limited->status());
        self::assertSame('rate_limit_exceeded', $this->payload($limited)['error']['code']);
        self::assertSame('0', $limited->headers()['X-RateLimit-Remaining']);
        self::assertGreaterThanOrEqual(1, (int) $limited->headers()['Retry-After']);
        self::assertSame(self::ORIGIN, $limited->headers()['Access-Control-Allow-Origin']);
    }

    public function testChatbotRateLimitAggregatesAcrossDifferentClientIps(): void
    {
        $fixture = $this->fixture(perIpLimit: 10, perChatbotLimit: 1);
        $path = '/api/public/v1/chatbots/' . self::PUBLIC_ID . '/config';
        $fixture['router']->dispatch($this->request(
            'GET', $path, ['origin' => self::ORIGIN], requestId: 'chatbot-first', clientIp: '192.0.2.10',
        ));
        $limited = $fixture['router']->dispatch($this->request(
            'GET', $path, ['origin' => self::ORIGIN], requestId: 'chatbot-second', clientIp: '192.0.2.11',
        ));

        self::assertSame(429, $limited->status());
        self::assertSame('rate_limit_exceeded', $this->payload($limited)['error']['code']);
        self::assertSame('0', $limited->headers()['X-RateLimit-Remaining']);
    }

    /**
     * @return array{
     *   router: Router,
     *   chatbots: InMemoryChatbotRepository,
     *   conversations: InMemoryChatbotConversationRepository
     * }
     */
    private function fixture(
        int $perIpLimit = 100,
        int $perChatbotLimit = 100,
        ?ChatbotProviderConfiguration $installationProvider = null,
    ): array {
        $provider = ChatbotFixtures::provider();
        $chatbots = new InMemoryChatbotRepository();
        $chatbots->defineSource(1);
        $chatbot = $chatbots->create(self::PUBLIC_ID, 'Public chatbot', null, ChatbotFixtures::draft());
        $chatbot = $chatbots->replaceDraftAssignments(
            $chatbot->id,
            1,
            new ChatbotAssignments([1], [self::ORIGIN]),
        );
        $publication = $chatbots->publish(
            $chatbot->id,
            2,
            $chatbot->draft,
            $chatbot->assignments,
            $provider,
            str_repeat('a', 64),
        );
        $conversations = new InMemoryChatbotConversationRepository();
        $conversations->defineChatbot(
            $chatbot->id,
            $publication->id,
            origins: [self::ORIGIN],
            maximumMessages: $chatbot->draft->maximumMessagesPerSession,
            maximumCharacters: $chatbot->draft->maximumMessageCharacters,
            idleMinutes: $chatbot->draft->idleExpiryMinutes,
            absoluteMinutes: $chatbot->draft->absoluteExpiryMinutes,
            retentionDays: $chatbot->draft->retentionDays,
        );
        $credentials = new ChatbotSessionCredentials(
            'cs_' . str_repeat('S', 43),
            'cst_v1_' . str_repeat('T', 43),
            'cst_v1_' . str_repeat('T', 8),
            hash('sha256', 'cst_v1_' . str_repeat('T', 43)),
        );
        $generator = new class($credentials) implements ChatbotSessionCredentialGeneratorInterface {
            public function __construct(private readonly ChatbotSessionCredentials $credentials)
            {
            }

            public function generate(): ChatbotSessionCredentials
            {
                return $this->credentials;
            }
        };
        $controller = new PublicChatbotController(
            new ChatbotConversationService($conversations, $generator),
            new JsonRequestParser(128),
            static fn (): \DateTimeImmutable => new \DateTimeImmutable('2026-07-20 10:00:00 UTC'),
        );
        $access = new PublicChatbotAccessService(
            $chatbots,
            new ChatbotOriginNormalizer(),
            $installationProvider ?? $provider,
            true,
        );
        $errors = new ErrorHandler(new NullLogger(), dirname(__DIR__, 2) . '/resources/views/errors');
        $rateRepository = new InMemoryApiRateLimitRepository();
        $configCors = new PublicChatbotCorsMiddleware($access, $errors, ['GET'], []);
        $sessionCors = new PublicChatbotCorsMiddleware($access, $errors, ['POST'], ['Content-Type']);
        $configRate = new PublicChatbotRateLimitMiddleware(new PublicChatbotRateLimiter(
            $rateRepository,
            str_repeat('s', 32),
            60,
            $perIpLimit,
            $perChatbotLimit,
            'test_config',
        ));
        $sessionRate = new PublicChatbotRateLimitMiddleware(new PublicChatbotRateLimiter(
            $rateRepository,
            str_repeat('s', 32),
            60,
            $perIpLimit,
            $perChatbotLimit,
            'test_session',
        ));
        $router = new Router();
        $router->middleware(new RequestIdMiddleware())->middleware(new SecurityHeadersMiddleware());
        $configPath = '/api/public/v1/chatbots/{chatbotPublicId}/config';
        $sessionPath = '/api/public/v1/chatbots/{chatbotPublicId}/sessions';
        $router->get($configPath, [$controller, 'configuration'], [$configCors, $configRate]);
        $router->add('OPTIONS', $configPath, static fn (Request $request): Response => new Response('', 204), [$configCors]);
        $router->post($sessionPath, [$controller, 'createSession'], [$sessionCors, $sessionRate]);
        $router->add('OPTIONS', $sessionPath, static fn (Request $request): Response => new Response('', 204), [$sessionCors]);

        return compact('router', 'chatbots', 'conversations');
    }

    /** @param array<string, string> $headers */
    private function request(
        string $method,
        string $path,
        array $headers = [],
        string $body = '',
        string $requestId = 'public-request',
        string $clientIp = '192.0.2.1',
    ): Request {
        return new Request(
            $method,
            $path,
            $headers,
            rawBody: $body,
            attributes: ['request_id' => $requestId],
            clientIp: $clientIp,
            secure: true,
        );
    }

    /** @return array<string, mixed> */
    private function payload(Response $response): array
    {
        return json_decode($response->body(), true, flags: JSON_THROW_ON_ERROR);
    }
}
