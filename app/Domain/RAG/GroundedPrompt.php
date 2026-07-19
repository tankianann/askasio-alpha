<?php

declare(strict_types=1);

namespace App\Domain\RAG;

final class GroundedPrompt
{
    public function __construct(
        public readonly string $instructions,
        public readonly string $input,
    ) {
    }
}
