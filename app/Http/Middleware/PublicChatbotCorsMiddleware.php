<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\HttpException;
use App\Http\ErrorHandler;
use App\Http\Request;
use App\Http\Response;
use App\Services\Chatbots\PublicChatbotAccessService;
use Closure;
use Throwable;

final readonly class PublicChatbotCorsMiddleware implements MiddlewareInterface
{
    /** @param list<string> $allowedMethods @param list<string> $allowedHeaders */
    public function __construct(
        private PublicChatbotAccessService $access,
        private ErrorHandler $errors,
        private array $allowedMethods,
        private array $allowedHeaders,
    ) {
    }

    public function process(Request $request, Closure $next): Response
    {
        $originHeader = $request->header('origin');
        $origin = $originHeader;
        $corsRequest = is_string($originHeader) && $originHeader !== '';
        $sameOriginRequest = !$corsRequest
            && strtolower((string) $request->header('sec-fetch-site', '')) === 'same-origin';

        if ($sameOriginRequest) {
            $host = trim((string) $request->header('host', ''));
            $origin = ($request->isSecure() ? 'https://' : 'http://') . $host;
        }

        try {
            $context = $this->access->authorize((string) $request->route('chatbotPublicId'), $origin);
        } catch (Throwable $exception) {
            $response = $this->errors->handle($request, $exception)
                ->withHeader('Cache-Control', 'no-store');

            return ($corsRequest || !$sameOriginRequest)
                ? $response->withHeader('Vary', 'Origin')
                : $response;
        }

        $request = $request->withAttribute('public_chatbot_context', $context);

        try {
            $response = $request->method() === 'OPTIONS'
                ? $this->preflight($request)
                : $next($request);
        } catch (Throwable $exception) {
            $response = $this->errors->handle($request, $exception);
        }

        $response = $response->withHeader('Cache-Control', 'no-store');

        if (!$corsRequest) {
            return $response;
        }

        return $response
            ->withHeader('Access-Control-Allow-Origin', trim((string) $originHeader))
            ->withHeader('Vary', $request->method() === 'OPTIONS'
                ? 'Origin, Access-Control-Request-Method, Access-Control-Request-Headers'
                : 'Origin');
    }

    private function preflight(Request $request): Response
    {
        $method = strtoupper(trim((string) $request->header('access-control-request-method', '')));

        if (!in_array($method, $this->allowedMethods, true)) {
            throw new HttpException(403, 'The CORS preflight request is not allowed.', 'cors_preflight_rejected');
        }

        $requested = strtolower((string) $request->header('access-control-request-headers', ''));
        $headers = $requested === '' ? [] : array_map('trim', explode(',', $requested));
        $allowed = array_map('strtolower', $this->allowedHeaders);

        foreach ($headers as $header) {
            if ($header === '' || !in_array($header, $allowed, true)) {
                throw new HttpException(403, 'The CORS preflight request is not allowed.', 'cors_preflight_rejected');
            }
        }

        $response = (new Response('', 204))
            ->withHeader('Access-Control-Allow-Methods', implode(', ', $this->allowedMethods))
            ->withHeader('Access-Control-Max-Age', '600');

        if ($this->allowedHeaders !== []) {
            $response = $response->withHeader('Access-Control-Allow-Headers', implode(', ', $this->allowedHeaders));
        }

        return $response;
    }
}
