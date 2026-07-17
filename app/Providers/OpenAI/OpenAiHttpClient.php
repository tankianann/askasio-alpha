<?php

declare(strict_types=1);

namespace App\Providers\OpenAI;

use App\Providers\Embeddings\EmbeddingAuthenticationException;
use App\Providers\Embeddings\EmbeddingConfigurationException;
use App\Providers\Embeddings\EmbeddingProviderException;
use App\Providers\Embeddings\EmbeddingRateLimitException;
use App\Providers\Embeddings\EmbeddingTimeoutException;
use App\Providers\Embeddings\MalformedEmbeddingResponseException;
use CurlHandle;
use JsonException;

final class OpenAiHttpClient implements OpenAiClientInterface
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $baseUrl,
        private readonly int $connectTimeoutSeconds,
        private readonly int $requestTimeoutSeconds,
        private readonly int $maximumRetries,
    ) {
        if (trim($this->apiKey) === '') {
            throw new EmbeddingConfigurationException('OPENAI_API_KEY is not configured.');
        }

        if (filter_var($this->baseUrl, FILTER_VALIDATE_URL) === false
            || parse_url($this->baseUrl, PHP_URL_SCHEME) !== 'https') {
            throw new EmbeddingConfigurationException('OPENAI_BASE_URL must be a valid HTTPS URL.');
        }

        if ($this->connectTimeoutSeconds < 1 || $this->requestTimeoutSeconds < 1) {
            throw new EmbeddingConfigurationException('OpenAI timeouts must be positive integers.');
        }

        if ($this->maximumRetries < 0 || $this->maximumRetries > 10) {
            throw new EmbeddingConfigurationException('OpenAI maximum retries must be between 0 and 10.');
        }
    }

    public function postJson(string $path, array $payload): array
    {
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $url = rtrim($this->baseUrl, '/') . '/' . ltrim($path, '/');
        $attempt = 0;

        while (true) {
            $attempt++;
            [$status, $body, $curlError, $curlErrorNumber] = $this->request($url, $encoded);

            if ($curlErrorNumber !== 0) {
                if ($curlErrorNumber === CURLE_OPERATION_TIMEDOUT) {
                    if ($attempt <= $this->maximumRetries) {
                        $this->backoff($attempt);
                        continue;
                    }

                    throw new EmbeddingTimeoutException('The OpenAI request timed out.');
                }

                throw new EmbeddingProviderException('The OpenAI request could not be completed.');
            }

            if ($status >= 200 && $status < 300) {
                try {
                    $decoded = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
                } catch (JsonException $exception) {
                    throw new MalformedEmbeddingResponseException('OpenAI returned malformed JSON.', previous: $exception);
                }

                if (!is_array($decoded)) {
                    throw new MalformedEmbeddingResponseException('OpenAI returned an unexpected response.');
                }

                return $decoded;
            }

            if ($status === 401 || $status === 403) {
                throw new EmbeddingAuthenticationException('OpenAI rejected the configured credentials.');
            }

            if ($status === 429) {
                if ($attempt <= $this->maximumRetries) {
                    $this->backoff($attempt);
                    continue;
                }

                throw new EmbeddingRateLimitException('OpenAI rate-limited the embedding request.');
            }

            if ($status >= 500 && $status <= 599 && $attempt <= $this->maximumRetries) {
                $this->backoff($attempt);
                continue;
            }

            throw new EmbeddingProviderException(sprintf('OpenAI returned HTTP %d.', $status));
        }
    }

    /** @return array{int, string, string, int} */
    private function request(string $url, string $body): array
    {
        $handle = curl_init($url);

        if (!$handle instanceof CurlHandle) {
            throw new EmbeddingProviderException('The OpenAI HTTP client could not be initialized.');
        }

        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->apiKey,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeoutSeconds,
            CURLOPT_TIMEOUT => $this->requestTimeoutSeconds,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        ]);

        $response = curl_exec($handle);
        $errorNumber = curl_errno($handle);
        $error = curl_error($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        return [$status, is_string($response) ? $response : '', $error, $errorNumber];
    }

    private function backoff(int $attempt): void
    {
        $baseMilliseconds = min(4_000, 250 * (2 ** max(0, $attempt - 1)));
        $jitterMilliseconds = random_int(0, 250);
        usleep(($baseMilliseconds + $jitterMilliseconds) * 1000);
    }
}
