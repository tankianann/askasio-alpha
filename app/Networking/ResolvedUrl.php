<?php

declare(strict_types=1);

namespace App\Networking;

final class ResolvedUrl
{
    /** @param list<string> $ipAddresses */
    public function __construct(
        public readonly string $url,
        public readonly string $host,
        public readonly int $port,
        public readonly array $ipAddresses,
    ) {
    }

    public function pinnedIp(): string
    {
        return $this->ipAddresses[0];
    }
}
