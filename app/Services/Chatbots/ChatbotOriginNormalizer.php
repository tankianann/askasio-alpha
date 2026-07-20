<?php

declare(strict_types=1);

namespace App\Services\Chatbots;

use App\Exceptions\ValidationException;

final class ChatbotOriginNormalizer
{
    /** @param list<string> $origins @return list<string> */
    public function normalizeMany(array $origins): array
    {
        if (count($origins) > 50) {
            throw new ValidationException('A chatbot may allow at most 50 origins.');
        }

        $normalized = [];

        foreach ($origins as $origin) {
            $normalized[] = $this->normalize($origin);
        }

        $normalized = array_values(array_unique($normalized));
        sort($normalized, SORT_STRING);

        return $normalized;
    }

    public function normalize(string $origin): string
    {
        $origin = trim($origin);

        if ($origin === '' || strlen($origin) > 255) {
            throw new ValidationException('Allowed origins must contain between 1 and 255 ASCII characters.');
        }

        $parts = parse_url($origin);

        if (!is_array($parts)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || (($parts['path'] ?? '') !== '' && ($parts['path'] ?? '') !== '/')) {
            throw new ValidationException('Allowed origins must contain only scheme, host, and optional port.');
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));
        $host = trim($host, '[]');

        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw new ValidationException('Allowed origins must use HTTP or HTTPS and include a host.');
        }

        if (function_exists('idn_to_ascii') && preg_match('/[^\x20-\x7E]/', $host) === 1) {
            $ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);

            if (!is_string($ascii) || $ascii === '') {
                throw new ValidationException('Allowed origin host could not be normalized.');
            }

            $host = strtolower($ascii);
        }

        if (preg_match('/[^A-Za-z0-9.:-]/', $host) === 1) {
            throw new ValidationException('Allowed origin host contains unsupported characters.');
        }

        $isLocal = $host === 'localhost' || $host === '127.0.0.1' || $host === '::1';

        if ($scheme !== 'https' && !$isLocal) {
            throw new ValidationException('Non-local allowed origins must use HTTPS.');
        }

        $port = $parts['port'] ?? null;

        if ($port !== null && (!is_int($port) || $port < 1 || $port > 65_535)) {
            throw new ValidationException('Allowed origin port is invalid.');
        }

        $defaultPort = ($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80);
        $hostForOrigin = str_contains($host, ':') ? '[' . $host . ']' : $host;
        $normalized = $scheme . '://' . $hostForOrigin;

        if ($port !== null && !$defaultPort) {
            $normalized .= ':' . $port;
        }

        if (strlen($normalized) > 255) {
            throw new ValidationException('Normalized allowed origin is too long.');
        }

        return $normalized;
    }
}
