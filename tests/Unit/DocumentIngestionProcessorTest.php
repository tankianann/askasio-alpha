<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Ingestion\Chunk;
use App\Domain\Ingestion\ExtractedDocument;
use App\Domain\Ingestion\ExtractedSection;
use App\Domain\Ingestion\IngestionJob;
use App\Domain\Ingestion\JobStatus;
use App\Domain\Sources\ProcessingStatus;
use App\Domain\Sources\SourceType;
use App\Domain\Sources\SourceVersion;
use App\Ingestion\ChunkerInterface;
use App\Ingestion\DocumentIngestionProcessor;
use App\Ingestion\ExtractorRegistry;
use App\Ingestion\PermanentIngestionException;
use App\Ingestion\SourceExtractorInterface;
use App\Repositories\SourceIngestionRepositoryInterface;
use PHPUnit\Framework\TestCase;

final class DocumentIngestionProcessorTest extends TestCase
{
    public function testExtractionFailureDoesNotPersistPartialResults(): void
    {
        $version = $this->version();
        $repository = new class ($version) implements SourceIngestionRepositoryInterface {
            public bool $stored = false;

            public function __construct(private readonly SourceVersion $version)
            {
            }

            public function findVersion(int $id): ?SourceVersion
            {
                return $this->version;
            }

            public function storeAndActivate(SourceVersion $version, ExtractedDocument $document, array $chunks): bool
            {
                $this->stored = true;

                return true;
            }
        };
        $extractor = new class implements SourceExtractorInterface {
            public function supports(SourceVersion $version): bool
            {
                return true;
            }

            public function extract(SourceVersion $version): ExtractedDocument
            {
                throw new PermanentIngestionException('Extraction failed safely.');
            }
        };
        $chunker = new class implements ChunkerInterface {
            public function chunk(ExtractedDocument $document): array
            {
                return [new Chunk(1, $document->content, 1, [])];
            }
        };
        $processor = new DocumentIngestionProcessor(
            $repository,
            new ExtractorRegistry([$extractor]),
            $chunker,
        );

        try {
            $processor->process($this->job());
            self::fail('Expected extraction to fail.');
        } catch (PermanentIngestionException) {
            self::assertFalse($repository->stored);
        }
    }

    private function version(): SourceVersion
    {
        return new SourceVersion(
            7, 3, 1, 'document.md', null, 'document.md', null, 'text/markdown', 100,
            ProcessingStatus::Processing, null, '2026-01-01', null, null, null, SourceType::Markdown,
        );
    }

    private function job(): IngestionJob
    {
        return new IngestionJob(
            9, 7, 'ingest_source_version', JobStatus::Processing, 0, 1, 3,
            '2026-01-01', '2026-01-01', 'worker', null, '2026-01-01', '2026-01-01', null, null,
        );
    }
}
