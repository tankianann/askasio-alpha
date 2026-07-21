<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Chatbots\ChatbotIntegrationCredential;
use App\Http\Middleware\ChatbotIntegrationRateLimitMiddleware;
use App\Http\Request;
use App\Http\Response;
use PHPUnit\Framework\TestCase;
use Tests\Fakes\InMemoryApiRateLimitRepository;

final class ChatbotIntegrationRateLimitMiddlewareTest extends TestCase
{
    public function testItEnforcesCredentialAndIpLimitsWithSafeRequestIdErrors(): void
    {
        $rates = new InMemoryApiRateLimitRepository();
        $credential = $this->credential(1);
        $credentialLimited = new ChatbotIntegrationRateLimitMiddleware(
            $rates,
            str_repeat('s', 32),
            60,
            1,
            10,
        );
        $request = $this->request($credential, '192.0.2.10', 'integration-rate-credential');

        $first = $credentialLimited->process($request, static fn (): Response => Response::json(['ok' => true]));
        $second = $credentialLimited->process($request, static fn (): Response => Response::json(['ok' => true]));

        self::assertSame(200, $first->status());
        self::assertSame('0', $first->headers()['X-RateLimit-Remaining']);
        self::assertSame(429, $second->status());
        self::assertSame('0', $second->headers()['X-RateLimit-Remaining']);
        self::assertGreaterThanOrEqual(1, (int) $second->headers()['Retry-After']);
        self::assertStringContainsString('integration-rate-credential', $second->body());

        $ipLimited = new ChatbotIntegrationRateLimitMiddleware(
            new InMemoryApiRateLimitRepository(),
            str_repeat('s', 32),
            60,
            10,
            1,
        );
        $ip = '198.51.100.25';
        $ipLimited->process(
            $this->request($this->credential(2), $ip, 'integration-rate-ip-one'),
            static fn (): Response => Response::json(['ok' => true]),
        );
        $limited = $ipLimited->process(
            $this->request($this->credential(3), $ip, 'integration-rate-ip-two'),
            static fn (): Response => Response::json(['ok' => true]),
        );

        self::assertSame(429, $limited->status());
        self::assertStringContainsString('rate_limit_exceeded', $limited->body());
        self::assertStringNotContainsString($ip, $limited->body());
    }

    private function credential(int $id): ChatbotIntegrationCredential
    {
        return new ChatbotIntegrationCredential(
            $id,
            1,
            'Backend',
            'chatint_live_safe',
            str_repeat('a', 64),
            'active',
            [1],
            0,
            0,
            '2026-07-21 00:00:00',
            null,
            null,
            null,
        );
    }

    private function request(ChatbotIntegrationCredential $credential, string $ip, string $requestId): Request
    {
        return new Request(
            'POST',
            '/api/integrations/v1/chatbots/cb_test/sessions',
            attributes: [
                'chatbot_integration_credential' => $credential,
                'request_id' => $requestId,
            ],
            clientIp: $ip,
        );
    }
}
