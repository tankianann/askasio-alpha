<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Sources\ProcessingStatus;
use App\Domain\Sources\SourceType;
use App\Exceptions\ValidationException;
use App\Security\SourceUploadValidator;
use App\Security\UrlSourceValidator;
use App\Services\Sources\SourceCreationService;
use App\Services\Sources\SourceFileStorage;
use PHPUnit\Framework\TestCase;
use Tests\Fakes\InMemorySourceRepository;

final class SourceCreationServiceTest extends TestCase
{
    public function testItCreatesAUrlSourceAndImmutablePendingVersion(): void
    {
        $repository = new InMemorySourceRepository();
        $service = new SourceCreationService(
            $repository,
            new UrlSourceValidator(),
            new SourceUploadValidator(1024),
            new SourceFileStorage(sys_get_temp_dir()),
        );

        $source = $service->createUrl('  Refund policy  ', 'https://example.com/refunds');
        $versions = $repository->versionsForSource($source->id);

        self::assertSame('Refund policy', $source->name);
        self::assertSame(SourceType::Url, $source->type);
        self::assertCount(1, $versions);
        self::assertSame(1, $versions[0]->versionNumber);
        self::assertSame('https://example.com/refunds', $versions[0]->originalUrl);
        self::assertSame(ProcessingStatus::Pending, $versions[0]->processingStatus);
        self::assertNull($source->activeVersionId);
    }

    public function testItRejectsInvalidNamesBeforeCreatingRecords(): void
    {
        $repository = new InMemorySourceRepository();
        $service = new SourceCreationService(
            $repository,
            new UrlSourceValidator(),
            new SourceUploadValidator(1024),
            new SourceFileStorage(sys_get_temp_dir()),
        );

        try {
            $service->createUrl('', 'https://example.com/refunds');
            self::fail('Expected validation to fail.');
        } catch (ValidationException) {
            self::assertSame([], $repository->all());
        }
    }
}
