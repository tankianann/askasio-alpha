<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Domain\ApiKeys\ApiKey;
use App\Domain\RAG\RetrievedChunk;
use App\Exceptions\HttpException;
use App\Http\JsonRequestParser;
use App\Http\Request;
use App\Http\Response;
use App\Providers\Chat\ChatAuthenticationException;
use App\Providers\Chat\ChatConfigurationException;
use App\Providers\Chat\ChatMalformedResponseException;
use App\Providers\Chat\ChatProviderException;
use App\Providers\Chat\ChatRateLimitException;
use App\Providers\Chat\ChatTimeoutException;
use App\Providers\Embeddings\EmbeddingAuthenticationException;
use App\Providers\Embeddings\EmbeddingConfigurationException;
use App\Providers\Embeddings\EmbeddingProviderException;
use App\Providers\Embeddings\EmbeddingRateLimitException;
use App\Providers\Embeddings\EmbeddingTimeoutException;
use App\Providers\Embeddings\MalformedEmbeddingResponseException;
use App\RAG\AnswerGenerator;

final class ChatController
{
    public function __construct(
        private readonly AnswerGenerator $answers,
        private readonly JsonRequestParser $json,
        private readonly int $maximumTopK,
        private readonly int $maximumQuestionCharacters,
        private readonly string $applicationSecret,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $payload = $this->json->object($request);
        $this->rejectUnknownFields($payload);
        $question = $payload['question'] ?? null;

        if (!is_string($question) || trim($question) === '') {
            throw new HttpException(422, 'The question field is required.', 'invalid_request');
        }

        $question = trim($question);

        if (mb_strlen($question) > $this->maximumQuestionCharacters) {
            throw new HttpException(
                422,
                sprintf('The question may not exceed %d characters.', $this->maximumQuestionCharacters),
                'invalid_request',
            );
        }

        if (array_key_exists('conversation_id', $payload) && $payload['conversation_id'] !== null) {
            throw new HttpException(
                422,
                'conversation_id is not supported yet and must be null.',
                'invalid_request',
            );
        }

        $topK = $payload['top_k'] ?? null;

        if ($topK !== null && (!is_int($topK) || $topK < 1 || $topK > $this->maximumTopK)) {
            throw new HttpException(
                422,
                sprintf('top_k must be an integer between 1 and %d.', $this->maximumTopK),
                'invalid_request',
            );
        }

        try {
            $result = $this->answers->generate($question, $topK, [
                'safety_identifier' => $this->safetyIdentifier($request),
            ]);
        } catch (EmbeddingConfigurationException|ChatConfigurationException) {
            throw new HttpException(503, 'The AI provider is not configured correctly.', 'provider_configuration_error');
        } catch (EmbeddingAuthenticationException|ChatAuthenticationException) {
            throw new HttpException(503, 'The AI provider rejected the configured credentials.', 'provider_authentication_error');
        } catch (EmbeddingRateLimitException|ChatRateLimitException) {
            throw new HttpException(503, 'The AI provider is temporarily rate limited.', 'provider_rate_limited');
        } catch (EmbeddingTimeoutException|ChatTimeoutException) {
            throw new HttpException(504, 'The AI provider request timed out.', 'provider_timeout');
        } catch (MalformedEmbeddingResponseException|ChatMalformedResponseException) {
            throw new HttpException(502, 'The AI provider returned an invalid response.', 'provider_invalid_response');
        } catch (EmbeddingProviderException|ChatProviderException) {
            throw new HttpException(502, 'The AI provider request failed.', 'provider_error');
        }

        $requestId = $request->attribute('request_id');

        return Response::json([
            'answer' => $result->answer,
            'citations' => array_map(
                fn (RetrievedChunk $chunk, int $index): array => $this->citation($chunk, $index),
                $result->chunks,
                array_keys($result->chunks),
            ),
            'usage' => $result->usage,
            'request_id' => is_string($requestId) ? $requestId : null,
        ])->withHeader('Cache-Control', 'no-store');
    }

    /** @param array<string, mixed> $payload */
    private function rejectUnknownFields(array $payload): void
    {
        $allowed = ['question', 'conversation_id', 'top_k'];

        foreach (array_keys($payload) as $key) {
            if (!is_string($key) || !in_array($key, $allowed, true)) {
                throw new HttpException(422, 'An unsupported request field was supplied.', 'invalid_request');
            }
        }
    }

    private function safetyIdentifier(Request $request): string
    {
        $apiKey = $request->attribute('api_key');
        $identifier = $apiKey instanceof ApiKey ? 'api-key:' . $apiKey->id : 'ip:' . $request->clientIp();

        return substr(hash_hmac('sha256', $identifier, $this->applicationSecret), 0, 64);
    }

    /** @return array<string, mixed> */
    private function citation(RetrievedChunk $chunk, int $index): array
    {
        $excerpt = preg_replace('/\s+/u', ' ', trim($chunk->content)) ?? trim($chunk->content);

        if (mb_strlen($excerpt) > 280) {
            $excerpt = rtrim(mb_substr($excerpt, 0, 279)) . '…';
        }

        return [
            'reference' => 'S' . ($index + 1),
            'source_id' => $chunk->sourceId,
            'source_version_id' => $chunk->sourceVersionId,
            'chunk_id' => $chunk->chunkId,
            'source_name' => $chunk->sourceName,
            'source_type' => $chunk->sourceType,
            'source_url' => $chunk->sourceUrl,
            'page' => $chunk->page(),
            'heading' => $chunk->heading(),
            'excerpt' => $excerpt,
        ];
    }
}
