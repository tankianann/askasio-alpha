<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\Middleware\AdminAuthenticationMiddleware;
use App\Http\Middleware\CsrfMiddleware;
use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use App\Security\CsrfTokenManager;
use PHPUnit\Framework\TestCase;
use Tests\Fakes\InMemoryAdminRepository;
use Tests\Fakes\InMemorySessionStore;

final class ApiRequestLogPurgeSecurityTest extends TestCase
{
    public function testPurgeRouteRequiresAnAuthenticatedAdministrator(): void
    {
        [$router, $called] = $this->router(new InMemorySessionStore(), new InMemoryAdminRepository());
        $response = $router->dispatch(new Request('POST', '/admin/api-requests/purge'));

        self::assertSame(302, $response->status());
        self::assertSame('/admin/login', $response->headers()['Location']);
        self::assertFalse($called());
    }

    public function testPurgeRouteRequiresAValidCsrfToken(): void
    {
        $session = new InMemorySessionStore();
        $admins = new InMemoryAdminRepository();
        $admin = $admins->create('archie', 'hash');
        $session->put(AdminAuthenticationMiddleware::SESSION_ADMIN_ID, $admin->id);
        [$router, $called] = $this->router($session, $admins);

        try {
            $router->dispatch(new Request('POST', '/admin/api-requests/purge'));
            self::fail('The request should have failed CSRF validation.');
        } catch (\App\Exceptions\HttpException $exception) {
            self::assertSame(419, $exception->statusCode);
        }

        self::assertFalse($called());
    }

    public function testPurgeRouteRunsAfterAuthenticationAndCsrfValidation(): void
    {
        $session = new InMemorySessionStore();
        $admins = new InMemoryAdminRepository();
        $admin = $admins->create('archie', 'hash');
        $session->put(AdminAuthenticationMiddleware::SESSION_ADMIN_ID, $admin->id);
        $tokens = new CsrfTokenManager($session);
        [$router, $called] = $this->router($session, $admins, $tokens);
        $response = $router->dispatch(new Request(
            'POST',
            '/admin/api-requests/purge',
            parsedBody: ['_csrf' => $tokens->token()],
        ));

        self::assertSame(204, $response->status());
        self::assertTrue($called());
    }

    /** @return array{Router, callable(): bool} */
    private function router(
        InMemorySessionStore $session,
        InMemoryAdminRepository $admins,
        ?CsrfTokenManager $tokens = null,
    ): array {
        $tokens ??= new CsrfTokenManager($session);
        $state = (object) ['called' => false];
        $router = new Router();
        $router->group('/admin', [
            new AdminAuthenticationMiddleware($session, $admins),
            new CsrfMiddleware($tokens),
        ], static function (Router $router) use ($state): void {
            $router->post('/api-requests/purge', static function (Request $request) use ($state): Response {
                $state->called = true;

                return new Response(status: 204);
            });
        });

        return [$router, static fn (): bool => $state->called];
    }
}
