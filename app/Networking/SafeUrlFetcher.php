<?php

declare(strict_types=1);

namespace App\Networking;

use App\Domain\Ingestion\FetchedPage;
use App\Ingestion\IngestionException;
use App\Ingestion\PermanentIngestionException;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;

final class SafeUrlFetcher implements UrlFetcherInterface
{
    public function __construct(
        private readonly UrlNetworkGuard $guard,
        private readonly int $connectionTimeoutSeconds,
        private readonly int $requestTimeoutSeconds,
        private readonly int $maximumRedirects,
        private readonly int $maximumResponseBytes,
        private readonly string $userAgent,
    ) {
    }

    public function fetch(string $url): FetchedPage
    {
        $visited = [];

        for ($redirect = 0; $redirect <= $this->maximumRedirects; $redirect++) {
            $resolved = $this->guard->resolve($url);
            $key = strtolower($resolved->url);

            if (isset($visited[$key])) {
                throw new PermanentIngestionException('The source URL entered a redirect loop.');
            }

            $visited[$key] = true;
            $response = $this->request($resolved);

            if (!in_array($response->statusCode, [301, 302, 303, 307, 308], true)) {
                return $response;
            }

            if ($redirect === $this->maximumRedirects) {
                throw new PermanentIngestionException('The source URL exceeded the redirect limit.');
            }

            $location = $response->headers['location'] ?? null;

            if (!is_string($location) || trim($location) === '') {
                throw new PermanentIngestionException('The source returned a redirect without a destination.');
            }

            try {
                $url = (string) UriResolver::resolve(new Uri($resolved->url), new Uri(trim($location)));
            } catch (\Throwable $exception) {
                throw new PermanentIngestionException('The source returned an invalid redirect destination.', previous: $exception);
            }
        }

        throw new PermanentIngestionException('The source URL exceeded the redirect limit.');
    }

    private function request(ResolvedUrl $resolved): FetchedPage
    {
        $handle = curl_init($resolved->url);

        if ($handle === false) {
            throw new IngestionException('The URL request could not be initialized.');
        }

        $body = '';
        $headers = [];
        $tooLarge = false;
        $ip = $resolved->pinnedIp();
        $pinnedAddress = str_contains($ip, ':') ? '[' . $ip . ']' : $ip;
        curl_setopt_array($handle, [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_CONNECTTIMEOUT => $this->connectionTimeoutSeconds,
            CURLOPT_TIMEOUT => $this->requestTimeoutSeconds,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_USERAGENT => $this->userAgent,
            CURLOPT_HTTPHEADER => ['Accept: text/html, application/xhtml+xml;q=0.9'],
            CURLOPT_ENCODING => '',
            CURLOPT_NOSIGNAL => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROXY => '',
            CURLOPT_RESOLVE => [sprintf('%s:%d:%s', $resolved->host, $resolved->port, $pinnedAddress)],
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$headers): int {
                $length = strlen($line);

                if (preg_match('/^HTTP\//i', $line) === 1) {
                    $headers = [];

                    return $length;
                }

                $separator = strpos($line, ':');

                if ($separator !== false) {
                    $name = strtolower(trim(substr($line, 0, $separator)));
                    $headers[$name] = trim(substr($line, $separator + 1));
                }

                return $length;
            },
            CURLOPT_WRITEFUNCTION => function ($curl, string $data) use (&$body, &$tooLarge): int {
                if (strlen($body) + strlen($data) > $this->maximumResponseBytes) {
                    $tooLarge = true;

                    return 0;
                }

                $body .= $data;

                return strlen($data);
            },
        ]);

        $success = curl_exec($handle);
        $statusCode = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $errorNumber = curl_errno($handle);
        curl_close($handle);

        if ($tooLarge) {
            throw new PermanentIngestionException('The source response exceeded the configured size limit.');
        }

        if ($success === false) {
            $message = in_array($errorNumber, [CURLE_OPERATION_TIMEDOUT, CURLE_COULDNT_CONNECT, CURLE_COULDNT_RESOLVE_HOST], true)
                ? 'The source could not be reached before the timeout.'
                : 'The source request failed.';
            throw new IngestionException($message);
        }

        if ($statusCode === 429 || $statusCode >= 500) {
            throw new IngestionException(sprintf('The source returned a temporary HTTP %d response.', $statusCode));
        }

        if ($statusCode < 200 || $statusCode >= 400) {
            throw new PermanentIngestionException(sprintf('The source returned HTTP %d.', $statusCode));
        }

        if (in_array($statusCode, [301, 302, 303, 307, 308], true)) {
            return new FetchedPage(
                $resolved->url,
                $body,
                (string) ($headers['content-type'] ?? ''),
                $statusCode,
                $headers,
            );
        }

        $contentTypeHeader = $headers['content-type'] ?? '';
        $contentType = strtolower(trim(explode(';', $contentTypeHeader, 2)[0]));

        if (!in_array($contentType, ['text/html', 'application/xhtml+xml'], true)) {
            throw new PermanentIngestionException('The source did not return supported HTML content.');
        }

        return new FetchedPage($resolved->url, $body, $contentTypeHeader, $statusCode, $headers);
    }
}
