<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Providers\Embeddings\MalformedEmbeddingResponseException;
use App\Providers\Embeddings\OpenAiEmbeddingProvider;
use App\Providers\OpenAI\OpenAiClientInterface;
use PHPUnit\Framework\TestCase;

final class OpenAiEmbeddingProviderTest extends TestCase
{
    public function testItBuildsBatchRequestAndOrdersVectorsByResponseIndex(): void
    {
        $client = new class implements OpenAiClientInterface {
            public array $payload = [];

            public function postJson(string $path, array $payload): array
            {
                \PHPUnit\Framework\Assert::assertSame('/v1/embeddings', $path);
                $this->payload = $payload;

                return ['data' => [
                    ['index' => 1, 'embedding' => [0, 1]],
                    ['index' => 0, 'embedding' => [1, 0]],
                ]];
            }
        };
        $provider = new OpenAiEmbeddingProvider($client, 'configured-model', 2);

        self::assertSame([[1.0, 0.0], [0.0, 1.0]], $provider->embedBatch(['one', 'two']));
        self::assertSame([
            'model' => 'configured-model',
            'input' => ['one', 'two'],
            'encoding_format' => 'float',
            'dimensions' => 2,
        ], $client->payload);
    }

    public function testItRejectsMalformedVectors(): void
    {
        $client = new class implements OpenAiClientInterface {
            public function postJson(string $path, array $payload): array
            {
                return ['data' => [['index' => 0, 'embedding' => ['not-a-number']]]];
            }
        };

        $this->expectException(MalformedEmbeddingResponseException::class);
        (new OpenAiEmbeddingProvider($client, 'configured-model'))->embed('text');
    }
}
