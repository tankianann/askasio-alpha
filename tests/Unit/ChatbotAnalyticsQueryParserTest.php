<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exceptions\ValidationException;
use App\Http\Request;
use App\Services\Chatbots\ChatbotAnalyticsQueryParser;
use PHPUnit\Framework\TestCase;

final class ChatbotAnalyticsQueryParserTest extends TestCase
{
    public function testItParsesAFilteredWindowInTheAdministratorTimezone(): void
    {
        $query = (new ChatbotAnalyticsQueryParser('Asia/Singapore'))->parse(new Request(
            'GET',
            '/',
            query: [
                'date_from' => '2026-07-20',
                'date_to' => '2026-07-21',
                'chatbot_id' => '4',
                'traffic' => 'test',
            ],
        ));

        self::assertSame('2026-07-19 16:00:00', $query->fromUtc);
        self::assertSame('2026-07-21 16:00:00', $query->beforeUtc);
        self::assertSame(4, $query->chatbotId);
        self::assertTrue($query->isTest);
    }

    public function testItRejectsWindowsLongerThanNinetyDays(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('between 1 and 90 days');

        (new ChatbotAnalyticsQueryParser('UTC'))->parse(new Request(
            'GET',
            '/',
            query: ['date_from' => '2026-01-01', 'date_to' => '2026-04-01'],
        ));
    }

    public function testItRejectsUnknownTrafficClassification(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('traffic parameter');

        (new ChatbotAnalyticsQueryParser('UTC'))->parse(new Request(
            'GET',
            '/',
            query: ['traffic' => 'tenant'],
        ));
    }
}
