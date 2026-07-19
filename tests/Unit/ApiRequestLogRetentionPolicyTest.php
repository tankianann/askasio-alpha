<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Api\ApiRequestLogRetentionPolicy;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ApiRequestLogRetentionPolicyTest extends TestCase
{
    /** @return iterable<string, array{int}> */
    public static function supportedPeriods(): iterable
    {
        yield 'keep forever' => [0];
        yield '30 days' => [30];
        yield '90 days' => [90];
        yield '180 days' => [180];
        yield '365 days' => [365];
    }

    #[DataProvider('supportedPeriods')]
    public function testItAcceptsSupportedRetentionPeriods(int $days): void
    {
        self::assertSame($days, (new ApiRequestLogRetentionPolicy($days))->days);
    }

    public function testKeepForeverHasNoCutoff(): void
    {
        $policy = new ApiRequestLogRetentionPolicy(0);

        self::assertTrue($policy->keepsForever());
        self::assertNull($policy->cutoff(new DateTimeImmutable('2026-07-19 12:00:00+08:00')));
    }

    public function testItCalculatesTheCutoffInUtc(): void
    {
        $policy = new ApiRequestLogRetentionPolicy(90);
        $cutoff = $policy->cutoff(new DateTimeImmutable('2026-07-19 12:00:00+08:00'));

        self::assertSame('2026-04-20 04:00:00.000000', $cutoff?->format('Y-m-d H:i:s.u'));
        self::assertSame('UTC', $cutoff?->getTimezone()->getName());
    }

    public function testItRejectsUnsupportedRetentionPeriods(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('0, 30, 90, 180, or 365');

        new ApiRequestLogRetentionPolicy(7);
    }
}
