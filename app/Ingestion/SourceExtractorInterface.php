<?php

declare(strict_types=1);

namespace App\Ingestion;

use App\Domain\Ingestion\ExtractedDocument;
use App\Domain\Sources\SourceVersion;

interface SourceExtractorInterface
{
    public function supports(SourceVersion $version): bool;

    public function extract(SourceVersion $version): ExtractedDocument;
}
