<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Providers\Embeddings\EmbeddingProviderInterface;
use Throwable;

final class FakeEmbeddingProvider implements EmbeddingProviderInterface
{
    /** @var list<string> */
    public array $inputs = [];

    /**
     * @param list<list<float>> $vectors
     */
    public function __construct(
        private readonly array $vectors = [[1.0, 0.0]],
        private readonly string $modelName = 'fake-embedding-model',
        private readonly ?Throwable $failure = null,
    ) {
    }

    public function embed(string $text): array
    {
        return $this->embedBatch([$text])[0];
    }

    public function embedBatch(array $texts): array
    {
        $this->inputs = [...$this->inputs, ...$texts];

        if ($this->failure instanceof Throwable) {
            throw $this->failure;
        }

        if (count($this->vectors) === count($texts)) {
            return $this->vectors;
        }

        return array_fill(0, count($texts), $this->vectors[0]);
    }

    public function model(): string
    {
        return $this->modelName;
    }
}
