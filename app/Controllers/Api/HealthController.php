<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Http\Request;
use App\Http\Response;
use Closure;
use Throwable;

final class HealthController
{
    private readonly Closure $databaseHealthCheck;

    /** @param callable(): bool $databaseHealthCheck */
    public function __construct(callable $databaseHealthCheck)
    {
        $this->databaseHealthCheck = Closure::fromCallable($databaseHealthCheck);
    }

    public function __invoke(Request $request): Response
    {
        try {
            $databaseHealthy = ($this->databaseHealthCheck)();
        } catch (Throwable) {
            $databaseHealthy = false;
        }

        $status = $databaseHealthy ? 'ok' : 'degraded';

        return Response::json([
            'status' => $status,
            'services' => [
                'application' => 'ok',
                'database' => $databaseHealthy ? 'ok' : 'unavailable',
            ],
            'timestamp' => gmdate(DATE_ATOM),
            'request_id' => $request->attribute('request_id'),
        ], $databaseHealthy ? 200 : 503);
    }
}
