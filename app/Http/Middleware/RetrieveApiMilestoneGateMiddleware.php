<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Request;
use App\Http\Response;
use Closure;

final class RetrieveApiMilestoneGateMiddleware implements MiddlewareInterface
{
    public function process(Request $request, Closure $next): Response
    {
        $requestId = $request->attribute('request_id');

        return Response::json([
            'error' => [
                'code' => 'api_authentication_pending',
                'message' => 'This endpoint will be enabled after API key authentication is installed in Milestone 7.',
                'request_id' => is_string($requestId) ? $requestId : null,
            ],
        ], 503);
    }
}
