<?php

declare(strict_types=1);

use App\Controllers\Api\HealthController;
use App\Controllers\Admin\AuthController;
use App\Controllers\Admin\DashboardController;
use App\Http\Middleware\AdminAuthenticationMiddleware;
use App\Http\Middleware\CsrfMiddleware;
use App\Http\Middleware\SessionStartMiddleware;
use App\Http\Request;
use App\Http\Response;
use App\Http\Router;

return [
    'register' => static function (
        Router $router,
        HealthController $healthController,
        AuthController $authController,
        DashboardController $dashboardController,
        SessionStartMiddleware $sessionMiddleware,
        AdminAuthenticationMiddleware $adminAuthenticationMiddleware,
        CsrfMiddleware $csrfMiddleware,
    ): void {
        $router->group('/api/v1', [], static function (Router $router) use ($healthController): void {
            $router->get('/health', $healthController, name: 'api.v1.health');
        });

        $router->get('/', static fn (Request $request): Response => Response::redirect('/admin'), name: 'home');
        $router->get('/admin/login', [$authController, 'showLogin'], [$sessionMiddleware], 'admin.login');
        $router->post('/admin/login', [$authController, 'login'], [$sessionMiddleware, $csrfMiddleware], 'admin.login.submit');

        $router->group(
            '/admin',
            [$sessionMiddleware, $adminAuthenticationMiddleware],
            static function (Router $router) use ($authController, $dashboardController, $csrfMiddleware): void {
                $router->get('/', $dashboardController, name: 'admin.dashboard');
                $router->post('/logout', [$authController, 'logout'], [$csrfMiddleware], 'admin.logout');
            },
        );
    },
];
