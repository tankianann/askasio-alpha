<?php

declare(strict_types=1);

namespace App\Ingestion;

use App\Domain\Ingestion\ExtractedDocument;

interface ChunkerInterface
{
    /** @return list<\App\Domain\Ingestion\Chunk> */
    public function chunk(ExtractedDocument $document): array;
}
