<?php

declare(strict_types=1);

namespace App\Providers\Chat;

use App\Providers\OpenAI\OpenAiAuthenticationException;
use App\Providers\OpenAI\OpenAiClientException;
use App\Providers\OpenAI\OpenAiClientInterface;
use App\Providers\OpenAI\OpenAiInvalidRequestException;
use App\Providers\OpenAI\OpenAiMalformedResponseException;
use App\Providers\OpenAI\OpenAiRateLimitException;
use App\Providers\OpenAI\OpenAiTimeoutException;

final class OpenAiChatProvider implements ChatProviderInterface
{
    private const REASONING_EFFORTS = ['none', 'minimal', 'low', 'medium', 'high', 'xhigh', 'max'];

    public function __construct(
        private readonly OpenAiClientInterface $client,
        private readonly string $modelName,
        private readonly int $maximumOutputTokens,
        private readonly ?string $reasoningEffort = null,
    ) {
        if (trim($this->modelName) === '') {
            throw new ChatConfigurationException('OPENAI_CHAT_MODEL is not configured.');
        }

        if ($this->maximumOutputTokens < 64 || $this->maximumOutputTokens > 32768) {
            throw new ChatConfigurationException('OpenAI chat output tokens must be between 64 and 32768.');
        }

        if ($this->reasoningEffort !== null && !in_array($this->reasoningEffort, self::REASONING_EFFORTS, true)) {
            throw new ChatConfigurationException('OPENAI_CHAT_REASONING_EFFORT is not supported.');
        }
    }

    public function generate(string $instructions, string $input, array $options = []): ChatGeneration
    {
        if (trim($instructions) === '' || trim($input) === '') {
            throw new ChatConfigurationException('Chat instructions and input must not be empty.');
        }

        $payload = [
            'model' => $this->modelName,
            'instructions' => $instructions,
            'input' => $input,
            'max_output_tokens' => $this->maximumOutputTokens,
            'store' => false,
            'truncation' => 'disabled',
        ];

        if ($this->reasoningEffort !== null) {
            $payload['reasoning'] = ['effort' => $this->reasoningEffort];
        }

        $safetyIdentifier = $options['safety_identifier'] ?? null;

        if (is_string($safetyIdentifier) && $safetyIdentifier !== '' && strlen($safetyIdentifier) <= 64) {
            $payload['safety_identifier'] = $safetyIdentifier;
        }

        try {
            $response = $this->client->postJson('/v1/responses', $payload);
        } catch (OpenAiAuthenticationException $exception) {
            throw new ChatAuthenticationException($exception->getMessage(), previous: $exception);
        } catch (OpenAiInvalidRequestException $exception) {
            throw new ChatConfigurationException($exception->getMessage(), previous: $exception);
        } catch (OpenAiRateLimitException $exception) {
            throw new ChatRateLimitException($exception->getMessage(), previous: $exception);
        } catch (OpenAiTimeoutException $exception) {
            throw new ChatTimeoutException($exception->getMessage(), previous: $exception);
        } catch (OpenAiMalformedResponseException $exception) {
            throw new ChatMalformedResponseException($exception->getMessage(), previous: $exception);
        } catch (OpenAiClientException $exception) {
            throw new ChatProviderException($exception->getMessage(), previous: $exception);
        }

        if (($response['status'] ?? null) !== 'completed') {
            throw new ChatProviderException('OpenAI did not complete the chat response.');
        }

        $answer = $this->extractText($response['output'] ?? null);

        if ($answer === '') {
            throw new ChatMalformedResponseException('OpenAI returned no answer text.');
        }

        $usage = is_array($response['usage'] ?? null) ? $response['usage'] : [];
        $inputDetails = is_array($usage['input_tokens_details'] ?? null) ? $usage['input_tokens_details'] : [];
        $outputDetails = is_array($usage['output_tokens_details'] ?? null) ? $usage['output_tokens_details'] : [];
        $model = is_string($response['model'] ?? null) && $response['model'] !== ''
            ? $response['model']
            : $this->modelName;
        $responseId = is_string($response['id'] ?? null) && $response['id'] !== '' ? $response['id'] : null;

        return new ChatGeneration(
            $answer,
            $responseId,
            $model,
            $this->nonNegativeInteger($usage['input_tokens'] ?? 0),
            $this->nonNegativeInteger($inputDetails['cached_tokens'] ?? 0),
            $this->nonNegativeInteger($usage['output_tokens'] ?? 0),
            $this->nonNegativeInteger($outputDetails['reasoning_tokens'] ?? 0),
            $this->nonNegativeInteger($usage['total_tokens'] ?? 0),
        );
    }

    private function extractText(mixed $output): string
    {
        if (!is_array($output)) {
            throw new ChatMalformedResponseException('OpenAI returned malformed output.');
        }

        $parts = [];

        foreach ($output as $item) {
            if (!is_array($item) || ($item['type'] ?? null) !== 'message' || !is_array($item['content'] ?? null)) {
                continue;
            }

            foreach ($item['content'] as $content) {
                if (!is_array($content)) {
                    continue;
                }

                $type = $content['type'] ?? null;
                $text = $type === 'output_text' ? ($content['text'] ?? null) : ($type === 'refusal' ? ($content['refusal'] ?? null) : null);

                if (is_string($text) && trim($text) !== '') {
                    $parts[] = trim($text);
                }
            }
        }

        return trim(implode("\n\n", $parts));
    }

    private function nonNegativeInteger(mixed $value): int
    {
        return is_int($value) && $value >= 0 ? $value : 0;
    }
}
