<?php

declare(strict_types=1);

use App\Controllers\Api\HealthController;
use App\Controllers\Admin\AuthController;
use App\Controllers\Admin\DashboardController;
use App\Controllers\Admin\SourceController;
use App\Controllers\Admin\JobController;
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
        SourceController $sourceController,
        JobController $jobController,
    ): void {
        $router->group('/api/v1', [], static function (Router $router) use ($healthController): void {
            $router->get('/health', $healthController, name: 'api.v1.health');
        });

        $router->get('/', static fn (Request $request): Response => Response::redirect('/admin'), name: 'home');
        $router->get('/admin/login', [$authController, 'showLogin'], [$sessionMiddleware], 'admin.login');
        $router->post('/admin/login', [$authController, 'login'], [$sessionMiddleware, $csrfMiddleware], 'admin.login.submit');

        $router->group(
            '/admin',
            [$sessionMiddleware, $adminAuthenticationMiddleware, $csrfMiddleware],
            static function (Router $router) use ($authController, $dashboardController, $sourceController, $jobController): void {
                $router->get('/', $dashboardController, name: 'admin.dashboard');
                $router->post('/logout', [$authController, 'logout'], name: 'admin.logout');
                $router->get('/sources', [$sourceController, 'index'], name: 'admin.sources.index');
                $router->get('/sources/create', [$sourceController, 'create'], name: 'admin.sources.create');
                $router->post('/sources', [$sourceController, 'store'], name: 'admin.sources.store');
                $router->get('/sources/{sourceId}', [$sourceController, 'show'], name: 'admin.sources.show');
                $router->post('/sources/{sourceId}/disable', [$sourceController, 'disable'], name: 'admin.sources.disable');
                $router->post('/sources/{sourceId}/enable', [$sourceController, 'enable'], name: 'admin.sources.enable');
                $router->post('/sources/{sourceId}/delete', [$sourceController, 'delete'], name: 'admin.sources.delete');
                $router->get('/jobs', $jobController, name: 'admin.jobs.index');
            },
        );
    },
];
