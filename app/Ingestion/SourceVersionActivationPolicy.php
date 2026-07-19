<?php

declare(strict_types=1);

namespace App\Ingestion;

final class SourceVersionActivationPolicy
{
    public function shouldActivate(int $candidateVersionNumber, ?int $activeVersionNumber): bool
    {
        return $activeVersionNumber === null || $candidateVersionNumber >= $activeVersionNumber;
    }
}
