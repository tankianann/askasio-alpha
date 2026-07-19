<?php

declare(strict_types=1);

namespace App\Domain\Api;

final readonly class ApiRequestConnectionOption
{
    public function __construct(
        public int $id,
        public string $name,
        public ?string $visiblePrefix,
        public bool $deleted,
    ) {
    }
}
