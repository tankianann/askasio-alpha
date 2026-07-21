<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\Admin\AiUsageController;
use App\Domain\Admin\AdminUser;
use App\Domain\ProviderQuota\AiUsageReport;
use App\Http\Request;
use App\Security\CsrfTokenManager;
use App\Services\ProviderQuota\AiUsageQueryParser;
use App\Services\ProviderQuota\ProviderQuotaService;
use App\Support\ViewRenderer;
use PHPUnit\Framework\TestCase;
use Tests\Fakes\InMemoryAiUsageRepository;
use Tests\Fakes\InMemoryProviderQuotaRepository;
use Tests\Fakes\InMemorySessionStore;

final class AiUsageControllerTest extends TestCase
{
    public function testDashboardSeparatesInstallationTotalsFromAttributedUsage(): void
    {
        $quotaRepository = new InMemoryProviderQuotaRepository();
        $quotas = new ProviderQuotaService($quotaRepository, 50_000, 500_000, 20_000, 200_000, 900);
        $reservation = $quotas->reserveInstallationChat('Question', 1_000, 200);
        $quotas->reconcile($reservation, 150);
        $session = new InMemorySessionStore();
        $report = new AiUsageReport(
            120,
            20,
            2,
            120,
            120,
            [['access_method' => 'browser_chatbot', 'usage_tokens' => 120, 'estimated_tokens' => 20, 'requests' => 2]],
            [['day' => gmdate('Y-m-d'), 'usage_tokens' => 120, 'estimated_tokens' => 20]],
            [['id' => 1, 'name' => 'Support bot', 'usage_tokens' => 120, 'estimated_tokens' => 20]],
            [],
            [],
        );
        $controller = new AiUsageController(
            new InMemoryAiUsageRepository($report),
            $quotas,
            new AiUsageQueryParser(),
            new ViewRenderer(dirname(__DIR__, 2) . '/resources/views'),
            new CsrfTokenManager($session),
            $session,
            'testing',
        );

        $response = $controller(new Request(
            'GET',
            '/admin/ai-usage',
            attributes: ['admin_user' => new AdminUser(1, 'asio', 'not-used')],
        ));

        self::assertSame(200, $response->status());
        self::assertStringContainsString('AI usage tokens today (UTC)', $response->body());
        self::assertStringContainsString('Browser chatbot', $response->body());
        self::assertStringContainsString('Support bot', $response->body());
        self::assertStringContainsString('30 unattributed', $response->body());
        self::assertStringContainsString('150', $response->body());
    }
}
