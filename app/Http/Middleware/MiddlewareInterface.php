<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Request;
use App\Http\Response;
use Closure;

interface MiddlewareInterface
{
    /** @param Closure(Request): Response $next */
    public function process(Request $request, Closure $next): Response;
}
