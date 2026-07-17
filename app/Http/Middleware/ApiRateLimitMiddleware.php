<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\ApiKeys\ApiKey;
use App\Http\Request;
use App\Http\Response;
use App\Services\Api\ApiRateLimiter;
use Closure;

final class ApiRateLimitMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly ApiRateLimiter $limiter)
    {
    }

    public function process(Request $request, Closure $next): Response
    {
        $apiKey = $request->attribute('api_key');

        if (!$apiKey instanceof ApiKey) {
            throw new \LogicException('Authenticated API key is missing from the request.');
        }

        $decision = $this->limiter->consume($apiKey, $request->clientIp());

        if (!$decision->allowed) {
            $requestId = $request->attribute('request_id');

            return Response::json([
                'error' => [
                    'code' => 'rate_limit_exceeded',
                    'message' => 'Too many API requests. Please try again later.',
                    'request_id' => is_string($requestId) ? $requestId : null,
                ],
            ], 429)
                ->withHeader('Retry-After', (string) $decision->retryAfterSeconds)
                ->withHeader('X-RateLimit-Limit', (string) $decision->limit)
                ->withHeader('X-RateLimit-Remaining', '0')
                ->withHeader('Cache-Control', 'no-store');
        }

        return $next($request)
            ->withHeader('X-RateLimit-Limit', (string) $decision->limit)
            ->withHeader('X-RateLimit-Remaining', (string) $decision->remaining);
    }
}
