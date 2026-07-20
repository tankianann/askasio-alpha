<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Ingestion\WorkerRuntimePolicy;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class WorkerRuntimePolicyTest extends TestCase
{
    public function testDefaultLimitsFitInsideTheDefaultAbandonedTimeout(): void
    {
        $policy = new WorkerRuntimePolicy(45 * 60, 5, 20, 5, true, 900, 120, 256, 64, 60, 3);

        self::assertLessThanOrEqual(45 * 60, $policy->minimumAbandonedTimeoutSeconds);
        self::assertGreaterThan(30 * 60, $policy->minimumAbandonedTimeoutSeconds);
    }

    public function testItRejectsAReservationWindowThatCanExpireDuringValidProcessing(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('use at least 37 minutes');

        new WorkerRuntimePolicy(30 * 60, 5, 20, 5, true, 900, 120, 256, 64, 60, 3);
    }

    public function testItRejectsAnOcrPageTimeoutLongerThanTheProcessTimeout(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('PDF_OCR_PAGE_TIMEOUT_SECONDS');

        new WorkerRuntimePolicy(60 * 60, 5, 20, 5, true, 60, 61, 32, 32, 30, 0);
    }

    public function testItRejectsAConnectionTimeoutLongerThanTheRequestTimeout(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('URL_CONNECT_TIMEOUT_SECONDS');

        new WorkerRuntimePolicy(60 * 60, 21, 20, 5, false, 900, 120, 32, 32, 30, 0);
    }
}
