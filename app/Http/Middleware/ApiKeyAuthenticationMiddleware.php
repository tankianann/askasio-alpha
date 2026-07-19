<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Request;
use App\Http\Response;
use App\Services\Api\ApiRequestContext;
use App\Services\ApiKeys\ApiKeyService;
use Closure;

final class ApiKeyAuthenticationMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly ApiKeyService $keys,
        private readonly ApiRequestContext $context,
    ) {
    }

    public function process(Request $request, Closure $next): Response
    {
        $this->context->apiKeyId = null;
        $authorization = trim((string) $request->header('authorization', ''));

        if (preg_match('/^Bearer\s+(\S+)$/i', $authorization, $matches) !== 1) {
            return $this->unauthorized($request);
        }

        $apiKey = $this->keys->authenticate($matches[1]);

        if ($apiKey === null) {
            return $this->unauthorized($request);
        }

        $this->context->apiKeyId = $apiKey->id;

        return $next($request->withAttribute('api_key', $apiKey));
    }

    private function unauthorized(Request $request): Response
    {
        $requestId = $request->attribute('request_id');

        return Response::json([
            'error' => [
                'code' => 'unauthorized',
                'message' => 'A valid bearer API key is required.',
                'request_id' => is_string($requestId) ? $requestId : null,
            ],
        ], 401)
            ->withHeader('WWW-Authenticate', 'Bearer realm="Ask Asio"')
            ->withHeader('Cache-Control', 'no-store');
    }
}
