<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\Admin\SourceController;
use App\Domain\Admin\AdminUser;
use App\Domain\Sources\ProcessingStatus;
use App\Domain\Sources\SourceType;
use App\Domain\Sources\SourceVersion;
use App\Http\Request;
use App\Security\CsrfTokenManager;
use App\Security\SourceUploadValidator;
use App\Security\UrlSourceValidator;
use App\Services\Ingestion\IngestionQueue;
use App\Services\Sources\SourceCreationService;
use App\Services\Sources\SourceFileStorage;
use App\Services\Sources\SourceHistoryQueryParser;
use App\Services\Sources\SourceListQueryParser;
use App\Services\Sources\SourcePermanentDeletionService;
use App\Services\Sources\SourceUpdateService;
use App\Support\ViewRenderer;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Tests\Fakes\InMemoryIngestionJobRepository;
use Tests\Fakes\InMemorySessionStore;
use Tests\Fakes\InMemorySourceRepository;

final class SourceDetailHistoryTest extends TestCase
{
    public function testRevisionAndProcessingHistoriesArePaginatedIndependently(): void
    {
        $sources = new InMemorySourceRepository();
        $jobs = new InMemoryIngestionJobRepository();
        $source = $sources->createSource('Long history', SourceType::Url);

        for ($number = 1; $number <= 25; ++$number) {
            $version = $sources->createUrlVersion($source->id, 'https://example.com/history');
            $jobs->enqueue($version->id, 3);
        }

        $response = $this->controller($sources, $jobs)->show($this->request(
            '/admin/sources/1?revision_page=2&job_page=3',
            ['sourceId' => '1'],
            ['revision_page' => '2', 'job_page' => '3'],
        ));

        self::assertSame(200, $response->status());
        self::assertStringContainsString('Showing <strong>11–20</strong> of <strong>25</strong> revisions', $response->body());
        self::assertStringContainsString('Showing <strong>21–25</strong> of <strong>25</strong> processing records', $response->body());
        self::assertStringContainsString('Revision 15', $response->body());
        self::assertStringNotContainsString('Revision 25</h3>', $response->body());
        self::assertStringContainsString('revision_page=2', $response->body());
        self::assertStringContainsString('job_page=3', $response->body());
        self::assertStringContainsString('versions/15?job_page=3&amp;revision_page=2', $response->body());
    }

    public function testOutOfRangeHistoryPagesRedirectToTheLastValidState(): void
    {
        $sources = new InMemorySourceRepository();
        $jobs = new InMemoryIngestionJobRepository();
        $source = $sources->createSource('Short history', SourceType::Url);
        $version = $sources->createUrlVersion($source->id, 'https://example.com/history');
        $jobs->enqueue($version->id, 3);

        $response = $this->controller($sources, $jobs)->show($this->request(
            '/admin/sources/1?revision_page=99&job_page=99',
            ['sourceId' => '1'],
            ['revision_page' => '99', 'job_page' => '99'],
        ));

        self::assertSame(302, $response->status());
        self::assertSame('/admin/sources/1', $response->headers()['Location']);
    }

    public function testLargeExtractedContentIsExcludedFromHistoryAndLoadedOnlyForItsRevision(): void
    {
        $sources = new InMemorySourceRepository();
        $jobs = new InMemoryIngestionJobRepository();
        $source = $sources->createSource('Large PDF', SourceType::Pdf);
        $original = $sources->createFileVersion(
            $source->id,
            'large.pdf',
            '1/random.pdf',
            str_repeat('a', 64),
            'application/pdf',
            4_000_000,
        );
        $marker = 'LARGE_DOCUMENT_SENTINEL_' . str_repeat('x', 1_000_000);
        $sources->replaceVersion(new SourceVersion(
            $original->id,
            $original->sourceId,
            $original->versionNumber,
            $original->originalFilename,
            $original->originalUrl,
            $original->storedFilePath,
            str_repeat('b', 64),
            $original->mimeType,
            $original->fileSize,
            ProcessingStatus::Ready,
            null,
            $original->createdAt,
            '2026-07-19 01:00:00.000000',
            '2026-07-19 01:00:00.000000',
            $original->fileHash,
            SourceType::Pdf,
            500,
            $marker,
            ['pages' => 800],
        ));

        $controller = $this->controller($sources, $jobs);
        $history = $controller->show($this->request('/admin/sources/1', ['sourceId' => '1']));

        self::assertSame(200, $history->status());
        self::assertStringNotContainsString('LARGE_DOCUMENT_SENTINEL_', $history->body());
        self::assertLessThan(100_000, strlen($history->body()));
        self::assertStringContainsString('/admin/sources/1/versions/1', $history->body());

        $detail = $controller->version($this->request(
            '/admin/sources/1/versions/1?revision_page=2&job_page=3',
            ['sourceId' => '1', 'versionId' => '1'],
            ['revision_page' => '2', 'job_page' => '3'],
        ));

        self::assertSame(200, $detail->status());
        self::assertStringContainsString('LARGE_DOCUMENT_SENTINEL_', $detail->body());
        self::assertStringContainsString('&quot;pages&quot;: 800', $detail->body());
        self::assertStringContainsString('/admin/sources/1?job_page=3&amp;revision_page=2', $detail->body());
    }

    /** @param array<string, string> $routes
     *  @param array<string, mixed> $query
     */
    private function request(string $uri, array $routes, array $query = []): Request
    {
        return (new Request(
            'GET',
            $uri,
            query: $query,
            attributes: ['admin_user' => new AdminUser(1, 'archie', 'not-used')],
        ))->withRouteParameters($routes);
    }

    private function controller(
        InMemorySourceRepository $sources,
        InMemoryIngestionJobRepository $jobs,
    ): SourceController {
        $session = new InMemorySessionStore();
        $queue = new IngestionQueue($jobs, 3, 30, 3600, 1800);
        $storage = new SourceFileStorage(sys_get_temp_dir());
        $urls = new UrlSourceValidator();
        $uploads = new SourceUploadValidator(1024 * 1024);

        return new SourceController(
            $sources,
            new SourceCreationService($sources, $urls, $uploads, $storage, $queue),
            new ViewRenderer(dirname(__DIR__, 2) . '/resources/views'),
            new CsrfTokenManager($session),
            $session,
            'testing',
            1,
            $queue,
            new SourceUpdateService($sources, $urls, $uploads, $storage, $queue),
            new SourcePermanentDeletionService($sources, $storage, new NullLogger()),
            new SourceListQueryParser('Asia/Singapore'),
            new SourceHistoryQueryParser('Asia/Singapore'),
        );
    }
}
