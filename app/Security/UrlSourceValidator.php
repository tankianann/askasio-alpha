<?php

declare(strict_types=1);

namespace App\Security;

use App\Exceptions\ValidationException;

final class UrlSourceValidator
{
    public function validate(string $url): string
    {
        $url = trim($url);

        if ($url === '' || strlen($url) > 2048 || filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new ValidationException('Enter a valid URL no longer than 2,048 characters.');
        }

        $parts = parse_url($url);

        if (!is_array($parts)) {
            throw new ValidationException('Enter a valid URL.');
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));

        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new ValidationException('Only HTTP and HTTPS URLs are supported.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new ValidationException('URLs containing embedded credentials are not allowed.');
        }

        $host = strtolower(rtrim(trim((string) ($parts['host'] ?? ''), '[]'), '.'));

        if ($host === '') {
            throw new ValidationException('The URL must include a hostname.');
        }

        if ($host === 'localhost'
            || str_ends_with($host, '.localhost')
            || $host === 'metadata.google.internal'
            || $host === 'metadata') {
            throw new ValidationException('Local and metadata service URLs are not allowed.');
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $publicIp = filter_var(
                $host,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
            );

            if ($publicIp === false) {
                throw new ValidationException('Private, reserved, loopback, and link-local addresses are not allowed.');
            }
        } elseif (preg_match('/^[0-9.]+$/', $host) === 1
            || filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
            throw new ValidationException('The URL hostname is invalid.');
        }

        return $url;
    }
}
