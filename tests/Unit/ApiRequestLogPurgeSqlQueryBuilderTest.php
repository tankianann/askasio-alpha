<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Api\ApiRequestAuthenticationState;
use App\Domain\Api\ApiRequestLogPurgeCriteria;
use App\Domain\Api\ApiRequestLogPurgeScope;
use App\Domain\Api\ApiRequestStatusGroup;
use App\Repositories\ApiRequestLogPurgeSqlQueryBuilder;
use PHPUnit\Framework\TestCase;

final class ApiRequestLogPurgeSqlQueryBuilderTest extends TestCase
{
    public function testItBuildsParameterizedFilterAndSnapshotClauses(): void
    {
        $criteria = new ApiRequestLogPurgeCriteria(
            ApiRequestLogPurgeScope::MatchingFilters,
            createdFromUtc: '2026-01-01 00:00:00',
            createdBeforeUtc: '2026-07-01 00:00:00',
            apiKeyId: 4,
            endpoint: '/api/v1/chat',
            method: 'POST',
            statusGroup: ApiRequestStatusGroup::ServerError,
            minimumDurationMilliseconds: 100,
            maximumDurationMilliseconds: 900,
            authentication: ApiRequestAuthenticationState::Authenticated,
        );
        $where = (new ApiRequestLogPurgeSqlQueryBuilder())->where($criteria, 123);

        self::assertStringContainsString('created_at >= :created_from', $where['sql']);
        self::assertStringContainsString('status_code BETWEEN :status_minimum AND :status_maximum', $where['sql']);
        self::assertStringContainsString("access_method <> 'unauthenticated'", $where['sql']);
        self::assertStringContainsString('id <= :maximum_id', $where['sql']);
        self::assertSame('/api/v1/chat', $where['parameters']['endpoint']);
        self::assertSame(500, $where['parameters']['status_minimum']);
        self::assertSame(599, $where['parameters']['status_maximum']);
        self::assertSame(123, $where['parameters']['maximum_id']);
        self::assertStringNotContainsString('/api/v1/chat', $where['sql']);
    }

    public function testAllScopeHasNoWhereClauseUntilSnapshotBoundaryIsApplied(): void
    {
        $criteria = new ApiRequestLogPurgeCriteria(ApiRequestLogPurgeScope::All);
        $builder = new ApiRequestLogPurgeSqlQueryBuilder();

        self::assertSame(['sql' => '', 'parameters' => []], $builder->where($criteria));
        self::assertSame(
            ['sql' => ' WHERE id <= :maximum_id', 'parameters' => ['maximum_id' => 8]],
            $builder->where($criteria, 8),
        );
    }
}
