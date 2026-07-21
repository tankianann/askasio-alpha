<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Chatbots\PublicChatbotContext;
use App\Http\Request;
use App\Http\Response;
use App\Services\Chatbots\PublicChatbotRateLimiter;
use Closure;

final readonly class PublicChatbotRateLimitMiddleware implements MiddlewareInterface
{
    public function __construct(private PublicChatbotRateLimiter $limiter)
    {
    }

    public function process(Request $request, Closure $next): Response
    {
        $context = $request->attribute('public_chatbot_context');

        if (!$context instanceof PublicChatbotContext) {
            throw new \LogicException('Authorized public chatbot context is missing.');
        }

        $decision = $this->limiter->consume($context->chatbot->publicId, $request->clientIp());

        if (!$decision->allowed) {
            $requestId = $request->attribute('request_id');

            return Response::json([
                'error' => [
                    'code' => 'rate_limit_exceeded',
                    'message' => 'Too many chatbot requests. Please try again later.',
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
