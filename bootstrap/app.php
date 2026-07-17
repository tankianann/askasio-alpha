<?php

declare(strict_types=1);

use App\Controllers\Api\HealthController;
use App\Auth\AuthenticationService;
use App\Auth\LoginRateLimiter;
use App\Auth\NativeSessionStore;
use App\Auth\PasswordHasher;
use App\Controllers\Admin\AuthController;
use App\Controllers\Admin\DashboardController;
use App\Controllers\Admin\SourceController;
use App\Controllers\Admin\JobController;
use App\Database\Connection;
use App\Http\ErrorHandler;
use App\Http\Middleware\AdminAuthenticationMiddleware;
use App\Http\Middleware\CsrfMiddleware;
use App\Http\Middleware\RequestIdMiddleware;
use App\Http\Middleware\SecurityHeadersMiddleware;
use App\Http\Middleware\SessionStartMiddleware;
use App\Http\Router;
use App\Logging\LoggerFactory;
use App\Repositories\PdoAdminRepository;
use App\Repositories\PdoLoginAttemptRepository;
use App\Repositories\PdoSourceRepository;
use App\Repositories\PdoIngestionJobRepository;
use App\Security\CsrfTokenManager;
use App\Security\SourceUploadValidator;
use App\Security\UrlSourceValidator;
use App\Services\Sources\SourceCreationService;
use App\Services\Sources\SourceFileStorage;
use App\Services\Ingestion\IngestionQueue;
use App\Support\Config;
use App\Support\ViewRenderer;
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
$appSecret = $config->requireString('app.secret');

if (strlen($appSecret) < 32) {
    throw new RuntimeException('APP_SECRET must contain at least 32 characters.');
}

$logger = (new LoggerFactory())->create($config);
$connection = new Connection($config);
$admins = new PdoAdminRepository($connection);
$loginAttempts = new PdoLoginAttemptRepository($connection);
$sources = new PdoSourceRepository($connection);
$jobRepository = new PdoIngestionJobRepository($connection);
$queue = new IngestionQueue(
    $jobRepository,
    $config->requireInt('queue.max_attempts'),
    $config->requireInt('queue.retry_base_seconds'),
    $config->requireInt('queue.retry_maximum_seconds'),
    $config->requireInt('queue.abandoned_timeout_minutes') * 60,
);
$session = new NativeSessionStore(
    $config->requireString('auth.session_name'),
    $config->requireInt('auth.session_idle_minutes') * 60,
    $config->requireInt('auth.session_regenerate_minutes') * 60,
    $config->requireString('auth.session_secure_cookie'),
);
$csrf = new CsrfTokenManager($session);
$authentication = new AuthenticationService($admins, new PasswordHasher());
$rateLimiter = new LoginRateLimiter(
    $loginAttempts,
    $appSecret,
    $config->requireInt('auth.login_max_attempts'),
    $config->requireInt('auth.login_window_minutes') * 60,
);
$views = new ViewRenderer($root . '/resources/views');
$configuredStoragePath = $config->requireString('app.filesystem_path');
$storagePath = str_starts_with($configuredStoragePath, DIRECTORY_SEPARATOR)
    ? $configuredStoragePath
    : $root . DIRECTORY_SEPARATOR . $configuredStoragePath;
$resolvedStoragePath = realpath($storagePath);
$resolvedPublicPath = realpath($root . '/public');

if ($resolvedStoragePath === false || $resolvedPublicPath === false) {
    throw new RuntimeException('The configured source storage and public directories must exist.');
}

if ($resolvedStoragePath === $resolvedPublicPath
    || str_starts_with($resolvedStoragePath, $resolvedPublicPath . DIRECTORY_SEPARATOR)) {
    throw new RuntimeException('FILESYSTEM_PATH must be outside the public directory.');
}

$sourceCreation = new SourceCreationService(
    $sources,
    new UrlSourceValidator(),
    new SourceUploadValidator($config->requireInt('app.max_upload_size_mb') * 1024 * 1024),
    new SourceFileStorage($resolvedStoragePath),
    $queue,
);
$router = new Router();
$router->middleware(new RequestIdMiddleware());
$router->middleware(new SecurityHeadersMiddleware());
$healthController = new HealthController($connection->ping(...));
$authController = new AuthController(
    $authentication,
    $rateLimiter,
    $session,
    $csrf,
    $admins,
    $views,
    $logger,
);
$dashboardController = new DashboardController(
    $views,
    $csrf,
    $config->requireString('app.env'),
    $sources,
    $queue,
);
$sourceController = new SourceController(
    $sources,
    $sourceCreation,
    $views,
    $csrf,
    $session,
    $config->requireString('app.env'),
    $config->requireInt('app.max_upload_size_mb'),
    $queue,
);
$jobController = new JobController(
    $queue,
    $views,
    $csrf,
    $config->requireString('app.env'),
    true,
);
$sessionMiddleware = new SessionStartMiddleware($session);
$adminAuthenticationMiddleware = new AdminAuthenticationMiddleware($session, $admins);
$csrfMiddleware = new CsrfMiddleware($csrf);
$registerRoutes = $config->get('routes.register');

if (!$registerRoutes instanceof Closure) {
    throw new RuntimeException('Route configuration is invalid.');
}

$registerRoutes(
    $router,
    $healthController,
    $authController,
    $dashboardController,
    $sessionMiddleware,
    $adminAuthenticationMiddleware,
    $csrfMiddleware,
    $sourceController,
    $jobController,
);

return [
    'router' => $router,
    'error_handler' => new ErrorHandler(
        $logger,
        $root . '/resources/views/errors',
    ),
];
