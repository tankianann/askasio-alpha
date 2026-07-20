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
use App\Ingestion\DocumentSafetyLimits;
use App\Ingestion\ExtractorRegistry;
use App\Ingestion\PermanentIngestionException;
use App\Ingestion\SourceExtractorInterface;
use App\Providers\Embeddings\EmbeddingAuthenticationException;
use App\Providers\Embeddings\EmbeddingService;
use App\Repositories\SourceIngestionRepositoryInterface;
use PHPUnit\Framework\TestCase;
use Tests\Fakes\FakeEmbeddingProvider;

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

            public function storeUnchangedIfActiveMatch(SourceVersion $version, ExtractedDocument $document): bool
            {
                return false;
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
            new EmbeddingService(new FakeEmbeddingProvider(), 10),
        );

        try {
            $processor->process($this->job());
            self::fail('Expected extraction to fail.');
        } catch (PermanentIngestionException) {
            self::assertFalse($repository->stored);
        }
    }

    public function testEmbeddingFailureDoesNotActivateOrPersistChunks(): void
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

            public function storeUnchangedIfActiveMatch(SourceVersion $version, ExtractedDocument $document): bool
            {
                return false;
            }

            public function storeAndActivate(SourceVersion $version, ExtractedDocument $document, array $chunks): bool
            {
                $this->stored = true;

                return true;
            }
        };
        $document = new ExtractedDocument('Document', 'Useful source content.', [
            new ExtractedSection(null, 'Useful source content.', startOffset: 0, endOffset: 22),
        ]);
        $extractor = new class ($document) implements SourceExtractorInterface {
            public function __construct(private readonly ExtractedDocument $document)
            {
            }

            public function supports(SourceVersion $version): bool
            {
                return true;
            }

            public function extract(SourceVersion $version): ExtractedDocument
            {
                return $this->document;
            }
        };
        $chunker = new class implements ChunkerInterface {
            public function chunk(ExtractedDocument $document): array
            {
                return [new Chunk(1, $document->content, 4, [])];
            }
        };
        $provider = new FakeEmbeddingProvider(failure: new EmbeddingAuthenticationException('Invalid credentials.'));
        $processor = new DocumentIngestionProcessor(
            $repository,
            new ExtractorRegistry([$extractor]),
            $chunker,
            new EmbeddingService($provider, 10),
        );

        $this->expectException(PermanentIngestionException::class);

        try {
            $processor->process($this->job());
        } finally {
            self::assertFalse($repository->stored);
        }
    }

    public function testOversizedDocumentStopsBeforeDeduplicationChunkingOrEmbedding(): void
    {
        $version = $this->version();
        $repository = new class ($version) implements SourceIngestionRepositoryInterface {
            public int $unchangedChecks = 0;
            public bool $stored = false;

            public function __construct(private readonly SourceVersion $version)
            {
            }

            public function findVersion(int $id): ?SourceVersion
            {
                return $this->version;
            }

            public function storeUnchangedIfActiveMatch(SourceVersion $version, ExtractedDocument $document): bool
            {
                $this->unchangedChecks++;

                return false;
            }

            public function storeAndActivate(SourceVersion $version, ExtractedDocument $document, array $chunks): bool
            {
                $this->stored = true;

                return true;
            }
        };
        $document = ExtractedDocument::fromSections('Large', [
            new ExtractedSection('Large', str_repeat('x', 101)),
        ]);
        $extractor = new class ($document) implements SourceExtractorInterface {
            public function __construct(private readonly ExtractedDocument $document)
            {
            }

            public function supports(SourceVersion $version): bool
            {
                return true;
            }

            public function extract(SourceVersion $version): ExtractedDocument
            {
                return $this->document;
            }
        };
        $chunker = new class implements ChunkerInterface {
            public int $calls = 0;

            public function chunk(ExtractedDocument $document): array
            {
                $this->calls++;

                return [new Chunk(1, $document->content, 1, [])];
            }
        };
        $provider = new FakeEmbeddingProvider();
        $processor = new DocumentIngestionProcessor(
            $repository,
            new ExtractorRegistry([$extractor]),
            $chunker,
            new EmbeddingService($provider, 10),
            new DocumentSafetyLimits(100, 10, 10),
        );

        $this->expectException(PermanentIngestionException::class);

        try {
            $processor->process($this->job());
        } finally {
            self::assertSame(0, $repository->unchangedChecks);
            self::assertSame(0, $chunker->calls);
            self::assertSame([], $provider->inputs);
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
