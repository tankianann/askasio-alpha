<?php

declare(strict_types=1);

use App\Controllers\Api\HealthController;
use App\Controllers\Api\PublicChatbotController;
use App\Controllers\Api\ChatbotIntegrationController;
use App\Controllers\Admin\AuthController;
use App\Controllers\Admin\ApiKeyController;
use App\Controllers\Admin\ApiRequestLogController;
use App\Controllers\Admin\DashboardController;
use App\Controllers\Admin\ChatbotController;
use App\Controllers\Admin\ChatbotConversationController;
use App\Controllers\Admin\ChatbotIntegrationCredentialController;
use App\Controllers\Admin\SourceController;
use App\Controllers\Admin\JobController;
use App\Http\Middleware\AdminAuthenticationMiddleware;
use App\Http\Middleware\ApiKeyAuthenticationMiddleware;
use App\Http\Middleware\ApiRateLimitMiddleware;
use App\Http\Middleware\ApiRequestLoggingMiddleware;
use App\Http\Middleware\CsrfMiddleware;
use App\Http\Middleware\SessionStartMiddleware;
use App\Http\Middleware\PublicChatbotCorsMiddleware;
use App\Http\Middleware\PublicChatbotRateLimitMiddleware;
use App\Http\Middleware\PublicChatbotSessionAuthenticationMiddleware;
use App\Http\Middleware\ChatbotIntegrationAuthenticationMiddleware;
use App\Http\Middleware\ChatbotIntegrationRateLimitMiddleware;
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
        ChatbotController $chatbotController,
        JobController $jobController,
        ApiKeyController $apiKeyController,
        ApiRequestLogController $apiRequestLogController,
        ChatbotConversationController $chatbotConversationController,
        Closure $retrieveHandler,
        Closure $chatHandler,
        ApiRequestLoggingMiddleware $apiRequestLoggingMiddleware,
        ApiKeyAuthenticationMiddleware $apiKeyAuthenticationMiddleware,
        ApiRateLimitMiddleware $apiRateLimitMiddleware,
        ApiRateLimitMiddleware $chatRateLimitMiddleware,
        PublicChatbotController $publicChatbotController,
        ApiRequestLoggingMiddleware $publicApiRequestLoggingMiddleware,
        PublicChatbotCorsMiddleware $publicChatbotConfigCorsMiddleware,
        PublicChatbotCorsMiddleware $publicChatbotSessionCorsMiddleware,
        PublicChatbotRateLimitMiddleware $publicChatbotConfigRateLimitMiddleware,
        PublicChatbotRateLimitMiddleware $publicChatbotSessionRateLimitMiddleware,
        PublicChatbotCorsMiddleware $publicChatbotMessageCorsMiddleware,
        PublicChatbotSessionAuthenticationMiddleware $publicChatbotSessionAuthenticationMiddleware,
        PublicChatbotRateLimitMiddleware $publicChatbotMessageRateLimitMiddleware,
        ChatbotIntegrationController $chatbotIntegrationController,
        ChatbotIntegrationAuthenticationMiddleware $chatbotIntegrationAuthenticationMiddleware,
        ChatbotIntegrationRateLimitMiddleware $chatbotIntegrationRateLimitMiddleware,
        ChatbotIntegrationCredentialController $chatbotIntegrationCredentialController,
    ): void {
        $router->group('/api/integrations/v1', [$chatbotIntegrationAuthenticationMiddleware, $chatbotIntegrationRateLimitMiddleware], static function (Router $router) use ($chatbotIntegrationController, $publicApiRequestLoggingMiddleware): void {
            $router->post('/chatbots/{chatbotPublicId}/sessions', [$chatbotIntegrationController, 'createSession'], [$publicApiRequestLoggingMiddleware], 'api.integrations.v1.sessions.create');
            $router->post('/chatbots/{chatbotPublicId}/sessions/{sessionId}/messages', [$chatbotIntegrationController, 'message'], [$publicApiRequestLoggingMiddleware], 'api.integrations.v1.messages.create');
        });
        $router->group('/api/public/v1', [], static function (Router $router) use (
            $publicChatbotController,
            $publicApiRequestLoggingMiddleware,
            $publicChatbotConfigCorsMiddleware,
            $publicChatbotSessionCorsMiddleware,
            $publicChatbotConfigRateLimitMiddleware,
            $publicChatbotSessionRateLimitMiddleware,
            $publicChatbotMessageCorsMiddleware,
            $publicChatbotSessionAuthenticationMiddleware,
            $publicChatbotMessageRateLimitMiddleware,
        ): void {
            $router->get(
                '/chatbots/{chatbotPublicId}/config',
                [$publicChatbotController, 'configuration'],
                [$publicChatbotConfigCorsMiddleware, $publicApiRequestLoggingMiddleware, $publicChatbotConfigRateLimitMiddleware],
                'api.public.v1.chatbots.config',
            );
            $router->add(
                'OPTIONS',
                '/chatbots/{chatbotPublicId}/config',
                static fn (Request $request): Response => new Response('', 204),
                [$publicChatbotConfigCorsMiddleware],
                'api.public.v1.chatbots.config.options',
            );
            $router->post(
                '/chatbots/{chatbotPublicId}/sessions',
                [$publicChatbotController, 'createSession'],
                [$publicChatbotSessionCorsMiddleware, $publicApiRequestLoggingMiddleware, $publicChatbotSessionRateLimitMiddleware],
                'api.public.v1.chatbots.sessions.create',
            );
            $router->add(
                'OPTIONS',
                '/chatbots/{chatbotPublicId}/sessions',
                static fn (Request $request): Response => new Response('', 204),
                [$publicChatbotSessionCorsMiddleware],
                'api.public.v1.chatbots.sessions.options',
            );
            $router->post(
                '/chatbots/{chatbotPublicId}/sessions/{sessionId}/messages',
                [$publicChatbotController, 'message'],
                [
                    $publicChatbotMessageCorsMiddleware,
                    $publicChatbotSessionAuthenticationMiddleware,
                    $publicApiRequestLoggingMiddleware,
                    $publicChatbotMessageRateLimitMiddleware,
                ],
                'api.public.v1.chatbots.sessions.messages.create',
            );
            $router->add(
                'OPTIONS',
                '/chatbots/{chatbotPublicId}/sessions/{sessionId}/messages',
                static fn (Request $request): Response => new Response('', 204),
                [$publicChatbotMessageCorsMiddleware],
                'api.public.v1.chatbots.sessions.messages.options',
            );
            $router->post(
                '/chatbots/{chatbotPublicId}/sessions/{sessionId}/complete',
                [$publicChatbotController, 'completeSession'],
                [
                    $publicChatbotMessageCorsMiddleware,
                    $publicChatbotSessionAuthenticationMiddleware,
                    $publicApiRequestLoggingMiddleware,
                ],
                'api.public.v1.chatbots.sessions.complete',
            );
            $router->add(
                'OPTIONS',
                '/chatbots/{chatbotPublicId}/sessions/{sessionId}/complete',
                static fn (Request $request): Response => new Response('', 204),
                [$publicChatbotMessageCorsMiddleware],
                'api.public.v1.chatbots.sessions.complete.options',
            );
        });

        $router->group('/api/v1', [], static function (Router $router) use (
            $healthController,
            $retrieveHandler,
            $chatHandler,
            $apiRequestLoggingMiddleware,
            $apiKeyAuthenticationMiddleware,
            $apiRateLimitMiddleware,
            $chatRateLimitMiddleware,
        ): void {
            $router->get('/health', $healthController, name: 'api.v1.health');
            $router->post(
                '/retrieve',
                $retrieveHandler,
                [$apiRequestLoggingMiddleware, $apiKeyAuthenticationMiddleware, $apiRateLimitMiddleware],
                'api.v1.retrieve',
            );
            $router->post(
                '/chat',
                $chatHandler,
                [$apiRequestLoggingMiddleware, $apiKeyAuthenticationMiddleware, $chatRateLimitMiddleware],
                'api.v1.chat',
            );
        });

        $router->get('/', static fn (Request $request): Response => Response::redirect('/admin'), name: 'home');
        $router->get('/admin/login', [$authController, 'showLogin'], [$sessionMiddleware], 'admin.login');
        $router->post('/admin/login', [$authController, 'login'], [$sessionMiddleware, $csrfMiddleware], 'admin.login.submit');

        $router->group(
            '/admin',
            [$sessionMiddleware, $adminAuthenticationMiddleware, $csrfMiddleware],
            static function (Router $router) use (
                $authController,
                $dashboardController,
                $sourceController,
                $chatbotController,
                $jobController,
                $apiKeyController,
                $apiRequestLogController,
                $chatbotConversationController,
                $chatbotIntegrationCredentialController,
            ): void {
                $router->get('/', $dashboardController, name: 'admin.dashboard');
                $router->post('/logout', [$authController, 'logout'], name: 'admin.logout');
                $router->get('/sources', [$sourceController, 'index'], name: 'admin.sources.index');
                $router->get('/sources/create', [$sourceController, 'create'], name: 'admin.sources.create');
                $router->post('/sources', [$sourceController, 'store'], name: 'admin.sources.store');
                $router->get('/sources/{sourceId}', [$sourceController, 'show'], name: 'admin.sources.show');
                $router->get('/sources/{sourceId}/versions/{versionId}', [$sourceController, 'version'], name: 'admin.sources.versions.show');
                $router->get('/sources/{sourceId}/replace', [$sourceController, 'replace'], name: 'admin.sources.replace');
                $router->post('/sources/{sourceId}/replacement', [$sourceController, 'storeReplacement'], name: 'admin.sources.replacement');
                $router->post('/sources/{sourceId}/refresh', [$sourceController, 'refresh'], name: 'admin.sources.refresh');
                $router->post('/sources/{sourceId}/versions/{versionId}/reprocess', [$sourceController, 'reprocess'], name: 'admin.sources.reprocess');
                $router->post('/sources/{sourceId}/disable', [$sourceController, 'disable'], name: 'admin.sources.disable');
                $router->post('/sources/{sourceId}/enable', [$sourceController, 'enable'], name: 'admin.sources.enable');
                $router->post('/sources/{sourceId}/delete', [$sourceController, 'delete'], name: 'admin.sources.delete');
                $router->post('/sources/{sourceId}/permanent-delete', [$sourceController, 'permanentDelete'], name: 'admin.sources.permanent_delete');
                $router->get('/chatbots', [$chatbotController, 'index'], name: 'admin.chatbots.index');
                $router->get('/chatbots/create', [$chatbotController, 'create'], name: 'admin.chatbots.create');
                $router->post('/chatbots', [$chatbotController, 'store'], name: 'admin.chatbots.store');
                $router->get('/chatbots/{chatbotId}/edit', [$chatbotController, 'edit'], name: 'admin.chatbots.edit');
                $router->get('/chatbots/{chatbotId}/preview', [$chatbotController, 'preview'], name: 'admin.chatbots.preview');
                $router->post('/chatbots/{chatbotId}/preview/messages', [$chatbotController, 'sendPreviewMessage'], name: 'admin.chatbots.preview.messages');
                $router->post('/chatbots/{chatbotId}/preview/restart', [$chatbotController, 'restartPreview'], name: 'admin.chatbots.preview.restart');
                $router->post('/chatbots/{chatbotId}', [$chatbotController, 'update'], name: 'admin.chatbots.update');
                $router->post('/chatbots/{chatbotId}/origins', [$chatbotController, 'updateOrigins'], name: 'admin.chatbots.origins');
                $router->post('/chatbots/{chatbotId}/sources/{sourceId}/assign', [$chatbotController, 'assignSource'], name: 'admin.chatbots.sources.assign');
                $router->post('/chatbots/{chatbotId}/sources/{sourceId}/remove', [$chatbotController, 'removeSource'], name: 'admin.chatbots.sources.remove');
                $router->post('/chatbots/{chatbotId}/publish', [$chatbotController, 'publish'], name: 'admin.chatbots.publish');
                $router->post('/chatbots/{chatbotId}/disable', [$chatbotController, 'disable'], name: 'admin.chatbots.disable');
                $router->post('/chatbots/{chatbotId}/enable', [$chatbotController, 'enable'], name: 'admin.chatbots.enable');
                $router->post('/chatbots/{chatbotId}/archive', [$chatbotController, 'archive'], name: 'admin.chatbots.archive');
                $router->post('/chatbots/{chatbotId}/rotate-public-id', [$chatbotController, 'rotatePublicId'], name: 'admin.chatbots.rotate_public_id');
                $router->post('/chatbots/{chatbotId}/permanent-delete', [$chatbotController, 'permanentlyDelete'], name: 'admin.chatbots.permanent_delete');
                $router->get('/conversations', [$chatbotConversationController, 'index'], name: 'admin.conversations.index');
                $router->get('/conversations/{sessionId}', [$chatbotConversationController, 'show'], name: 'admin.conversations.show');
                $router->post('/conversations/purge/preview', [$chatbotConversationController, 'previewPurge'], name: 'admin.conversations.purge_preview');
                $router->post('/conversations/purge', [$chatbotConversationController, 'executePurge'], name: 'admin.conversations.purge_execute');
                $router->get('/integration-credentials', [$chatbotIntegrationCredentialController, 'index'], name: 'admin.integration_credentials.index');
                $router->get('/integration-credentials/create', [$chatbotIntegrationCredentialController, 'create'], name: 'admin.integration_credentials.create');
                $router->post('/integration-credentials', [$chatbotIntegrationCredentialController, 'store'], name: 'admin.integration_credentials.store');
                $router->post('/integration-credentials/{credentialId}/revoke', [$chatbotIntegrationCredentialController, 'revoke'], name: 'admin.integration_credentials.revoke');
                $router->post('/integration-credentials/{credentialId}/delete', [$chatbotIntegrationCredentialController, 'delete'], name: 'admin.integration_credentials.delete');
                $router->get('/jobs', $jobController, name: 'admin.jobs.index');
                $router->get('/api-keys', [$apiKeyController, 'index'], name: 'admin.api_keys.index');
                $router->get('/api-keys/create', [$apiKeyController, 'create'], name: 'admin.api_keys.create');
                $router->post('/api-keys', [$apiKeyController, 'store'], name: 'admin.api_keys.store');
                $router->post('/api-keys/{apiKeyId}/revoke', [$apiKeyController, 'revoke'], name: 'admin.api_keys.revoke');
                $router->post('/api-keys/{apiKeyId}/delete', [$apiKeyController, 'delete'], name: 'admin.api_keys.delete');
                $router->get('/api-requests', $apiRequestLogController, name: 'admin.api_requests.index');
                $router->get('/api-requests/purge', [$apiRequestLogController, 'purge'], name: 'admin.api_requests.purge');
                $router->post('/api-requests/purge/preview', [$apiRequestLogController, 'previewPurge'], name: 'admin.api_requests.purge_preview');
                $router->post('/api-requests/purge', [$apiRequestLogController, 'executePurge'], name: 'admin.api_requests.purge_execute');
            },
        );
    },
];
