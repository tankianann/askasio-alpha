<?php

declare(strict_types=1);

use App\Controllers\Api\HealthController;
use App\Database\Connection;
use App\Http\ErrorHandler;
use App\Http\Middleware\RequestIdMiddleware;
use App\Http\Middleware\SecurityHeadersMiddleware;
use App\Http\Router;
use App\Logging\LoggerFactory;
use App\Support\Config;
use Dotenv\Dotenv;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

Dotenv::createImmutable($root)->safeLoad();
$config = Config::load($root . '/config');
$timezone = $config->requireString('app.timezone');

if (!in_array($timezone, timezone_identifiers_list(), true)) {
    throw new RuntimeException('APP_TIMEZONE is not a valid timezone identifier.');
}

date_default_timezone_set($timezone);
$logger = (new LoggerFactory())->create($config);
$connection = new Connection($config);
$router = new Router();
$router->middleware(new RequestIdMiddleware());
$router->middleware(new SecurityHeadersMiddleware());
$healthController = new HealthController($connection->ping(...));
$registerRoutes = $config->get('routes.register');

if (!$registerRoutes instanceof Closure) {
    throw new RuntimeException('Route configuration is invalid.');
}

$registerRoutes($router, $healthController);

return [
    'router' => $router,
    'error_handler' => new ErrorHandler(
        $logger,
        $root . '/resources/views/errors',
    ),
];
