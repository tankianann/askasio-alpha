<?php

declare(strict_types=1);

namespace App\Providers\OpenAI;

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
            throw new OpenAiConfigurationException('OPENAI_API_KEY is not configured.');
        }

        if (filter_var($this->baseUrl, FILTER_VALIDATE_URL) === false
            || parse_url($this->baseUrl, PHP_URL_SCHEME) !== 'https') {
            throw new OpenAiConfigurationException('OPENAI_BASE_URL must be a valid HTTPS URL.');
        }

        if ($this->connectTimeoutSeconds < 1 || $this->requestTimeoutSeconds < 1) {
            throw new OpenAiConfigurationException('OpenAI timeouts must be positive integers.');
        }

        if ($this->maximumRetries < 0 || $this->maximumRetries > 10) {
            throw new OpenAiConfigurationException('OpenAI maximum retries must be between 0 and 10.');
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

                    throw new OpenAiTimeoutException('The OpenAI request timed out.');
                }

                throw new OpenAiClientException('The OpenAI request could not be completed.');
            }

            if ($status >= 200 && $status < 300) {
                try {
                    $decoded = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
                } catch (JsonException $exception) {
                    throw new OpenAiMalformedResponseException('OpenAI returned malformed JSON.', previous: $exception);
                }

                if (!is_array($decoded)) {
                    throw new OpenAiMalformedResponseException('OpenAI returned an unexpected response.');
                }

                return $decoded;
            }

            if ($status === 401 || $status === 403) {
                throw new OpenAiAuthenticationException('OpenAI rejected the configured credentials.');
            }

            if ($status === 400 || $status === 404) {
                throw new OpenAiInvalidRequestException('OpenAI rejected the configured request or model.');
            }

            if ($status === 429) {
                if ($attempt <= $this->maximumRetries) {
                    $this->backoff($attempt);
                    continue;
                }

                throw new OpenAiRateLimitException('OpenAI rate-limited the request.');
            }

            if ($status >= 500 && $status <= 599 && $attempt <= $this->maximumRetries) {
                $this->backoff($attempt);
                continue;
            }

            throw new OpenAiClientException(sprintf('OpenAI returned HTTP %d.', $status));
        }
    }

    /** @return array{int, string, string, int} */
    private function request(string $url, string $body): array
    {
        $handle = curl_init($url);

        if (!$handle instanceof CurlHandle) {
            throw new OpenAiClientException('The OpenAI HTTP client could not be initialized.');
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
