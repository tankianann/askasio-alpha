<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Sources\SourceType;
use App\Domain\Sources\ProcessingStatus;
use App\Domain\Sources\SourceVersion;
use App\Exceptions\ValidationException;
use App\Security\SourceUploadValidator;
use App\Security\UrlSourceValidator;
use App\Services\Ingestion\IngestionQueue;
use App\Services\Sources\SourceFileStorage;
use App\Services\Sources\SourceUpdateService;
use PHPUnit\Framework\TestCase;
use Tests\Fakes\InMemoryIngestionJobRepository;
use Tests\Fakes\InMemorySourceRepository;
use App\Support\Pagination\PageRequest;

final class SourceUpdateServiceTest extends TestCase
{
    public function testUrlRefreshCreatesANewImmutablePendingVersion(): void
    {
        $sources = new InMemorySourceRepository();
        $jobs = new InMemoryIngestionJobRepository();
        $source = $sources->createSource('Documentation', SourceType::Url);
        $original = $sources->createUrlVersion($source->id, 'https://example.com/docs');

        $replacement = $this->service($sources, $jobs)->refreshUrl($source);

        self::assertSame(1, $original->versionNumber);
        self::assertSame(2, $replacement->versionNumber);
        self::assertSame($original->originalUrl, $replacement->originalUrl);
        self::assertSame(2, $sources->paginateVersionsForSource($source->id, new PageRequest())->total);
        self::assertSame(1, $jobs->counts()['pending']);
    }

    public function testReprocessingAFileReferencesTheImmutableStoredFileInANewVersion(): void
    {
        $sources = new InMemorySourceRepository();
        $jobs = new InMemoryIngestionJobRepository();
        $source = $sources->createSource('Handbook', SourceType::Markdown);
        $original = $sources->createFileVersion(
            $source->id,
            'handbook.md',
            '1/random.md',
            str_repeat('a', 64),
            'text/plain',
            123,
        );
        $processed = new SourceVersion(
            $original->id,
            $original->sourceId,
            $original->versionNumber,
            $original->originalFilename,
            $original->originalUrl,
            $original->storedFilePath,
            str_repeat('c', 64),
            $original->mimeType,
            $original->fileSize,
            ProcessingStatus::Ready,
            null,
            $original->createdAt,
            '2026-07-17 00:01:00.000000',
            '2026-07-17 00:01:00.000000',
            $original->fileHash,
            SourceType::Markdown,
        );

        $copy = $this->service($sources, $jobs)->reprocess($source, $processed);

        self::assertSame(2, $copy->versionNumber);
        self::assertSame($original->storedFilePath, $copy->storedFilePath);
        self::assertSame($original->fileHash, $copy->fileHash);
        self::assertSame(1, $jobs->counts()['pending']);
    }

    public function testItRejectsAnotherVersionWhileAJobIsInFlight(): void
    {
        $sources = new InMemorySourceRepository();
        $source = $sources->createSource('Documentation', SourceType::Url);
        $sources->createUrlVersion($source->id, 'https://example.com/docs');
        $sources->inFlightJobs = true;

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Wait for the current source job');

        $this->service($sources, new InMemoryIngestionJobRepository())->refreshUrl($source);
    }

    private function service(
        InMemorySourceRepository $sources,
        InMemoryIngestionJobRepository $jobs,
    ): SourceUpdateService {
        return new SourceUpdateService(
            $sources,
            new UrlSourceValidator(),
            new SourceUploadValidator(1024 * 1024),
            new SourceFileStorage(sys_get_temp_dir()),
            new IngestionQueue($jobs, 3, 30, 3600, 900),
        );
    }
}
