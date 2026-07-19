<?php

declare(strict_types=1);

namespace App\Services\Sources;

final class StagedSourceDirectory
{
    public function __construct(
        public readonly string $originalPath,
        public readonly string $stagedPath,
    ) {
    }
}
