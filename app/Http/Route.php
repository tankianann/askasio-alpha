<?php

declare(strict_types=1);

namespace App\Http;

use App\Http\Middleware\MiddlewareInterface;
use Closure;
use InvalidArgumentException;

final class Route
{
    private string $regex;

    /**
     * @param list<string> $methods
     * @param Closure(Request): Response $handler
     * @param list<MiddlewareInterface> $middleware
     */
    public function __construct(
        public readonly array $methods,
        public readonly string $path,
        public readonly Closure $handler,
        public readonly array $middleware = [],
        public readonly ?string $name = null,
    ) {
        if ($path === '' || $path[0] !== '/') {
            throw new InvalidArgumentException('Route paths must begin with /.');
        }

        $this->regex = $this->compile($path);
    }

    /** @return array<string, string>|null */
    public function match(string $method, string $path): ?array
    {
        if (!in_array(strtoupper($method), $this->methods, true)) {
            return null;
        }

        $matches = [];

        if (preg_match($this->regex, $path, $matches) !== 1) {
            return null;
        }

        return array_map(
            static fn (mixed $value): string => rawurldecode((string) $value),
            array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY),
        );
    }

    private function compile(string $path): string
    {
        $quoted = preg_quote($path, '#');
        $pattern = preg_replace('/\\\\\{([A-Za-z_][A-Za-z0-9_]*)\\\\\}/', '(?<$1>[^/]+)', $quoted);

        if ($pattern === null) {
            throw new InvalidArgumentException('Invalid route path.');
        }

        return '#^' . rtrim($pattern, '/') . '/?$#D';
    }
}
