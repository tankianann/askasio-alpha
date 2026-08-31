<?php

declare(strict_types=1);

use App\Controllers\Api\HealthController;
use App\Controllers\Api\ChatController;
use App\Controllers\Api\RetrieveController;
use App\Controllers\Api\PublicChatbotController;
use App\Controllers\Api\ChatbotIntegrationController;
use App\Auth\AuthenticationService;
use App\Auth\LoginRateLimiter;
use App\Auth\NativeSessionStore;
use App\Auth\PasswordHasher;
use App\Controllers\Admin\AuthController;
use App\Controllers\Admin\ApiKeyController;
use App\Controllers\Admin\ApiRequestLogController;
use App\Controllers\Admin\AiUsageController;
use App\Controllers\Admin\DashboardController;
use App\Controllers\Admin\ChatbotController;
use App\Controllers\Admin\ChatbotConversationController;
use App\Controllers\Admin\ChatbotIntegrationCredentialController;
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
use App\Http\Middleware\PublicChatbotCorsMiddleware;
use App\Http\Middleware\PublicChatbotRateLimitMiddleware;
use App\Http\Middleware\PublicChatbotSessionAuthenticationMiddleware;
use App\Http\Middleware\ChatbotIntegrationAuthenticationMiddleware;
use App\Http\Middleware\ChatbotIntegrationRateLimitMiddleware;
use App\Http\Router;
use App\Logging\LoggerFactory;
use App\Maintenance\ApiRequestLogMaintenanceLock;
use App\Maintenance\PdoAdvisoryLock;
use App\Maintenance\ChatbotConversationMaintenanceLock;
use App\Repositories\PdoAdminRepository;
use App\Repositories\PdoApiKeyRepository;
use App\Repositories\PdoApiRateLimitRepository;
use App\Repositories\PdoApiRequestLogRepository;
use App\Repositories\PdoAiUsageRepository;
use App\Repositories\PdoLoginAttemptRepository;
use App\Repositories\PdoProviderQuotaRepository;
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
use App\Services\Sources\MarkdownFrontMatterTitleParser;
use App\Services\Sources\SourcePermanentDeletionService;
use App\Repositories\PdoChatbotRepository;
use App\Repositories\PdoChatbotConversationRepository;
use App\Repositories\PdoChatbotIntegrationCredentialRepository;
use App\Services\Sources\SourceUpdateService;
use App\Services\Sources\SourceListQueryParser;
use App\Services\Sources\SourceHistoryQueryParser;
use App\Services\Ingestion\IngestionQueue;
use App\Services\Ingestion\IngestionJobListQueryParser;
use App\Services\ProviderQuota\ProviderQuotaService;
use App\Services\ProviderQuota\AiUsageQueryParser;
use App\Services\ProviderQuota\ProviderUsageAccumulator;
use App\Services\Api\ApiRateLimiter;
use App\Services\Api\ApiRequestContext;
use App\Services\Api\ApiRequestLogQueryParser;
use App\Services\Api\ApiRequestLogPurgeIntentStore;
use App\Services\Api\ApiRequestLogPurgeRequestParser;
use App\Services\Api\ApiRequestLogPurgeService;
use App\Services\ApiKeys\ApiKeyService;
use App\Services\ApiKeys\ApiKeyListQueryParser;
use App\Services\Chatbots\ChatbotAdminFormParser;
use App\Services\Chatbots\ChatbotDraftDefaults;
use App\Services\Chatbots\ChatbotDraftValidator;
use App\Services\Chatbots\ChatbotListQueryParser;
use App\Services\Chatbots\ChatbotOriginNormalizer;
use App\Services\Chatbots\ChatbotService;
use App\Services\Chatbots\ChatbotConversationService;
use App\Services\Chatbots\ChatbotConversationListQueryParser;
use App\Services\Chatbots\ChatbotConversationRetentionService;
use App\Services\Chatbots\ChatbotAnalyticsQueryParser;
use App\Services\Chatbots\ChatbotIntegrationCredentialService;
use App\Services\Chatbots\ChatbotHistorySelector;
use App\Services\Chatbots\ChatbotPreviewService;
use App\Services\Chatbots\PublicChatbotAccessService;
use App\Services\Chatbots\PublicChatbotRateLimiter;
use App\Services\Chatbots\RandomChatbotSessionCredentialGenerator;
use App\Services\Chatbots\SharedChatExecutionService;
use App\Services\Chatbots\InstallationChatbotProviderConfigurationFactory;
use App\Services\Chatbots\RandomChatbotPublicIdGenerator;
use App\Domain\Chatbots\ChatbotProviderConfiguration;
use App\RAG\ChatExecutionErrorMapper;
use App\RAG\CitationProjector;
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
$errorHandler = new ErrorHandler($logger, $root . '/resources/views/errors');
$connection = new Connection($config);
$admins = new PdoAdminRepository($connection);
$loginAttempts = new PdoLoginAttemptRepository($connection);
$sources = new PdoSourceRepository($connection);
$jobRepository = new PdoIngestionJobRepository($connection);
$apiKeys = new PdoApiKeyRepository($connection);
$apiRequestLogs = new PdoApiRequestLogRepository($connection);
$aiUsage = new PdoAiUsageRepository($connection);
$providerQuotas = new ProviderQuotaService(
    new PdoProviderQuotaRepository($connection),
    $config->requireInt('provider_quotas.global_daily_tokens'),
    $config->requireInt('provider_quotas.global_monthly_tokens'),
    $config->requireInt('provider_quotas.api_key_daily_tokens'),
    $config->requireInt('provider_quotas.api_key_monthly_tokens'),
    $config->requireInt('provider_quotas.reservation_ttl_seconds'),
);
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
    new MarkdownFrontMatterTitleParser(),
);
$sourceUpdates = new SourceUpdateService(
    $sources,
    $urlSourceValidator,
    $sourceUploadValidator,
    $sourceFileStorage,
    $queue,
);
$chatbots = new PdoChatbotRepository($connection);
$chatbotProviderConfigured = true;

try {
    $chatbotProvider = (new InstallationChatbotProviderConfigurationFactory($config))->create();
} catch (ConfigurationException) {
    $chatbotProviderConfigured = false;
    $chatbotProvider = new ChatbotProviderConfiguration(
        'unconfigured',
        'unconfigured',
        'unconfigured',
        'unconfigured',
        null,
    );
}

$chatbotService = new ChatbotService(
    $chatbots,
    new ChatbotDraftValidator(
        $config->requireInt('rag.chat_maximum_top_k'),
        $config->requireInt('rag.chat_maximum_question_characters'),
    ),
    new RandomChatbotPublicIdGenerator(),
    $chatbotProvider,
    new ChatbotOriginNormalizer(),
    $chatbotProviderConfigured,
);
$conversationRepository = new PdoChatbotConversationRepository($connection);
$integrationCredentials = new PdoChatbotIntegrationCredentialRepository($connection);
$integrationCredentialService = new ChatbotIntegrationCredentialService($integrationCredentials);
$conversationService = new ChatbotConversationService(
    $conversationRepository,
    new RandomChatbotSessionCredentialGenerator(),
    pendingTimeoutSeconds: $config->requireInt('api.public_chatbot_pending_timeout_seconds'),
);
$sourceDeletion = new SourcePermanentDeletionService($sources, $sourceFileStorage, $logger, $chatbots);
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
    new SourceListQueryParser($timezone),
    new SourceHistoryQueryParser($timezone),
);
$chatbotPreviewService = null;
$sharedChatExecutionService = null;

if ($chatbotProviderConfigured) {
    try {
        $previewUsage = new ProviderUsageAccumulator();
        $previewTokens = new HeuristicTokenEstimator();
        $previewAnswers = new AnswerGenerator(
            new Retriever(
                (new EmbeddingProviderFactory($config))->create($previewUsage),
                new PdoVectorStore($connection, new CosineSimilarity()),
                $config->requireInt('rag.chat_default_top_k'),
                $config->requireInt('rag.chat_maximum_top_k'),
                (float) $config->get('rag.retrieval_minimum_similarity'),
            ),
            new ContextSelector($previewTokens, $config->requireInt('rag.chat_context_maximum_tokens')),
            new PromptBuilder(),
            (new ChatProviderFactory($config))->create(),
        );
        $sharedChatExecutionService = new SharedChatExecutionService(
            $chatbots,
            $conversationRepository,
            $previewAnswers,
            new ChatbotHistorySelector($previewTokens, $config->requireInt('rag.chat_history_maximum_tokens')),
            new CitationProjector(),
            new ChatExecutionErrorMapper(),
            $providerQuotas,
            $previewUsage,
            $chatbotProvider,
            $previewTokens,
            $config->requireInt('rag.chat_context_maximum_tokens'),
            $config->requireInt('providers.openai.chat_maximum_output_tokens'),
        );
        $chatbotPreviewService = new ChatbotPreviewService(
            $conversationService,
            $sharedChatExecutionService,
            $session,
            $chatbotProvider,
        );
    } catch (ConfigurationException|EmbeddingConfigurationException|ChatConfigurationException) {
        $chatbotProviderConfigured = false;
    }
}
$chatbotController = new ChatbotController(
    $chatbots,
    $sources,
    $chatbotService,
    new ChatbotDraftDefaults(
        $config->requireInt('rag.chat_default_top_k'),
        (float) $config->get('rag.retrieval_minimum_similarity'),
        $config->requireInt('rag.chat_maximum_question_characters'),
    ),
    new ChatbotAdminFormParser(),
    new ChatbotListQueryParser($timezone),
    new SourceListQueryParser($timezone),
    $chatbotProvider,
    $views,
    $csrf,
    $session,
    $config->requireString('app.env'),
    $chatbotProviderConfigured,
    $config->requireInt('rag.chat_maximum_top_k'),
    $config->requireInt('rag.chat_maximum_question_characters'),
    $chatbotPreviewService,
    $appSecret,
    $config->requireString('app.url'),
);
$publicChatbotAccess = new PublicChatbotAccessService(
    $chatbots,
    new ChatbotOriginNormalizer(),
    $chatbotProvider,
    $chatbotProviderConfigured,
);
$publicChatbotController = new PublicChatbotController(
    $conversationService,
    new JsonRequestParser($config->requireInt('rag.api_maximum_body_bytes')),
    executor: $sharedChatExecutionService,
    applicationSecret: $appSecret,
);
$chatbotIntegrationController = new ChatbotIntegrationController(
    $conversationService,
    new JsonRequestParser($config->requireInt('rag.api_maximum_body_bytes')),
    $sharedChatExecutionService,
    $integrationCredentials,
    $appSecret,
);
$jobController = new JobController(
    $queue,
    $views,
    $csrf,
    $config->requireString('app.env'),
    true,
    $session,
    new IngestionJobListQueryParser($timezone),
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
    new ApiKeyListQueryParser($timezone),
    $providerQuotas,
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
$aiUsageController = new AiUsageController(
    $aiUsage,
    $providerQuotas,
    new AiUsageQueryParser(),
    $views,
    $csrf,
    $session,
    $config->requireString('app.env'),
);
$conversationRetention = new ChatbotConversationRetentionService(
    $conversationRepository,
    new PdoAdvisoryLock($connection),
    $logger,
    $config->requireInt('api.chatbot_conversation_purge_batch_size'),
    ChatbotConversationMaintenanceLock::name($config->requireString('database.database')),
);
$chatbotConversationController = new ChatbotConversationController(
    $conversationRepository,
    $chatbots,
    new ChatbotConversationListQueryParser($timezone),
    $conversationRetention,
    $views,
    $csrf,
    $session,
    $config->requireString('app.env'),
    new ChatbotAnalyticsQueryParser($timezone),
);
$chatbotIntegrationCredentialController = new ChatbotIntegrationCredentialController(
    $integrationCredentials,
    $integrationCredentialService,
    $chatbots,
    $views,
    $csrf,
    $session,
    $config->requireString('app.env'),
    $timezone,
    $logger,
    $aiUsage,
);
$apiRequestContext = new ApiRequestContext();
$apiRequestLoggingMiddleware = new ApiRequestLoggingMiddleware(
    $apiRequestLogs,
    $apiRequestContext,
    $logger,
    $appSecret,
);
$publicApiRequestLoggingMiddleware = new ApiRequestLoggingMiddleware(
    $apiRequestLogs,
    new ApiRequestContext(),
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
$publicChatbotConfigCorsMiddleware = new PublicChatbotCorsMiddleware(
    $publicChatbotAccess,
    $errorHandler,
    ['GET'],
    [],
);
$publicChatbotSessionCorsMiddleware = new PublicChatbotCorsMiddleware(
    $publicChatbotAccess,
    $errorHandler,
    ['POST'],
    ['Content-Type'],
);
$publicChatbotMessageCorsMiddleware = new PublicChatbotCorsMiddleware(
    $publicChatbotAccess,
    $errorHandler,
    ['POST'],
    ['Authorization', 'Content-Type'],
);
$publicChatbotSessionAuthenticationMiddleware = new PublicChatbotSessionAuthenticationMiddleware(
    $conversationService,
);
$publicChatbotConfigRateLimitMiddleware = new PublicChatbotRateLimitMiddleware(
    new PublicChatbotRateLimiter(
        new PdoApiRateLimitRepository($connection),
        $appSecret,
        $config->requireInt('api.public_chatbot_rate_limit_window_seconds'),
        $config->requireInt('api.public_chatbot_config_rate_limit_per_ip'),
        $config->requireInt('api.public_chatbot_config_rate_limit_per_chatbot'),
        'public_config',
    ),
);
$publicChatbotSessionRateLimitMiddleware = new PublicChatbotRateLimitMiddleware(
    new PublicChatbotRateLimiter(
        new PdoApiRateLimitRepository($connection),
        $appSecret,
        $config->requireInt('api.public_chatbot_rate_limit_window_seconds'),
        $config->requireInt('api.public_chatbot_session_rate_limit_per_ip'),
        $config->requireInt('api.public_chatbot_session_rate_limit_per_chatbot'),
        'public_session',
    ),
);
$publicChatbotMessageRateLimitMiddleware = new PublicChatbotRateLimitMiddleware(
    new PublicChatbotRateLimiter(
        new PdoApiRateLimitRepository($connection),
        $appSecret,
        $config->requireInt('api.public_chatbot_rate_limit_window_seconds'),
        $config->requireInt('api.public_chatbot_message_rate_limit_per_ip'),
        $config->requireInt('api.public_chatbot_message_rate_limit_per_chatbot'),
        'public_message',
        $config->requireInt('api.public_chatbot_message_rate_limit_per_session'),
    ),
);
$chatbotIntegrationAuthenticationMiddleware = new ChatbotIntegrationAuthenticationMiddleware(
    $integrationCredentialService,
    $chatbots,
    $integrationCredentials,
);
$chatbotIntegrationRateLimitMiddleware = new ChatbotIntegrationRateLimitMiddleware(
    new PdoApiRateLimitRepository($connection),
    $appSecret,
    $config->requireInt('api.public_chatbot_rate_limit_window_seconds'),
    $config->requireInt('api.chatbot_integration_rate_limit_per_credential'),
    $config->requireInt('api.chatbot_integration_rate_limit_per_ip'),
);
$sessionMiddleware = new SessionStartMiddleware($session);
$adminAuthenticationMiddleware = new AdminAuthenticationMiddleware($session, $admins);
$csrfMiddleware = new CsrfMiddleware($csrf);
$registerRoutes = $config->get('routes.register');

if (!$registerRoutes instanceof Closure) {
    throw new RuntimeException('Route configuration is invalid.');
}

$retrieveHandler = static function (\App\Http\Request $request) use ($config, $connection, $providerQuotas): \App\Http\Response {
    $providerUsage = new ProviderUsageAccumulator();
    $provider = (new EmbeddingProviderFactory($config))->create($providerUsage);
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
        $providerQuotas,
        $providerUsage,
    );

    return $controller($request);
};

$chatHandler = static function (\App\Http\Request $request) use ($config, $connection, $appSecret, $providerQuotas): \App\Http\Response {
    try {
        $providerUsage = new ProviderUsageAccumulator();
        $embeddings = (new EmbeddingProviderFactory($config))->create($providerUsage);
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
            $providerQuotas,
            $providerUsage,
            $config->requireInt('rag.chat_context_maximum_tokens'),
            $config->requireInt('providers.openai.chat_maximum_output_tokens'),
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
    $chatbotController,
    $jobController,
    $apiKeyController,
    $apiRequestLogController,
    $aiUsageController,
    $chatbotConversationController,
    $retrieveHandler,
    $chatHandler,
    $apiRequestLoggingMiddleware,
    $apiKeyAuthenticationMiddleware,
    $apiRateLimitMiddleware,
    $chatRateLimitMiddleware,
    $publicChatbotController,
    $publicApiRequestLoggingMiddleware,
    $publicChatbotConfigCorsMiddleware,
    $publicChatbotSessionCorsMiddleware,
    $publicChatbotConfigRateLimitMiddleware,
    $publicChatbotSessionRateLimitMiddleware,
    $publicChatbotMessageCorsMiddleware,
    $publicChatbotSessionAuthenticationMiddleware,
    $publicChatbotMessageRateLimitMiddleware,
    $chatbotIntegrationController,
    $chatbotIntegrationAuthenticationMiddleware,
    $chatbotIntegrationRateLimitMiddleware,
    $chatbotIntegrationCredentialController,
);

return [
    'router' => $router,
    'error_handler' => $errorHandler,
];
