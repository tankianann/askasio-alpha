<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\Admin\ApiRequestLogController;
use App\Domain\Admin\AdminUser;
use App\Domain\Api\ApiRequestLog;
use App\Http\Request;
use App\Security\CsrfTokenManager;
use App\Services\Api\ApiRequestLogQueryParser;
use App\Support\ViewRenderer;
use PHPUnit\Framework\TestCase;
use Tests\Fakes\InMemoryApiRequestLogRepository;
use Tests\Fakes\InMemorySessionStore;

final class ApiRequestLogControllerTest extends TestCase
{
    public function testItRendersServerPaginatedResultsCountsAndFilterState(): void
    {
        $repository = $this->repository(30);
        $response = ($this->controller($repository))(new Request(
            'GET',
            '/admin/api-requests?page=2&per_page=25&status_group=success',
            query: ['page' => '2', 'per_page' => '25', 'status_group' => 'success'],
            attributes: ['admin_user' => $this->admin()],
        ));

        self::assertSame(200, $response->status());
        self::assertStringContainsString('Showing <strong>26–30</strong> of <strong>30</strong> requests', $response->body());
        self::assertStringContainsString('Page 2 of 2', $response->body());
        self::assertStringContainsString('Status: Success', $response->body());
        self::assertStringContainsString('status_group=success', $response->body());
    }

    public function testItRendersAFilteredEmptyState(): void
    {
        $response = ($this->controller($this->repository(3)))(new Request(
            'GET',
            '/admin/api-requests',
            query: ['endpoint' => '/api/v1/missing'],
            attributes: ['admin_user' => $this->admin()],
        ));

        self::assertSame(200, $response->status());
        self::assertStringContainsString('No requests match these filters', $response->body());
        self::assertStringContainsString('No requests', $response->body());
    }

    public function testItRedirectsAnInvalidatedPageToTheCanonicalLastPage(): void
    {
        $response = ($this->controller($this->repository(30)))(new Request(
            'GET',
            '/admin/api-requests',
            query: ['page' => '99'],
            attributes: ['admin_user' => $this->admin()],
        ));

        self::assertSame(302, $response->status());
        self::assertSame('/admin/api-requests?page=2', $response->headers()['Location']);
    }

    public function testInvalidQueryStateRedirectsAndReturnsSafeFeedback(): void
    {
        $repository = $this->repository(1);
        $session = new InMemorySessionStore();
        $controller = $this->controller($repository, $session);
        $invalid = $controller(new Request(
            'GET',
            '/admin/api-requests',
            query: ['sort' => 'DROP TABLE'],
            attributes: ['admin_user' => $this->admin()],
        ));

        self::assertSame(302, $invalid->status());
        self::assertSame('/admin/api-requests', $invalid->headers()['Location']);

        $followed = $controller(new Request(
            'GET',
            '/admin/api-requests',
            attributes: ['admin_user' => $this->admin()],
        ));

        self::assertStringContainsString('The sort parameter is invalid.', $followed->body());
        self::assertStringNotContainsString('DROP TABLE', $followed->body());
    }

    private function controller(
        InMemoryApiRequestLogRepository $repository,
        ?InMemorySessionStore $session = null,
    ): ApiRequestLogController {
        $session ??= new InMemorySessionStore();

        return new ApiRequestLogController(
            $repository,
            new ViewRenderer(dirname(__DIR__, 2) . '/resources/views'),
            new CsrfTokenManager($session),
            $session,
            new ApiRequestLogQueryParser('Asia/Singapore'),
            'testing',
        );
    }

    private function repository(int $count): InMemoryApiRequestLogRepository
    {
        $repository = new InMemoryApiRequestLogRepository();

        for ($index = 1; $index <= $count; $index++) {
            $repository->record(new ApiRequestLog(
                sprintf('request-%03d', $index),
                7,
                str_repeat('a', 64),
                'POST',
                '/api/v1/chat',
                200,
                $index * 10,
                null,
                ['total_tokens' => $index],
                sprintf('2026-07-%02d 10:00:00', min($index, 28)),
                'Website',
                'rag_live_abcd',
            ));
        }

        return $repository;
    }

    private function admin(): AdminUser
    {
        return new AdminUser(1, 'archie', 'not-used');
    }
}
