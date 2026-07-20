<?php

declare(strict_types=1);

namespace App\Ingestion;

use App\Domain\Ingestion\Chunk;
use App\Domain\Ingestion\IngestionJob;
use App\Providers\Embeddings\EmbeddingAuthenticationException;
use App\Providers\Embeddings\EmbeddingConfigurationException;
use App\Providers\Embeddings\EmbeddingProviderException;
use App\Providers\Embeddings\EmbeddingService;
use App\Providers\Embeddings\MalformedEmbeddingResponseException;
use App\Repositories\SourceIngestionRepositoryInterface;

final class DocumentIngestionProcessor implements IngestionProcessorInterface
{
    public function __construct(
        private readonly SourceIngestionRepositoryInterface $sources,
        private readonly ExtractorRegistry $extractors,
        private readonly ChunkerInterface $chunker,
        private readonly EmbeddingService $embeddings,
        private readonly ?DocumentSafetyLimits $limits = null,
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
        $this->limits?->assertDocument($document);

        if ($this->sources->storeUnchangedIfActiveMatch($version, $document)) {
            return;
        }

        $chunks = $this->chunker->chunk($document);
        $this->limits?->assertChunkCount(count($chunks));

        if ($chunks === [] || array_filter($chunks, static fn (mixed $chunk): bool => !$chunk instanceof Chunk) !== []) {
            throw new PermanentIngestionException('The extracted document did not produce valid chunks.');
        }

        try {
            $embeddedChunks = $this->embeddings->embedChunks($chunks);
        } catch (EmbeddingConfigurationException|EmbeddingAuthenticationException|MalformedEmbeddingResponseException $exception) {
            throw new PermanentIngestionException($exception->getMessage(), previous: $exception);
        } catch (EmbeddingProviderException $exception) {
            throw new IngestionException($exception->getMessage(), previous: $exception);
        }

        $this->sources->storeAndActivate($version, $document, $embeddedChunks);
    }
}
