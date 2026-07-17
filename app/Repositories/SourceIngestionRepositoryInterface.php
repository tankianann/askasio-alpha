<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Domain\Ingestion\Chunk;
use App\Domain\Ingestion\ExtractedDocument;
use App\Domain\Sources\SourceVersion;

interface SourceIngestionRepositoryInterface
{
    public function findVersion(int $id): ?SourceVersion;

    /** @param list<Chunk> $chunks */
    public function storeAndActivate(SourceVersion $version, ExtractedDocument $document, array $chunks): bool;
}
