<?php

declare(strict_types=1);

namespace App\Http;

use App\Exceptions\HttpException;
use App\Http\Middleware\MiddlewareInterface;
use Closure;
use InvalidArgumentException;

final class Router
{
    /** @var list<Route> */
    private array $routes = [];

    /** @var list<MiddlewareInterface> */
    private array $globalMiddleware = [];

    private string $groupPrefix = '';

    /** @var list<MiddlewareInterface> */
    private array $groupMiddleware = [];

    public function middleware(MiddlewareInterface $middleware): self
    {
        $this->globalMiddleware[] = $middleware;

        return $this;
    }

    /**
     * @param string|list<string> $methods
     * @param callable(Request): Response $handler
     * @param list<MiddlewareInterface> $middleware
     */
    public function add(string|array $methods, string $path, callable $handler, array $middleware = [], ?string $name = null): self
    {
        $normalizedMethods = array_values(array_unique(array_map('strtoupper', (array) $methods)));
        $allowedMethods = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'HEAD'];

        foreach ($normalizedMethods as $method) {
            if (!in_array($method, $allowedMethods, true)) {
                throw new InvalidArgumentException(sprintf('Unsupported HTTP method: %s', $method));
            }
        }

        $fullPath = '/' . trim($this->groupPrefix . '/' . ltrim($path, '/'), '/');
        $fullPath = $fullPath === '' ? '/' : $fullPath;
        $routeMiddleware = [...$this->groupMiddleware, ...$middleware];
        $this->routes[] = new Route($normalizedMethods, $fullPath, Closure::fromCallable($handler), $routeMiddleware, $name);

        return $this;
    }

    /** @param callable(Request): Response $handler */
    public function get(string $path, callable $handler, array $middleware = [], ?string $name = null): self
    {
        return $this->add('GET', $path, $handler, $middleware, $name);
    }

    /** @param callable(Request): Response $handler */
    public function post(string $path, callable $handler, array $middleware = [], ?string $name = null): self
    {
        return $this->add('POST', $path, $handler, $middleware, $name);
    }

    /** @param callable(Request): Response $handler */
    public function put(string $path, callable $handler, array $middleware = [], ?string $name = null): self
    {
        return $this->add('PUT', $path, $handler, $middleware, $name);
    }

    /** @param callable(Request): Response $handler */
    public function patch(string $path, callable $handler, array $middleware = [], ?string $name = null): self
    {
        return $this->add('PATCH', $path, $handler, $middleware, $name);
    }

    /** @param callable(Request): Response $handler */
    public function delete(string $path, callable $handler, array $middleware = [], ?string $name = null): self
    {
        return $this->add('DELETE', $path, $handler, $middleware, $name);
    }

    /** @param list<MiddlewareInterface> $middleware */
    public function group(string $prefix, array $middleware, callable $routes): void
    {
        $previousPrefix = $this->groupPrefix;
        $previousMiddleware = $this->groupMiddleware;
        $this->groupPrefix .= '/' . trim($prefix, '/');
        $this->groupMiddleware = [...$this->groupMiddleware, ...$middleware];

        try {
            $routes($this);
        } finally {
            $this->groupPrefix = $previousPrefix;
            $this->groupMiddleware = $previousMiddleware;
        }
    }

    public function dispatch(Request $request): Response
    {
        foreach ($this->routes as $route) {
            $parameters = $route->match($request->method(), $request->path());

            if ($parameters === null) {
                continue;
            }

            $request = $request->withRouteParameters($parameters);
            $core = $route->handler;
            $middleware = [...$this->globalMiddleware, ...$route->middleware];

            $pipeline = array_reduce(
                array_reverse($middleware),
                static fn (Closure $next, MiddlewareInterface $item): Closure =>
                    static fn (Request $pipelineRequest): Response => $item->process($pipelineRequest, $next),
                $core,
            );

            return $pipeline($request);
        }

        throw new HttpException(404, 'The requested resource was not found.', 'not_found');
    }
}
