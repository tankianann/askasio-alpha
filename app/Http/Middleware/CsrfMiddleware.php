<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Security\CsrfTokenManager;
use Closure;

final class CsrfMiddleware implements MiddlewareInterface
{
    private const STATE_CHANGING_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public function __construct(private readonly CsrfTokenManager $tokens)
    {
    }

    public function process(Request $request, Closure $next): Response
    {
        if (in_array($request->method(), self::STATE_CHANGING_METHODS, true)
            && !$this->tokens->validate($request->input('_csrf'))) {
            throw new HttpException(419, 'Your form session expired. Please try again.', 'csrf_token_invalid');
        }

        return $next($request);
    }
}
