<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\Request;
use App\Repositories\ApiRequestLogSqlQueryBuilder;
use App\Services\Api\ApiRequestLogQueryParser;
use PHPUnit\Framework\TestCase;

final class ApiRequestLogSqlQueryBuilderTest extends TestCase
{
    public function testItBuildsParameterizedClausesForEverySupportedFilter(): void
    {
        $query = (new ApiRequestLogQueryParser('Asia/Singapore'))->parse(new Request(
            'GET',
            '/admin/api-requests',
            query: [
                'date_from' => '2026-07-01',
                'date_to' => '2026-07-19',
                'api_key_id' => '8',
                'endpoint' => '/api/v1/chat',
                'method' => 'POST',
                'status_group' => 'client_error',
                'duration_min' => '25',
                'duration_max' => '5000',
                'request_id' => 'request-123',
                'authentication' => 'authenticated',
                'sort' => 'duration',
                'direction' => 'asc',
            ],
        ));
        $builder = new ApiRequestLogSqlQueryBuilder();
        $where = $builder->where($query);

        self::assertStringContainsString('logs.created_at >= :created_from', $where['sql']);
        self::assertStringContainsString('logs.created_at < :created_before', $where['sql']);
        self::assertStringContainsString('logs.api_key_id = :api_key_id', $where['sql']);
        self::assertStringContainsString('logs.endpoint = :endpoint', $where['sql']);
        self::assertStringContainsString('logs.method = :method', $where['sql']);
        self::assertStringContainsString('logs.status_code BETWEEN :status_minimum AND :status_maximum', $where['sql']);
        self::assertStringContainsString('logs.duration_ms >= :duration_minimum', $where['sql']);
        self::assertStringContainsString('logs.duration_ms <= :duration_maximum', $where['sql']);
        self::assertStringContainsString('logs.request_id = :request_id', $where['sql']);
        self::assertStringContainsString('logs.api_key_id IS NOT NULL', $where['sql']);
        self::assertSame('/api/v1/chat', $where['parameters']['endpoint']);
        self::assertSame(400, $where['parameters']['status_minimum']);
        self::assertSame(499, $where['parameters']['status_maximum']);
        self::assertSame(
            ' ORDER BY logs.duration_ms ASC, logs.created_at ASC, logs.id ASC',
            $builder->orderBy($query),
        );
    }

    public function testExactStatusAndUnauthenticatedFilteringUseDedicatedClauses(): void
    {
        $query = (new ApiRequestLogQueryParser('UTC'))->parse(new Request(
            'GET',
            '/admin/api-requests',
            query: ['status_code' => '401', 'authentication' => 'unauthenticated'],
        ));
        $where = (new ApiRequestLogSqlQueryBuilder())->where($query);

        self::assertStringContainsString('logs.status_code = :status_code', $where['sql']);
        self::assertStringNotContainsString('BETWEEN', $where['sql']);
        self::assertStringContainsString('logs.api_key_id IS NULL', $where['sql']);
        self::assertSame(401, $where['parameters']['status_code']);
    }

    public function testDefaultOrderingIsNewestFirstAndCannotContainQueryInput(): void
    {
        $query = (new ApiRequestLogQueryParser('UTC'))->parse(new Request(
            'GET',
            '/admin/api-requests',
            query: ['endpoint' => '/api/v1/chat'],
        ));
        $builder = new ApiRequestLogSqlQueryBuilder();

        self::assertSame(' ORDER BY logs.created_at DESC, logs.id DESC', $builder->orderBy($query));
        self::assertStringNotContainsString('/api/v1/chat', $builder->where($query)['sql']);
    }
}
