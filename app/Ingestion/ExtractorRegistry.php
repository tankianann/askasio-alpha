<?php

declare(strict_types=1);

namespace App\Ingestion;

use App\Domain\Sources\SourceVersion;

final class ExtractorRegistry
{
    /** @param list<SourceExtractorInterface> $extractors */
    public function __construct(private readonly array $extractors)
    {
    }

    public function forVersion(SourceVersion $version): SourceExtractorInterface
    {
        foreach ($this->extractors as $extractor) {
            if ($extractor->supports($version)) {
                return $extractor;
            }
        }

        throw new PermanentIngestionException('No extractor supports this source version.');
    }
}
