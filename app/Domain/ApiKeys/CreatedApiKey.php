<?php

declare(strict_types=1);

namespace App\Domain\ApiKeys;

final class CreatedApiKey
{
    public function __construct(
        public readonly ApiKey $apiKey,
        public readonly string $plaintextKey,
    ) {
    }
}
