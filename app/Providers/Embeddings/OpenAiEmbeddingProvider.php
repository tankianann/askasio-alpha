<?php

declare(strict_types=1);

namespace App\Providers\Embeddings;

use App\Providers\OpenAI\OpenAiClientInterface;

final class OpenAiEmbeddingProvider implements EmbeddingProviderInterface
{
    public function __construct(
        private readonly OpenAiClientInterface $client,
        private readonly string $modelName,
        private readonly ?int $dimensions = null,
    ) {
        if (trim($this->modelName) === '') {
            throw new EmbeddingConfigurationException('OPENAI_EMBEDDING_MODEL is not configured.');
        }

        if ($this->dimensions !== null && $this->dimensions < 1) {
            throw new EmbeddingConfigurationException('Embedding dimensions must be a positive integer.');
        }
    }

    public function embed(string $text): array
    {
        return $this->embedBatch([$text])[0];
    }

    public function embedBatch(array $texts): array
    {
        if ($texts === [] || count($texts) > 2048) {
            throw new EmbeddingConfigurationException('Embedding batches must contain between 1 and 2048 inputs.');
        }

        foreach ($texts as $text) {
            if (!is_string($text) || trim($text) === '') {
                throw new EmbeddingConfigurationException('Embedding inputs must be non-empty strings.');
            }
        }

        $payload = [
            'model' => $this->modelName,
            'input' => array_values($texts),
            'encoding_format' => 'float',
        ];

        if ($this->dimensions !== null) {
            $payload['dimensions'] = $this->dimensions;
        }

        $response = $this->client->postJson('/v1/embeddings', $payload);
        $data = $response['data'] ?? null;

        if (!is_array($data) || count($data) !== count($texts)) {
            throw new MalformedEmbeddingResponseException('OpenAI returned an unexpected number of embeddings.');
        }

        $vectors = [];

        foreach ($data as $position => $item) {
            if (!is_array($item) || !isset($item['index'], $item['embedding']) || !is_array($item['embedding'])) {
                throw new MalformedEmbeddingResponseException('OpenAI returned a malformed embedding item.');
            }

            $index = filter_var($item['index'], FILTER_VALIDATE_INT);

            if ($index === false || $index < 0 || $index >= count($texts) || $item['embedding'] === []) {
                throw new MalformedEmbeddingResponseException('OpenAI returned an invalid embedding index or vector.');
            }

            $vector = [];

            foreach ($item['embedding'] as $value) {
                if (!is_int($value) && !is_float($value)) {
                    throw new MalformedEmbeddingResponseException('OpenAI returned a non-numeric embedding value.');
                }

                $number = (float) $value;

                if (!is_finite($number)) {
                    throw new MalformedEmbeddingResponseException('OpenAI returned a non-finite embedding value.');
                }

                $vector[] = $number;
            }

            $vectors[(int) $index] = $vector;
        }

        ksort($vectors);
        $vectors = array_values($vectors);

        if (count($vectors) !== count($texts)) {
            throw new MalformedEmbeddingResponseException('OpenAI returned duplicate or missing embedding indexes.');
        }

        $dimensions = count($vectors[0] ?? []);

        foreach ($vectors as $vector) {
            if (count($vector) !== $dimensions) {
                throw new MalformedEmbeddingResponseException('OpenAI returned vectors with inconsistent dimensions.');
            }
        }

        return $vectors;
    }

    public function model(): string
    {
        return $this->modelName;
    }
}
