<?php

declare(strict_types=1);

namespace App\Networking;

use App\Ingestion\IngestionException;
use App\Ingestion\PermanentIngestionException;
use App\Security\UrlSourceValidator;
use Closure;

final class UrlNetworkGuard
{
    private readonly Closure $resolver;

    /** @param null|callable(string): list<string> $resolver */
    public function __construct(
        private readonly UrlSourceValidator $urls,
        ?callable $resolver = null,
    ) {
        $this->resolver = $resolver === null
            ? $this->resolveDns(...)
            : Closure::fromCallable($resolver);
    }

    public function resolve(string $url): ResolvedUrl
    {
        try {
            $url = $this->urls->validate($url);
        } catch (\Throwable $exception) {
            throw new PermanentIngestionException($exception->getMessage(), previous: $exception);
        }

        $parts = parse_url($url);

        if (!is_array($parts)) {
            throw new PermanentIngestionException('The source URL could not be parsed.');
        }

        $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $port = isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80);

        if ($port < 1 || $port > 65535) {
            throw new PermanentIngestionException('The source URL port is invalid.');
        }

        $addresses = ($this->resolver)($host);

        if ($addresses === []) {
            throw new IngestionException('The source hostname did not resolve to an IP address.');
        }

        $validated = [];

        foreach (array_unique($addresses) as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP) === false
                || filter_var(
                    $address,
                    FILTER_VALIDATE_IP,
                    FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
                ) === false) {
                throw new PermanentIngestionException('The source hostname resolved to a private or reserved address.');
            }

            $validated[] = $address;
        }

        sort($validated, SORT_STRING);

        return new ResolvedUrl($url, $host, $port, $validated);
    }

    /** @return list<string> */
    private function resolveDns(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $records = dns_get_record($host, DNS_A | DNS_AAAA);

        if ($records === false) {
            return [];
        }

        $addresses = [];

        foreach ($records as $record) {
            if (isset($record['ip'])) {
                $addresses[] = (string) $record['ip'];
            } elseif (isset($record['ipv6'])) {
                $addresses[] = (string) $record['ipv6'];
            }
        }

        return $addresses;
    }
}
