<?php

declare(strict_types=1);

namespace App\RAG;

use App\Domain\RAG\ChatExecutionError;
use App\Exceptions\ProviderQuotaExceededException;
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
use Throwable;

final class ChatExecutionErrorMapper
{
    public function map(Throwable $exception): ?ChatExecutionError
    {
        return match (true) {
            $exception instanceof ProviderQuotaExceededException => new ChatExecutionError(
                429, 'quota_exceeded', 'The provider token quota has been exhausted.',
            ),
            $exception instanceof EmbeddingConfigurationException,
            $exception instanceof ChatConfigurationException => new ChatExecutionError(
                503, 'provider_configuration_error', 'The AI provider is not configured correctly.',
            ),
            $exception instanceof EmbeddingAuthenticationException,
            $exception instanceof ChatAuthenticationException => new ChatExecutionError(
                503, 'provider_authentication_error', 'The AI provider rejected the configured credentials.',
            ),
            $exception instanceof EmbeddingRateLimitException,
            $exception instanceof ChatRateLimitException => new ChatExecutionError(
                503, 'provider_rate_limited', 'The AI provider is temporarily rate limited.',
            ),
            $exception instanceof EmbeddingTimeoutException,
            $exception instanceof ChatTimeoutException => new ChatExecutionError(
                504, 'provider_timeout', 'The AI provider request timed out.',
            ),
            $exception instanceof MalformedEmbeddingResponseException,
            $exception instanceof ChatMalformedResponseException => new ChatExecutionError(
                502, 'provider_invalid_response', 'The AI provider returned an invalid response.',
            ),
            $exception instanceof EmbeddingProviderException,
            $exception instanceof ChatProviderException => new ChatExecutionError(
                502, 'provider_error', 'The AI provider request failed.',
            ),
            default => null,
        };
    }
}
