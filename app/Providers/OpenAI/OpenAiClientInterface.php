<?php

declare(strict_types=1);

namespace App\Providers\OpenAI;

interface OpenAiClientInterface
{
    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function postJson(string $path, array $payload): array;
}
