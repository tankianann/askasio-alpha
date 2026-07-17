<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Request;
use App\Http\Response;
use Closure;
use Ramsey\Uuid\Uuid;

final class RequestIdMiddleware implements MiddlewareInterface
{
    public function process(Request $request, Closure $next): Response
    {
        $requestId = $request->attribute('request_id');

        if (!is_string($requestId) || $requestId === '') {
            $requestId = Uuid::uuid7()->toString();
            $request = $request->withAttribute('request_id', $requestId);
        }

        return $next($request)->withHeader('X-Request-ID', $requestId);
    }
}
