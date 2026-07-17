<?php

declare(strict_types=1);

namespace App\Http;

final class Request
{
    /**
     * @param array<string, string> $headers
     * @param array<string, mixed> $query
     * @param array<string, mixed> $parsedBody
     * @param array<string, mixed> $attributes
     */
    public function __construct(
        private readonly string $method,
        private readonly string $uri,
        private readonly array $headers = [],
        private readonly array $query = [],
        private readonly string $rawBody = '',
        private readonly array $parsedBody = [],
        private readonly array $attributes = [],
        private readonly string $clientIp = '0.0.0.0',
        private readonly bool $secure = false,
    ) {
    }

    public static function fromGlobals(): self
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $headers = [];

        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$name] = (string) $value;
            }
        }

        foreach (['CONTENT_TYPE' => 'content-type', 'CONTENT_LENGTH' => 'content-length'] as $serverKey => $name) {
            if (isset($_SERVER[$serverKey])) {
                $headers[$name] = (string) $_SERVER[$serverKey];
            }
        }

        $rawBody = file_get_contents('php://input');

        return new self(
            $method,
            $uri,
            $headers,
            $_GET,
            $rawBody === false ? '' : $rawBody,
            $_POST,
            [],
            (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'),
            (isset($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
                || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443,
        );
    }

    public function method(): string
    {
        return $this->method;
    }

    public function uri(): string
    {
        return $this->uri;
    }

    public function path(): string
    {
        $path = parse_url($this->uri, PHP_URL_PATH);

        return is_string($path) && $path !== '' ? '/' . ltrim(rawurldecode($path), '/') : '/';
    }

    public function header(string $name, ?string $default = null): ?string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->parsedBody[$key] ?? $default;
    }

    public function rawBody(): string
    {
        return $this->rawBody;
    }

    public function clientIp(): string
    {
        return $this->clientIp;
    }

    public function isSecure(): bool
    {
        return $this->secure;
    }

    public function attribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    public function withAttribute(string $key, mixed $value): self
    {
        $attributes = $this->attributes;
        $attributes[$key] = $value;

        return new self(
            $this->method,
            $this->uri,
            $this->headers,
            $this->query,
            $this->rawBody,
            $this->parsedBody,
            $attributes,
            $this->clientIp,
            $this->secure,
        );
    }

    /** @param array<string, string> $parameters */
    public function withRouteParameters(array $parameters): self
    {
        return $this->withAttribute('route_parameters', $parameters);
    }

    public function route(string $key, ?string $default = null): ?string
    {
        $parameters = $this->attribute('route_parameters', []);

        return is_array($parameters) && isset($parameters[$key]) ? (string) $parameters[$key] : $default;
    }

    public function expectsJson(): bool
    {
        return str_starts_with($this->path(), '/api/')
            || str_contains(strtolower((string) $this->header('accept', '')), 'application/json');
    }
}
