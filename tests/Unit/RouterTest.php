<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exceptions\HttpException;
use App\Http\Middleware\MiddlewareInterface;
use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use Closure;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    public function testItMatchesGroupedRoutesAndParameters(): void
    {
        $router = new Router();
        $router->group('/api/v1', [], static function (Router $router): void {
            $router->get('/sources/{sourceId}', static fn (Request $request): Response => Response::json([
                'source_id' => $request->route('sourceId'),
                'route_pattern' => $request->attribute('route_pattern'),
                'route_name' => $request->attribute('route_name'),
            ]), name: 'api.v1.sources.show');
        });

        $response = $router->dispatch(new Request('GET', '/api/v1/sources/42'));

        self::assertSame(200, $response->status());
        self::assertSame([
            'source_id' => '42',
            'route_pattern' => '/api/v1/sources/{sourceId}',
            'route_name' => 'api.v1.sources.show',
        ], json_decode($response->body(), true, flags: JSON_THROW_ON_ERROR));
    }

    public function testMiddlewareWrapsTheMatchedRoute(): void
    {
        $middleware = new class implements MiddlewareInterface {
            public function process(Request $request, Closure $next): Response
            {
                return $next($request)->withHeader('X-Test', 'passed');
            }
        };
        $router = new Router();
        $router->middleware($middleware)->post('/test', static fn (Request $request): Response => Response::html('ok'));

        $response = $router->dispatch(new Request('POST', '/test'));

        self::assertSame('passed', $response->headers()['X-Test']);
    }

    public function testItRejectsUnknownRoutes(): void
    {
        $router = new Router();

        $this->expectException(HttpException::class);
        $this->expectExceptionCode(0);
        $router->dispatch(new Request('GET', '/missing'));
    }
}
