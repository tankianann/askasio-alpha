<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Auth\SessionStoreInterface;
use App\Domain\Admin\AdminUser;
use App\Exceptions\ValidationException;
use App\Http\Request;
use App\Http\Response;
use App\Repositories\AiUsageRepositoryInterface;
use App\Security\CsrfTokenManager;
use App\Services\ProviderQuota\AiUsageQueryParser;
use App\Services\ProviderQuota\ProviderQuotaService;
use App\Support\ViewRenderer;
use DateTimeImmutable;
use DateTimeZone;

final readonly class AiUsageController
{
    private const FLASH_ERROR = '_flash_ai_usage_error';

    public function __construct(
        private AiUsageRepositoryInterface $usage,
        private ProviderQuotaService $quotas,
        private AiUsageQueryParser $queries,
        private ViewRenderer $views,
        private CsrfTokenManager $csrf,
        private SessionStoreInterface $session,
        private string $environment,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $admin = $request->attribute('admin_user');

        if (!$admin instanceof AdminUser) {
            throw new \LogicException('Authenticated administrator is missing from the request.');
        }

        try {
            $query = $this->queries->parse($request);
        } catch (ValidationException $exception) {
            $this->session->put(self::FLASH_ERROR, $exception->getMessage());

            return Response::redirect('/admin/ai-usage');
        }

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $quota = $this->quotas->snapshots([])['global'];
        $report = $this->usage->report($query, $now);

        return Response::html($this->views->render('ai_usage/index', [
            'title' => 'AI Usage',
            'admin' => $admin,
            'csrfToken' => $this->csrf->token(),
            'environment' => $this->environment,
            'currentSection' => 'ai_usage',
            'query' => $query,
            'quota' => $quota,
            'report' => $report,
            'todayUnattributed' => max(0, $quota->dailyConsumed - $report->todayRecordedTokens),
            'monthUnattributed' => max(0, $quota->monthlyConsumed - $report->monthRecordedTokens),
            'error' => $this->session->pull(self::FLASH_ERROR),
        ], 'layouts/admin'));
    }
}
