<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Api\ApiRequestLog;
use App\Exceptions\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Repositories\ApiRequestLogRepositoryInterface;
use App\Services\Api\ApiRequestContext;
use Closure;
use Psr\Log\LoggerInterface;
use Throwable;

final class ApiRequestLoggingMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly ApiRequestLogRepositoryInterface $logs,
        private readonly ApiRequestContext $context,
        private readonly LoggerInterface $logger,
        private readonly string $applicationSecret,
    ) {
    }

    public function process(Request $request, Closure $next): Response
    {
        $started = hrtime(true);

        try {
            $response = $next($request);
            $this->record(
                $request,
                $response->status(),
                $this->errorCategory($response),
                $this->usage($response),
                $started,
            );

            return $response;
        } catch (Throwable $exception) {
            $this->record(
                $request,
                $exception instanceof HttpException ? $exception->statusCode : 500,
                $exception instanceof HttpException ? $exception->errorCode : 'internal_error',
                [],
                $started,
            );

            throw $exception;
        }
    }

    /** @param array<string, int|float> $usage */
    private function record(
        Request $request,
        int $status,
        ?string $errorCategory,
        array $usage,
        int $started,
    ): void {
        $requestId = $request->attribute('request_id');
        $routePattern = $request->attribute('route_pattern');
        $endpoint = is_string($routePattern) && str_starts_with($routePattern, '/')
            ? $routePattern
            : $request->path();

        try {
            $this->logs->record(new ApiRequestLog(
                is_string($requestId) && $requestId !== '' ? $requestId : bin2hex(random_bytes(16)),
                $this->context->apiKeyId,
                hash_hmac('sha256', $request->clientIp(), $this->applicationSecret),
                $request->method(),
                $endpoint,
                $status,
                max(0, (int) ((hrtime(true) - $started) / 1_000_000)),
                $errorCategory,
                $usage,
            ));

        } catch (Throwable $exception) {
            $this->logger->warning('API request audit record could not be persisted.', [
                'request_id' => $requestId,
                'exception' => $exception,
            ]);
        }
    }

    private function errorCategory(Response $response): ?string
    {
        if ($response->status() < 400) {
            return null;
        }

        $payload = json_decode($response->body(), true);
        $code = is_array($payload) ? ($payload['error']['code'] ?? null) : null;

        return is_string($code) && $code !== '' ? $code : 'http_error';
    }

    /** @return array<string, int|float> */
    private function usage(Response $response): array
    {
        $payload = json_decode($response->body(), true);
        $usage = is_array($payload) ? ($payload['usage'] ?? null) : null;

        if (!is_array($usage)) {
            return [];
        }

        $safe = [];

        foreach ($usage as $key => $value) {
            if (is_string($key) && (is_int($value) || is_float($value)) && is_finite((float) $value)) {
                $safe[$key] = $value;
            }
        }

        return $safe;
    }
}
