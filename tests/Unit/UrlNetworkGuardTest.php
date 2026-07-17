<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Ingestion\PermanentIngestionException;
use App\Networking\UrlNetworkGuard;
use App\Security\UrlSourceValidator;
use PHPUnit\Framework\TestCase;

final class UrlNetworkGuardTest extends TestCase
{
    public function testItResolvesAndPinsOnlyPublicAddresses(): void
    {
        $guard = new UrlNetworkGuard(
            new UrlSourceValidator(),
            static fn (string $host): array => ['2001:4860:4860::8888', '93.184.216.34'],
        );

        $resolved = $guard->resolve('https://example.com:8443/policy');

        self::assertSame('example.com', $resolved->host);
        self::assertSame(8443, $resolved->port);
        self::assertContains($resolved->pinnedIp(), $resolved->ipAddresses);
    }

    public function testItRejectsAHostnameWhenAnyResolvedAddressIsPrivate(): void
    {
        $guard = new UrlNetworkGuard(
            new UrlSourceValidator(),
            static fn (string $host): array => ['93.184.216.34', '10.0.0.7'],
        );

        $this->expectException(PermanentIngestionException::class);
        $guard->resolve('https://example.com/policy');
    }
}
