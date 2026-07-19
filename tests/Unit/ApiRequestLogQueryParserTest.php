<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Api\ApiRequestAuthenticationState;
use App\Domain\Api\ApiRequestLogSort;
use App\Domain\Api\ApiRequestStatusGroup;
use App\Exceptions\ValidationException;
use App\Http\Request;
use App\Services\Api\ApiRequestLogQueryParser;
use App\Support\QueryString;
use App\Support\SortDirection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ApiRequestLogQueryParserTest extends TestCase
{
    public function testItUsesSafeCanonicalDefaults(): void
    {
        $query = $this->parser()->parse($this->request());

        self::assertSame(1, $query->pagination->page);
        self::assertSame(25, $query->pagination->perPage);
        self::assertSame(ApiRequestAuthenticationState::All, $query->authentication);
        self::assertSame(ApiRequestLogSort::Date, $query->sort);
        self::assertSame(SortDirection::Descending, $query->direction);
        self::assertFalse($query->hasActiveFilters());
        self::assertSame([], $query->queryParameters());
    }

    public function testItParsesEveryFilterAndConvertsLocalDatesToUtc(): void
    {
        $query = $this->parser()->parse($this->request([
            'page' => '2',
            'per_page' => '50',
            'date_from' => '2026-07-01',
            'date_to' => '2026-07-19',
            'api_key_id' => '7',
            'endpoint' => '/api/v1/chat',
            'method' => 'post',
            'status_group' => 'client_error',
            'duration_min' => '100',
            'duration_max' => '5000',
            'request_id' => '019f78de-6f1c-70f4-b20d-d144a31b7018',
            'authentication' => 'authenticated',
            'sort' => 'duration',
            'direction' => 'asc',
        ]));

        self::assertSame('2026-06-30 16:00:00', $query->createdFromUtc);
        self::assertSame('2026-07-19 16:00:00', $query->createdBeforeUtc);
        self::assertSame(7, $query->apiKeyId);
        self::assertSame('POST', $query->method);
        self::assertSame(ApiRequestStatusGroup::ClientError, $query->statusGroup);
        self::assertSame(ApiRequestAuthenticationState::Authenticated, $query->authentication);
        self::assertSame(ApiRequestLogSort::Duration, $query->sort);
        self::assertSame(SortDirection::Ascending, $query->direction);
        self::assertTrue($query->hasActiveFilters());
        self::assertSame(400, $query->statusGroup?->minimumStatus());
        self::assertSame(499, $query->statusGroup?->maximumStatus());
    }

    public function testExactStatusTakesPrecedenceAndCanonicalStateDropsUnknownParameters(): void
    {
        $query = $this->parser()->parse($this->request([
            'status_code' => '429',
            'status_group' => 'server_error',
            'unknown' => 'discard-me',
        ]));

        self::assertSame(429, $query->statusCode);
        self::assertNull($query->statusGroup);
        self::assertSame(['status_code' => 429], $query->queryParameters());
    }

    public function testDateRangesRespectDaylightSavingBoundaries(): void
    {
        $query = (new ApiRequestLogQueryParser('America/New_York'))->parse($this->request([
            'date_from' => '2026-03-08',
            'date_to' => '2026-03-08',
        ]));

        self::assertSame('2026-03-08 05:00:00', $query->createdFromUtc);
        self::assertSame('2026-03-09 04:00:00', $query->createdBeforeUtc);
    }

    public function testQueryStatePersistsFiltersWhenBuildingPaginationUrls(): void
    {
        $query = $this->parser()->parse($this->request([
            'page' => '2',
            'per_page' => '50',
            'endpoint' => '/api/v1/retrieve',
            'status_group' => 'success',
            'sort' => 'endpoint',
        ]));

        self::assertSame(
            '/admin/api-requests?endpoint=%2Fapi%2Fv1%2Fretrieve&page=3&per_page=50&sort=endpoint&status_group=success',
            QueryString::url('/admin/api-requests', $query->queryParameters(), ['page' => 3]),
        );
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidQueries(): iterable
    {
        yield 'page zero' => [['page' => '0']];
        yield 'non-numeric page' => [['page' => 'second']];
        yield 'unsupported page size' => [['per_page' => '20']];
        yield 'impossible date' => [['date_from' => '2026-02-30']];
        yield 'reversed date range' => [['date_from' => '2026-07-20', 'date_to' => '2026-07-01']];
        yield 'invalid key ID' => [['api_key_id' => '0']];
        yield 'external endpoint' => [['endpoint' => 'https://example.com/api']];
        yield 'endpoint query string' => [['endpoint' => '/api/v1/chat?secret=value']];
        yield 'unsupported method' => [['method' => 'TRACE']];
        yield 'invalid status code' => [['status_code' => '600']];
        yield 'invalid status group' => [['status_group' => 'redirect']];
        yield 'reversed duration' => [['duration_min' => '500', 'duration_max' => '100']];
        yield 'invalid request ID' => [['request_id' => 'request id with spaces']];
        yield 'invalid authentication state' => [['authentication' => 'maybe']];
        yield 'connection with unauthenticated state' => [[
            'api_key_id' => '7',
            'authentication' => 'unauthenticated',
        ]];
        yield 'invalid sort' => [['sort' => 'raw_sql']];
        yield 'invalid direction' => [['direction' => 'sideways']];
        yield 'array injection' => [['status_code' => ['500']]];
    }

    /** @param array<string, mixed> $parameters */
    #[DataProvider('invalidQueries')]
    public function testItRejectsInvalidQueryState(array $parameters): void
    {
        $this->expectException(ValidationException::class);

        $this->parser()->parse($this->request($parameters));
    }

    public function testItRejectsAnInvalidApplicationTimezone(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ApiRequestLogQueryParser('Not/A-Timezone');
    }

    /** @param array<string, mixed> $query */
    private function request(array $query = []): Request
    {
        return new Request('GET', '/admin/api-requests', query: $query);
    }

    private function parser(): ApiRequestLogQueryParser
    {
        return new ApiRequestLogQueryParser('Asia/Singapore');
    }
}
