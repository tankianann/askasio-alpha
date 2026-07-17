<?php

declare(strict_types=1);

namespace App\Providers\Embeddings;

interface EmbeddingProviderInterface
{
    /** @return list<float> */
    public function embed(string $text): array;

    /**
     * @param list<string> $texts
     * @return list<list<float>>
     */
    public function embedBatch(array $texts): array;

    public function model(): string;
}
