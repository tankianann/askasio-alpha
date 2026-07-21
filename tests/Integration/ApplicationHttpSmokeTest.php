<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Http\ErrorHandler;
use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Tests\Support\DatabaseIntegrationTestCase;
use Tests\Support\TestDatabase;
use Throwable;

final class ApplicationHttpSmokeTest extends DatabaseIntegrationTestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testApplicationBootsAndItsPublicRoutesUseTheRealMiddlewareStack(): void
    {
        foreach (TestDatabase::applicationEnvironment() as $key => $value) {
            putenv(sprintf('%s=%s', $key, $value));
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }

        $application = require dirname(__DIR__, 2) . '/bootstrap/app.php';
        $router = $application['router'] ?? null;
        $errorHandler = $application['error_handler'] ?? null;

        self::assertInstanceOf(Router::class, $router);
        self::assertInstanceOf(ErrorHandler::class, $errorHandler);

        $health = $this->dispatch($router, $errorHandler, new Request(
            'GET',
            '/api/v1/health',
            attributes: ['request_id' => 'smoke-health'],
        ));
        $healthPayload = json_decode($health->body(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(200, $health->status());
        self::assertSame('ok', $healthPayload['status'] ?? null);
        self::assertSame('ok', $healthPayload['services']['database'] ?? null);
        self::assertSame('nosniff', $health->headers()['X-Content-Type-Options'] ?? null);

        $home = $this->dispatch($router, $errorHandler, new Request(
            'GET',
            '/',
            attributes: ['request_id' => 'smoke-home'],
        ));
        self::assertSame(302, $home->status());
        self::assertSame('/admin', $home->headers()['Location'] ?? null);

        $analytics = $this->dispatch($router, $errorHandler, new Request(
            'GET',
            '/admin/conversations/analytics',
            attributes: ['request_id' => 'smoke-chatbot-analytics'],
        ));
        self::assertSame(302, $analytics->status());
        self::assertSame('/admin/login', $analytics->headers()['Location'] ?? null);

        $retrieve = $this->dispatch($router, $errorHandler, new Request(
            'POST',
            '/api/v1/retrieve',
            headers: ['content-type' => 'application/json'],
            rawBody: '{"query":"must not reach the provider"}',
            attributes: ['request_id' => 'smoke-retrieve'],
            clientIp: '192.0.2.10',
        ));
        $retrievePayload = json_decode($retrieve->body(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(401, $retrieve->status());
        self::assertSame('unauthorized', $retrievePayload['error']['code'] ?? null);
        self::assertSame(1, (int) self::$database?->query(
            "SELECT COUNT(*) FROM api_request_logs WHERE request_id = 'smoke-retrieve' AND status_code = 401",
        )->fetchColumn());

        $missing = $this->dispatch($router, $errorHandler, new Request(
            'GET',
            '/api/v1/not-a-route',
            attributes: ['request_id' => 'smoke-missing'],
        ));
        $missingPayload = json_decode($missing->body(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(404, $missing->status());
        self::assertSame('not_found', $missingPayload['error']['code'] ?? null);
        self::assertSame('smoke-missing', $missingPayload['error']['request_id'] ?? null);
    }

    private function dispatch(Router $router, ErrorHandler $errorHandler, Request $request): Response
    {
        try {
            return $router->dispatch($request);
        } catch (Throwable $exception) {
            return $errorHandler->handle($request, $exception);
        }
    }
}
