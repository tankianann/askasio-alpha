<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Ingestion\SourceVersionActivationPolicy;
use PHPUnit\Framework\TestCase;

final class SourceVersionActivationPolicyTest extends TestCase
{
    public function testItAllowsTheFirstAndNewestVersionsToActivate(): void
    {
        $policy = new SourceVersionActivationPolicy();

        self::assertTrue($policy->shouldActivate(1, null));
        self::assertTrue($policy->shouldActivate(3, 2));
        self::assertTrue($policy->shouldActivate(3, 3));
    }

    public function testItPreventsAnOlderConcurrentJobFromReplacingANewerVersion(): void
    {
        self::assertFalse((new SourceVersionActivationPolicy())->shouldActivate(2, 3));
    }
}
