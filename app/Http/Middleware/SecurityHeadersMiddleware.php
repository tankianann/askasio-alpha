<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Request;
use App\Http\Response;
use Closure;

final class SecurityHeadersMiddleware implements MiddlewareInterface
{
    public function process(Request $request, Closure $next): Response
    {
        $response = $next($request)
            ->withHeader('Content-Security-Policy', "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'")
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->withHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()')
            ->withHeader(
                'Cross-Origin-Resource-Policy',
                str_starts_with($request->path(), '/api/public/') ? 'cross-origin' : 'same-origin',
            );

        if (str_starts_with($request->path(), '/admin')) {
            $response = $response->withHeader('Cache-Control', 'no-store');
        }

        if ($request->isSecure()) {
            $response = $response->withHeader('Strict-Transport-Security', 'max-age=31536000');
        }

        return $response;
    }
}
