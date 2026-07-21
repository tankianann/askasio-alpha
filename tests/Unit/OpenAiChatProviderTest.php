<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Providers\Chat\ChatMalformedResponseException;
use App\Providers\Chat\ChatRateLimitException;
use App\Providers\Chat\ChatConfigurationException;
use App\Providers\Chat\OpenAiChatProvider;
use App\Providers\OpenAI\OpenAiClientInterface;
use App\Providers\OpenAI\OpenAiRateLimitException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OpenAiChatProviderTest extends TestCase
{
    public function testItBuildsAResponsesApiRequestAndParsesTextAndUsage(): void
    {
        $client = new class implements OpenAiClientInterface {
            public string $path = '';
            public array $payload = [];

            public function postJson(string $path, array $payload): array
            {
                $this->path = $path;
                $this->payload = $payload;

                return [
                    'id' => 'resp_123',
                    'model' => 'gpt-test-snapshot',
                    'status' => 'completed',
                    'output' => [
                        ['type' => 'reasoning', 'summary' => []],
                        ['type' => 'message', 'content' => [
                            ['type' => 'output_text', 'text' => 'Supported answer [S1].'],
                        ]],
                    ],
                    'usage' => [
                        'input_tokens' => 150,
                        'input_tokens_details' => ['cached_tokens' => 30],
                        'output_tokens' => 45,
                        'output_tokens_details' => ['reasoning_tokens' => 8],
                        'total_tokens' => 195,
                    ],
                ];
            }
        };
        $provider = new OpenAiChatProvider($client, 'configured-model', 600, 'low');
        $result = $provider->generate('instructions', 'input', ['safety_identifier' => str_repeat('a', 64)]);

        self::assertSame('/v1/responses', $client->path);
        self::assertSame('configured-model', $client->payload['model']);
        self::assertSame(['effort' => 'low'], $client->payload['reasoning']);
        self::assertSame(600, $client->payload['max_output_tokens']);
        self::assertFalse($client->payload['store']);
        self::assertSame('disabled', $client->payload['truncation']);
        self::assertSame('Supported answer [S1].', $result->answer);
        self::assertSame('gpt-test-snapshot', $result->model);
        self::assertSame(30, $result->cachedInputTokens);
        self::assertSame(8, $result->reasoningTokens);
    }

    public function testItRejectsCompletedResponsesWithoutText(): void
    {
        $client = new class implements OpenAiClientInterface {
            public function postJson(string $path, array $payload): array
            {
                return ['status' => 'completed', 'output' => [['type' => 'reasoning']]];
            }
        };

        $this->expectException(ChatMalformedResponseException::class);
        (new OpenAiChatProvider($client, 'model', 600))->generate('instructions', 'input');
    }

    public function testItMapsSharedClientRateLimitsToChatExceptions(): void
    {
        $client = new class implements OpenAiClientInterface {
            public function postJson(string $path, array $payload): array
            {
                throw new OpenAiRateLimitException('limited');
            }
        };

        $this->expectException(ChatRateLimitException::class);
        (new OpenAiChatProvider($client, 'model', 600))->generate('instructions', 'input');
    }

    #[DataProvider('invalidOutputTokenLimits')]
    public function testItRejectsOutputTokenLimitsOutsideTheApplicationHardBounds(int $tokens): void
    {
        $client = new class implements OpenAiClientInterface {
            public function postJson(string $path, array $payload): array
            {
                throw new \LogicException('The provider must not be called for invalid configuration.');
            }
        };

        $this->expectException(ChatConfigurationException::class);
        $this->expectExceptionMessage('between 64 and 32768');

        new OpenAiChatProvider($client, 'model', $tokens);
    }

    /** @return iterable<string, array{int}> */
    public static function invalidOutputTokenLimits(): iterable
    {
        yield 'below minimum' => [63];
        yield 'above maximum' => [32_769];
    }
}
