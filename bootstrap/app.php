<?php

declare(strict_types=1);

use App\Controllers\Api\HealthController;
use App\Controllers\Api\ChatController;
use App\Controllers\Api\RetrieveController;
use App\Auth\AuthenticationService;
use App\Auth\LoginRateLimiter;
use App\Auth\NativeSessionStore;
use App\Auth\PasswordHasher;
use App\Controllers\Admin\AuthController;
use App\Controllers\Admin\ApiKeyController;
use App\Controllers\Admin\ApiRequestLogController;
use App\Controllers\Admin\DashboardController;
use App\Controllers\Admin\SourceController;
use App\Controllers\Admin\JobController;
use App\Database\Connection;
use App\Http\ErrorHandler;
use App\Http\JsonRequestParser;
use App\Http\Middleware\AdminAuthenticationMiddleware;
use App\Http\Middleware\ApiKeyAuthenticationMiddleware;
use App\Http\Middleware\ApiRateLimitMiddleware;
use App\Http\Middleware\ApiRequestLoggingMiddleware;
use App\Http\Middleware\CsrfMiddleware;
use App\Http\Middleware\RequestIdMiddleware;
use App\Http\Middleware\SecurityHeadersMiddleware;
use App\Http\Middleware\SessionStartMiddleware;
use App\Http\Router;
use App\Logging\LoggerFactory;
use App\Maintenance\ApiRequestLogMaintenanceLock;
use App\Maintenance\PdoAdvisoryLock;
use App\Repositories\PdoAdminRepository;
use App\Repositories\PdoApiKeyRepository;
use App\Repositories\PdoApiRateLimitRepository;
use App\Repositories\PdoApiRequestLogRepository;
use App\Repositories\PdoLoginAttemptRepository;
use App\Repositories\PdoSourceRepository;
use App\Repositories\PdoIngestionJobRepository;
use App\Providers\Embeddings\EmbeddingProviderFactory;
use App\Providers\Embeddings\EmbeddingConfigurationException;
use App\Providers\Chat\ChatConfigurationException;
use App\Providers\Chat\ChatProviderFactory;
use App\Exceptions\ConfigurationException;
use App\Exceptions\HttpException;
use App\Ingestion\Chunking\HeuristicTokenEstimator;
use App\RAG\AnswerGenerator;
use App\RAG\ContextSelector;
use App\RAG\CosineSimilarity;
use App\RAG\PdoVectorStore;
use App\RAG\PromptBuilder;
use App\RAG\Retriever;
use App\Security\CsrfTokenManager;
use App\Security\SourceUploadValidator;
use App\Security\UrlSourceValidator;
use App\Services\Sources\SourceCreationService;
use App\Services\Sources\SourceFileStorage;
use App\Services\Sources\SourcePermanentDeletionService;
use App\Services\Sources\SourceUpdateService;
use App\Services\Ingestion\IngestionQueue;
use App\Services\Api\ApiRateLimiter;
use App\Services\Api\ApiRequestContext;
use App\Services\Api\ApiRequestLogQueryParser;
use App\Services\Api\ApiRequestLogPurgeIntentStore;
use App\Services\Api\ApiRequestLogPurgeRequestParser;
use App\Services\Api\ApiRequestLogPurgeService;
use App\Services\ApiKeys\ApiKeyService;
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
$apiKeys = new PdoApiKeyRepository($connection);
$apiRequestLogs = new PdoApiRequestLogRepository($connection);
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

$sourceFileStorage = new SourceFileStorage($resolvedStoragePath);
$sourceUploadValidator = new SourceUploadValidator($config->requireInt('app.max_upload_size_mb') * 1024 * 1024);
$urlSourceValidator = new UrlSourceValidator();
$sourceCreation = new SourceCreationService(
    $sources,
    $urlSourceValidator,
    $sourceUploadValidator,
    $sourceFileStorage,
    $queue,
);
$sourceUpdates = new SourceUpdateService(
    $sources,
    $urlSourceValidator,
    $sourceUploadValidator,
    $sourceFileStorage,
    $queue,
);
$sourceDeletion = new SourcePermanentDeletionService($sources, $sourceFileStorage, $logger);
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
    $sourceUpdates,
    $sourceDeletion,
);
$jobController = new JobController(
    $queue,
    $views,
    $csrf,
    $config->requireString('app.env'),
    true,
);
$apiKeyService = new ApiKeyService($apiKeys);
$apiKeyController = new ApiKeyController(
    $apiKeys,
    $apiKeyService,
    $views,
    $csrf,
    $session,
    $config->requireString('app.env'),
    $timezone,
);
$apiRequestLogController = new ApiRequestLogController(
    $apiRequestLogs,
    $views,
    $csrf,
    $session,
    new ApiRequestLogQueryParser($timezone),
    new ApiRequestLogPurgeRequestParser($timezone),
    new ApiRequestLogPurgeService(
        $apiRequestLogs,
        new PdoAdvisoryLock($connection),
        $logger,
        $config->requireInt('api.request_log_purge_batch_size'),
        ApiRequestLogMaintenanceLock::name($config->requireString('database.database')),
    ),
    new ApiRequestLogPurgeIntentStore($session),
    $config->requireString('app.env'),
);
$apiRequestContext = new ApiRequestContext();
$apiRequestLoggingMiddleware = new ApiRequestLoggingMiddleware(
    $apiRequestLogs,
    $apiRequestContext,
    $logger,
    $appSecret,
);
$apiKeyAuthenticationMiddleware = new ApiKeyAuthenticationMiddleware($apiKeyService, $apiRequestContext);
$apiRateLimitMiddleware = new ApiRateLimitMiddleware(new ApiRateLimiter(
    new PdoApiRateLimitRepository($connection),
    $appSecret,
    $config->requireInt('api.rate_limit_window_seconds'),
    $config->requireInt('api.rate_limit_per_key'),
    $config->requireInt('api.rate_limit_per_ip'),
));
$chatRateLimitMiddleware = new ApiRateLimitMiddleware(new ApiRateLimiter(
    new PdoApiRateLimitRepository($connection),
    $appSecret,
    $config->requireInt('api.rate_limit_window_seconds'),
    $config->requireInt('api.chat_rate_limit_per_key'),
    $config->requireInt('api.chat_rate_limit_per_ip'),
    'chat',
));
$sessionMiddleware = new SessionStartMiddleware($session);
$adminAuthenticationMiddleware = new AdminAuthenticationMiddleware($session, $admins);
$csrfMiddleware = new CsrfMiddleware($csrf);
$registerRoutes = $config->get('routes.register');

if (!$registerRoutes instanceof Closure) {
    throw new RuntimeException('Route configuration is invalid.');
}

$retrieveHandler = static function (\App\Http\Request $request) use ($config, $connection): \App\Http\Response {
    $provider = (new EmbeddingProviderFactory($config))->create();
    $retriever = new Retriever(
        $provider,
        new PdoVectorStore($connection, new CosineSimilarity()),
        $config->requireInt('rag.retrieval_default_top_k'),
        $config->requireInt('rag.retrieval_maximum_top_k'),
        (float) $config->get('rag.retrieval_minimum_similarity'),
    );
    $controller = new RetrieveController(
        $retriever,
        $config->requireInt('rag.retrieval_maximum_top_k'),
        $config->requireInt('rag.retrieval_maximum_query_characters'),
        new JsonRequestParser($config->requireInt('rag.api_maximum_body_bytes')),
    );

    return $controller($request);
};

$chatHandler = static function (\App\Http\Request $request) use ($config, $connection, $appSecret): \App\Http\Response {
    try {
        $embeddings = (new EmbeddingProviderFactory($config))->create();
        $retriever = new Retriever(
            $embeddings,
            new PdoVectorStore($connection, new CosineSimilarity()),
            $config->requireInt('rag.chat_default_top_k'),
            $config->requireInt('rag.chat_maximum_top_k'),
            (float) $config->get('rag.retrieval_minimum_similarity'),
        );
        $answers = new AnswerGenerator(
            $retriever,
            new ContextSelector(
                new HeuristicTokenEstimator(),
                $config->requireInt('rag.chat_context_maximum_tokens'),
            ),
            new PromptBuilder(),
            (new ChatProviderFactory($config))->create(),
        );
        $controller = new ChatController(
            $answers,
            new JsonRequestParser($config->requireInt('rag.api_maximum_body_bytes')),
            $config->requireInt('rag.chat_maximum_top_k'),
            $config->requireInt('rag.chat_maximum_question_characters'),
            $appSecret,
        );
    } catch (ConfigurationException|EmbeddingConfigurationException|ChatConfigurationException) {
        throw new HttpException(503, 'The AI provider is not configured correctly.', 'provider_configuration_error');
    }

    return $controller($request);
};

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
    $apiKeyController,
    $apiRequestLogController,
    $retrieveHandler,
    $chatHandler,
    $apiRequestLoggingMiddleware,
    $apiKeyAuthenticationMiddleware,
    $apiRateLimitMiddleware,
    $chatRateLimitMiddleware,
);

return [
    'router' => $router,
    'error_handler' => new ErrorHandler(
        $logger,
        $root . '/resources/views/errors',
    ),
];
