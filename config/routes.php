<?php

declare(strict_types=1);

use App\Controllers\Api\HealthController;
use App\Http\Router;

return [
    'register' => static function (Router $router, HealthController $healthController): void {
        $router->group('/api/v1', [], static function (Router $router) use ($healthController): void {
            $router->get('/health', $healthController, name: 'api.v1.health');
        });
    },
];
