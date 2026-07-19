<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\Admin\ApiKeyController;
use App\Controllers\Admin\JobController;
use App\Controllers\Admin\SourceController;
use App\Domain\Admin\AdminUser;
use App\Domain\Sources\SourceType;
use App\Http\Request;
use App\Security\CsrfTokenManager;
use App\Security\SourceUploadValidator;
use App\Security\UrlSourceValidator;
use App\Services\ApiKeys\ApiKeyListQueryParser;
use App\Services\ApiKeys\ApiKeyService;
use App\Services\Ingestion\IngestionJobListQueryParser;
use App\Services\Ingestion\IngestionQueue;
use App\Services\Sources\SourceCreationService;
use App\Services\Sources\SourceFileStorage;
use App\Services\Sources\SourceListQueryParser;
use App\Services\Sources\SourcePermanentDeletionService;
use App\Services\Sources\SourceUpdateService;
use App\Support\ViewRenderer;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Tests\Fakes\InMemoryApiKeyRepository;
use Tests\Fakes\InMemoryIngestionJobRepository;
use Tests\Fakes\InMemorySessionStore;
use Tests\Fakes\InMemorySourceRepository;

final class DashboardListControllerTest extends TestCase
{
    public function testKnowledgeBaseRendersServerPaginationAndPreservesFilters(): void
    {
        $sources = new InMemorySourceRepository();

        for ($index = 1; $index <= 30; ++$index) {
            $sources->createSource(sprintf('Policy %02d', $index), SourceType::Pdf);
        }

        $response = $this->sourceController($sources)->index(new Request(
            'GET',
            '/admin/sources?page=2&search=Policy&type=pdf',
            query: ['page' => '2', 'search' => 'Policy', 'type' => 'pdf'],
            attributes: ['admin_user' => $this->admin()],
        ));

        self::assertSame(200, $response->status());
        self::assertStringContainsString('Showing <strong>26–30</strong> of <strong>30</strong> documents', $response->body());
        self::assertStringContainsString('search=Policy', $response->body());
        self::assertStringContainsString('type=pdf', $response->body());
        self::assertStringNotContainsString('Policy 30</a>', $response->body());
    }

    public function testProcessingReplacesThePreviousHundredRecordCapWithPagination(): void
    {
        $jobs = new InMemoryIngestionJobRepository();

        for ($index = 1; $index <= 105; ++$index) {
            $jobs->enqueue($index, 3);
        }

        $session = new InMemorySessionStore();
        $queue = $this->queue($jobs);
        $controller = new JobController(
            $queue,
            $this->views(),
            new CsrfTokenManager($session),
            'testing',
            true,
            $session,
            new IngestionJobListQueryParser('Asia/Singapore'),
        );
        $response = $controller(new Request(
            'GET',
            '/admin/jobs?page=5&status=pending',
            query: ['page' => '5', 'status' => 'pending'],
            attributes: ['admin_user' => $this->admin()],
        ));

        self::assertSame(200, $response->status());
        self::assertStringContainsString('Showing <strong>101–105</strong> of <strong>105</strong> processing records', $response->body());
        self::assertStringContainsString('status=pending', $response->body());
        self::assertStringContainsString('Page 5 of 5', $response->body());
    }

    public function testApiAccessRendersFilteredEmptyStateAndCanonicalInvalidPage(): void
    {
        $keys = new InMemoryApiKeyRepository();

        for ($index = 1; $index <= 30; ++$index) {
            $keys->create(1, sprintf('Connection %02d', $index), 'rag_live_abcd', hash('sha256', (string) $index), null);
        }

        $controller = $this->apiKeyController($keys);
        $empty = $controller->index(new Request(
            'GET',
            '/admin/api-keys?search=missing',
            query: ['search' => 'missing'],
            attributes: ['admin_user' => $this->admin()],
        ));
        self::assertSame(200, $empty->status());
        self::assertStringContainsString('No connections match these filters', $empty->body());

        $redirect = $controller->index(new Request(
            'GET',
            '/admin/api-keys?page=99',
            query: ['page' => '99'],
            attributes: ['admin_user' => $this->admin()],
        ));
        self::assertSame(302, $redirect->status());
        self::assertSame('/admin/api-keys?page=2', $redirect->headers()['Location']);
    }

    public function testInvalidListQueryRedirectsWithSafeFeedback(): void
    {
        $keys = new InMemoryApiKeyRepository();
        $session = new InMemorySessionStore();
        $controller = $this->apiKeyController($keys, $session);
        $invalid = $controller->index(new Request(
            'GET',
            '/admin/api-keys',
            query: ['sort' => 'secret_hash'],
            attributes: ['admin_user' => $this->admin()],
        ));

        self::assertSame(302, $invalid->status());
        self::assertSame('/admin/api-keys', $invalid->headers()['Location']);

        $followed = $controller->index(new Request(
            'GET',
            '/admin/api-keys',
            attributes: ['admin_user' => $this->admin()],
        ));
        self::assertStringContainsString('The sort parameter is invalid.', $followed->body());
        self::assertStringNotContainsString('secret_hash', $followed->body());
    }

    private function sourceController(InMemorySourceRepository $sources): SourceController
    {
        $session = new InMemorySessionStore();
        $queue = $this->queue(new InMemoryIngestionJobRepository());
        $storage = new SourceFileStorage(sys_get_temp_dir());
        $urls = new UrlSourceValidator();
        $uploads = new SourceUploadValidator(1024 * 1024);

        return new SourceController(
            $sources,
            new SourceCreationService($sources, $urls, $uploads, $storage, $queue),
            $this->views(),
            new CsrfTokenManager($session),
            $session,
            'testing',
            1,
            $queue,
            new SourceUpdateService($sources, $urls, $uploads, $storage, $queue),
            new SourcePermanentDeletionService($sources, $storage, new NullLogger()),
            new SourceListQueryParser('Asia/Singapore'),
        );
    }

    private function apiKeyController(
        InMemoryApiKeyRepository $keys,
        ?InMemorySessionStore $session = null,
    ): ApiKeyController {
        $session ??= new InMemorySessionStore();

        return new ApiKeyController(
            $keys,
            new ApiKeyService($keys),
            $this->views(),
            new CsrfTokenManager($session),
            $session,
            'testing',
            'Asia/Singapore',
            new ApiKeyListQueryParser('Asia/Singapore'),
        );
    }

    private function queue(InMemoryIngestionJobRepository $jobs): IngestionQueue
    {
        return new IngestionQueue($jobs, 3, 30, 3600, 1800);
    }

    private function views(): ViewRenderer
    {
        return new ViewRenderer(dirname(__DIR__, 2) . '/resources/views');
    }

    private function admin(): AdminUser
    {
        return new AdminUser(1, 'archie', 'not-used');
    }
}
