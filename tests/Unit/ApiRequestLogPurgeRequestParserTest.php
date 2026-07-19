<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Api\ApiRequestLogPurgeScope;
use App\Exceptions\ValidationException;
use App\Http\Request;
use App\Services\Api\ApiRequestLogPurgeRequestParser;
use App\Services\Api\ApiRequestLogQueryParser;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class ApiRequestLogPurgeRequestParserTest extends TestCase
{
    public function testItBuildsCriteriaFromTheCurrentValidatedFilters(): void
    {
        $queryRequest = new Request('GET', '/admin/api-requests', query: [
            'endpoint' => '/api/v1/chat',
            'status_group' => 'server_error',
            'authentication' => 'authenticated',
        ]);
        $query = (new ApiRequestLogQueryParser('Asia/Singapore'))->parse($queryRequest);
        $criteria = (new ApiRequestLogPurgeRequestParser('Asia/Singapore'))->parse(
            new Request('POST', '/admin/api-requests/purge/preview', parsedBody: ['scope' => 'matching_filters']),
            $query,
        );

        self::assertSame(ApiRequestLogPurgeScope::MatchingFilters, $criteria->scope);
        self::assertSame('/api/v1/chat', $criteria->endpoint);
        self::assertSame('server_error', $criteria->statusGroup?->value);
        self::assertSame('authenticated', $criteria->authentication->value);
    }

    public function testBeforeDateUsesLocalMidnightConvertedToUtc(): void
    {
        $criteria = $this->parser()->parse(
            new Request('POST', '/purge', parsedBody: [
                'scope' => 'before_date',
                'before_date' => '2026-07-19',
            ]),
            $this->query(),
        );

        self::assertSame(ApiRequestLogPurgeScope::BeforeDate, $criteria->scope);
        self::assertSame('2026-07-18 16:00:00.000000', $criteria->cutoffUtc);
    }

    public function testAgeUsesAStableUtcCutoff(): void
    {
        $criteria = $this->parser()->parse(
            new Request('POST', '/purge', parsedBody: [
                'scope' => 'older_than_age',
                'age_days' => '90',
            ]),
            $this->query(),
        );

        self::assertSame(ApiRequestLogPurgeScope::OlderThanAge, $criteria->scope);
        self::assertSame('2026-04-20 04:00:00.000000', $criteria->cutoffUtc);
    }

    public function testItRejectsMatchingFiltersWhenNoneAreActive(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Apply at least one filter');

        $this->parser()->parse(
            new Request('POST', '/purge', parsedBody: ['scope' => 'matching_filters']),
            $this->query(),
        );
    }

    public function testItRejectsUnsupportedAge(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('30, 90, 180, or 365');

        $this->parser()->parse(
            new Request('POST', '/purge', parsedBody: ['scope' => 'older_than_age', 'age_days' => '7']),
            $this->query(),
        );
    }

    private function parser(): ApiRequestLogPurgeRequestParser
    {
        return new ApiRequestLogPurgeRequestParser(
            'Asia/Singapore',
            static fn (): DateTimeImmutable => new DateTimeImmutable('2026-07-19 12:00:00+08:00'),
        );
    }

    private function query(): \App\Domain\Api\ApiRequestLogQuery
    {
        return (new ApiRequestLogQueryParser('Asia/Singapore'))->parse(new Request('GET', '/admin/api-requests'));
    }
}
