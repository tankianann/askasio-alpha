<?php

declare(strict_types=1);

namespace App\Ingestion;

use App\Domain\Ingestion\Chunk;
use App\Domain\Ingestion\IngestionJob;
use App\Repositories\SourceIngestionRepositoryInterface;

final class DocumentIngestionProcessor implements IngestionProcessorInterface
{
    public function __construct(
        private readonly SourceIngestionRepositoryInterface $sources,
        private readonly ExtractorRegistry $extractors,
        private readonly ChunkerInterface $chunker,
    ) {
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function process(IngestionJob $job): void
    {
        $version = $this->sources->findVersion($job->sourceVersionId);

        if ($version === null) {
            throw new PermanentIngestionException('The source version no longer exists.');
        }

        $document = $this->extractors->forVersion($version)->extract($version);
        $chunks = $this->chunker->chunk($document);

        if ($chunks === [] || array_filter($chunks, static fn (mixed $chunk): bool => !$chunk instanceof Chunk) !== []) {
            throw new PermanentIngestionException('The extracted document did not produce valid chunks.');
        }

        $this->sources->storeAndActivate($version, $document, $chunks);
    }
}
