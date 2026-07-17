<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Auth\SessionStoreInterface;
use App\Http\Request;
use App\Http\Response;
use Closure;

final class SessionStartMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly SessionStoreInterface $session)
    {
    }

    public function process(Request $request, Closure $next): Response
    {
        $this->session->start($request->isSecure());

        return $next($request);
    }
}
