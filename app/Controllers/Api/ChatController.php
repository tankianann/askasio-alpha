<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Domain\ApiKeys\ApiKey;
use App\Domain\RAG\RetrievedChunk;
use App\Exceptions\HttpException;
use App\Exceptions\ProviderQuotaExceededException;
use App\Http\JsonRequestParser;
use App\Http\Request;
use App\Http\Response;
use App\RAG\AnswerGenerator;
use App\RAG\ChatExecutionErrorMapper;
use App\RAG\CitationProjector;
use App\Ingestion\Chunking\HeuristicTokenEstimator;
use App\Services\ProviderQuota\ProviderQuotaService;
use App\Services\ProviderQuota\ProviderUsageAccumulator;
use Throwable;

final class ChatController
{
    public function __construct(
        private readonly AnswerGenerator $answers,
        private readonly JsonRequestParser $json,
        private readonly int $maximumTopK,
        private readonly int $maximumQuestionCharacters,
        private readonly string $applicationSecret,
        private readonly ?ProviderQuotaService $quotas = null,
        private readonly ?ProviderUsageAccumulator $providerUsage = null,
        private readonly int $maximumContextTokens = 0,
        private readonly int $maximumOutputTokens = 0,
        private readonly CitationProjector $citations = new CitationProjector(),
        private readonly ChatExecutionErrorMapper $errors = new ChatExecutionErrorMapper(),
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

        $reservation = null;

        if ($this->quotas instanceof ProviderQuotaService) {
            try {
                $reservation = $this->quotas->reserveChat(
                    $this->apiKeyId($request),
                    $question,
                    $this->maximumContextTokens,
                    $this->maximumOutputTokens,
                );
            } catch (ProviderQuotaExceededException) {
                throw new HttpException(429, 'The provider token quota has been exhausted.', 'quota_exceeded');
            }
        }

        try {
            $result = $this->answers->generate($question, $topK, [
                'safety_identifier' => $this->safetyIdentifier($request),
            ]);
        } catch (Throwable $exception) {
            if ($reservation !== null) {
                $this->quotas?->reconcile($reservation, $reservation->reservedTokens, true);
            }

            $mapped = $this->errors->map($exception);

            if ($mapped !== null) {
                throw new HttpException($mapped->statusCode, $mapped->safeMessage, $mapped->errorCode);
            }

            throw $exception;
        }

        $embeddingTokens = $this->providerUsage?->embeddingTokens() ?? 0;
        $embeddingTokens = $embeddingTokens > 0
            ? $embeddingTokens
            : (new HeuristicTokenEstimator())->estimate($question);
        $providerTotalTokens = ($result->usage['total_tokens'] ?? 0) + $embeddingTokens;
        $quotaUsage = $reservation !== null
            ? $this->quotas?->reconcile($reservation, $providerTotalTokens) ?? []
            : [];

        $requestId = $request->attribute('request_id');

        return Response::json([
            'answer' => $result->answer,
            'citations' => array_map(
                fn (RetrievedChunk $chunk, int $index): array => $this->citations->legacy($chunk, $index),
                $result->chunks,
                array_keys($result->chunks),
            ),
            'usage' => [
                ...$result->usage,
                'embedding_tokens' => $embeddingTokens,
                'provider_total_tokens' => $providerTotalTokens,
                ...$quotaUsage,
            ],
            'request_id' => is_string($requestId) ? $requestId : null,
        ])->withHeader('Cache-Control', 'no-store');
    }

    private function apiKeyId(Request $request): int
    {
        $apiKey = $request->attribute('api_key');

        if (!$apiKey instanceof ApiKey) {
            throw new \LogicException('Authenticated API key is missing from the request.');
        }

        return $apiKey->id;
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

}
