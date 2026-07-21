<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\Admin\ChatbotConversationController;
use App\Domain\Admin\AdminUser;
use App\Http\Request;
use App\Security\CsrfTokenManager;
use App\Services\Chatbots\ChatbotAnalyticsQueryParser;
use App\Services\Chatbots\ChatbotConversationListQueryParser;
use App\Services\Chatbots\ChatbotConversationRetentionService;
use App\Support\ViewRenderer;
use Monolog\Handler\NullHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Tests\Fakes\InMemoryChatbotConversationRepository;
use Tests\Fakes\InMemoryChatbotRepository;
use Tests\Fakes\InMemoryMaintenanceLock;
use Tests\Fakes\InMemorySessionStore;
use Tests\Support\ChatbotFixtures;

final class ChatbotAnalyticsControllerTest extends TestCase
{
    public function testItRendersAContentFreeBoundedSummaryWithTheRequestId(): void
    {
        $session = new InMemorySessionStore();
        $conversations = new InMemoryChatbotConversationRepository();
        $chatbots = new InMemoryChatbotRepository();
        $chatbots->create('cb_analytics', 'Support <script>', null, ChatbotFixtures::draft());
        $logger = new Logger('test');
        $logger->pushHandler(new NullHandler());
        $controller = new ChatbotConversationController(
            $conversations,
            $chatbots,
            new ChatbotConversationListQueryParser('Asia/Singapore'),
            new ChatbotConversationRetentionService(
                $conversations,
                new InMemoryMaintenanceLock(),
                $logger,
                100,
                'chatbot-test',
            ),
            new ViewRenderer(dirname(__DIR__, 2) . '/resources/views'),
            new CsrfTokenManager($session),
            $session,
            'testing',
            new ChatbotAnalyticsQueryParser('Asia/Singapore'),
        );
        $response = $controller->analytics(new Request(
            'GET',
            '/admin/conversations/analytics',
            query: [
                'date_from' => '2026-07-20',
                'date_to' => '2026-07-21',
                'traffic' => 'all',
            ],
            attributes: [
                'admin_user' => new AdminUser(1, 'asio', 'unused'),
                'request_id' => '018f9f3a-7420-7cc1-8a12-8ac550003333',
            ],
        ));

        self::assertSame(200, $response->status());
        self::assertSame('no-store', $response->headers()['Cache-Control']);
        self::assertStringContainsString('Maximum 90 inclusive days', $response->body());
        self::assertStringContainsString('018f9f3a-7420-7cc1-8a12-8ac550003333', $response->body());
        self::assertStringContainsString('Support &lt;script&gt;', $response->body());
        self::assertStringNotContainsString('Support <script>', $response->body());
        self::assertStringContainsString('this page never reads transcript content', $response->body());
    }
}
