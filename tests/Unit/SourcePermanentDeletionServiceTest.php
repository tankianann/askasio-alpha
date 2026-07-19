<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Sources\SourceType;
use App\Exceptions\ValidationException;
use App\Services\Sources\SourceFileStorage;
use App\Services\Sources\SourcePermanentDeletionService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Tests\Fakes\InMemorySourceRepository;

final class SourcePermanentDeletionServiceTest extends TestCase
{
    private string $storageRoot;

    protected function setUp(): void
    {
        $this->storageRoot = sys_get_temp_dir() . '/rag-source-delete-' . bin2hex(random_bytes(8));
        mkdir($this->storageRoot, 0700, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->storageRoot . '/.trash')) {
            rmdir($this->storageRoot . '/.trash');
        }

        if (is_dir($this->storageRoot)) {
            rmdir($this->storageRoot);
        }
    }

    public function testItPermanentlyDeletesDatabaseRecordsAndPrivateFiles(): void
    {
        $sources = new InMemorySourceRepository();
        $source = $sources->createSource('Old handbook', SourceType::Markdown);
        $sources->createFileVersion(
            $source->id,
            'old.md',
            $source->id . '/random.md',
            str_repeat('b', 64),
            'text/plain',
            7,
        );
        $sources->softDelete($source->id);
        mkdir($this->storageRoot . '/' . $source->id, 0700);
        file_put_contents($this->storageRoot . '/' . $source->id . '/random.md', 'content');

        $this->service($sources)->delete($sources->findById($source->id), 'Old handbook');

        self::assertNull($sources->findById($source->id));
        self::assertDirectoryDoesNotExist($this->storageRoot . '/' . $source->id);
    }

    public function testItRequiresTheExactSourceNameAndPreservesTheSourceOnFailure(): void
    {
        $sources = new InMemorySourceRepository();
        $source = $sources->createSource('Old handbook', SourceType::Markdown);
        $sources->softDelete($source->id);

        try {
            $this->service($sources)->delete($sources->findById($source->id), 'old handbook');
            self::fail('Expected confirmation validation to fail.');
        } catch (ValidationException) {
            self::assertNotNull($sources->findById($source->id));
        }
    }

    public function testItRequiresSoftDeletionFirst(): void
    {
        $sources = new InMemorySourceRepository();
        $source = $sources->createSource('Current handbook', SourceType::Markdown);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Soft-delete');

        $this->service($sources)->delete($source, 'Current handbook');
    }

    private function service(InMemorySourceRepository $sources): SourcePermanentDeletionService
    {
        return new SourcePermanentDeletionService(
            $sources,
            new SourceFileStorage($this->storageRoot),
            new NullLogger(),
        );
    }
}
