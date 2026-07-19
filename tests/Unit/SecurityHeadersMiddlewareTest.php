<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\Middleware\SecurityHeadersMiddleware;
use App\Http\Request;
use App\Http\Response;
use PHPUnit\Framework\TestCase;

final class SecurityHeadersMiddlewareTest extends TestCase
{
    public function testItAddsHstsOnlyToSecureRequestsAndPreventsAdminCaching(): void
    {
        $middleware = new SecurityHeadersMiddleware();
        $next = static fn (Request $request): Response => Response::html('ok');

        $secure = $middleware->process(new Request('GET', '/admin/sources', secure: true), $next);
        $insecure = $middleware->process(new Request('GET', '/api/v1/health'), $next);

        self::assertSame('max-age=31536000', $secure->headers()['Strict-Transport-Security']);
        self::assertSame('no-store', $secure->headers()['Cache-Control']);
        self::assertSame('same-origin', $secure->headers()['Cross-Origin-Resource-Policy']);
        self::assertArrayNotHasKey('Strict-Transport-Security', $insecure->headers());
        self::assertArrayNotHasKey('Cache-Control', $insecure->headers());
    }
}
