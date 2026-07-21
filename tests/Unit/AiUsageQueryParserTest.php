<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exceptions\ValidationException;
use App\Http\Request;
use App\Services\ProviderQuota\AiUsageQueryParser;
use PHPUnit\Framework\TestCase;

final class AiUsageQueryParserTest extends TestCase
{
    public function testItParsesAnInclusiveBoundedWindowIntoUtc(): void
    {
        $query = (new AiUsageQueryParser())->parse(new Request(
            'GET',
            '/admin/ai-usage',
            query: ['date_from' => '2026-07-01', 'date_to' => '2026-07-21'],
        ));

        self::assertSame('2026-07-01', $query->dateFrom);
        self::assertSame('2026-07-21', $query->dateTo);
        self::assertSame('2026-07-01 00:00:00', $query->fromUtc);
        self::assertSame('2026-07-22 00:00:00', $query->beforeUtc);
    }

    public function testItRejectsWindowsLongerThanNinetyDays(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('between 1 and 90 days');

        (new AiUsageQueryParser())->parse(new Request(
            'GET',
            '/admin/ai-usage',
            query: ['date_from' => '2026-01-01', 'date_to' => '2026-07-21'],
        ));
    }
}
