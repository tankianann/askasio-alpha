<?php

declare(strict_types=1);

namespace App\Providers\Chat;

interface ChatProviderInterface
{
    /** @param array<string, mixed> $options */
    public function generate(string $instructions, string $input, array $options = []): ChatGeneration;
}
