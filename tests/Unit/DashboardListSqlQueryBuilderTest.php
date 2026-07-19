<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\Request;
use App\Repositories\ApiKeyListSqlQueryBuilder;
use App\Repositories\IngestionJobListSqlQueryBuilder;
use App\Repositories\SourceListSqlQueryBuilder;
use App\Services\ApiKeys\ApiKeyListQueryParser;
use App\Services\Ingestion\IngestionJobListQueryParser;
use App\Services\Sources\SourceListQueryParser;
use PHPUnit\Framework\TestCase;

final class DashboardListSqlQueryBuilderTest extends TestCase
{
    public function testKnowledgeSqlUsesParametersAndAllowlistedOrdering(): void
    {
        $query = (new SourceListQueryParser('UTC'))->parse(new Request('GET', '/', query: [
            'search' => "Policy%'", 'type' => 'url', 'availability' => 'disabled',
            'processing' => 'failed', 'sort' => 'revisions', 'direction' => 'asc',
        ]));
        $builder = new SourceListSqlQueryBuilder();
        $where = $builder->where($query);

        self::assertStringContainsString('LOCATE(:search, s.name)', $where['sql']);
        self::assertStringContainsString('latest.processing_status = :processing_status', $where['sql']);
        self::assertSame("Policy%'", $where['parameters']['search']);
        self::assertStringNotContainsString("Policy%'", $where['sql']);
        self::assertSame(' ORDER BY version_count ASC, s.id ASC', $builder->orderBy($query));
    }

    public function testProcessingSqlFiltersBeforePagination(): void
    {
        $query = (new IngestionJobListQueryParser('UTC'))->parse(new Request('GET', '/', query: [
            'status' => 'failed', 'source_id' => '9', 'attempts_min' => '2', 'sort' => 'source',
        ]));
        $builder = new IngestionJobListSqlQueryBuilder();
        $where = $builder->where($query);

        self::assertStringContainsString('j.status = :status', $where['sql']);
        self::assertStringContainsString('sv.source_id = :source_id', $where['sql']);
        self::assertSame(2, $where['parameters']['attempts_min']);
        self::assertSame(' ORDER BY s.name DESC, j.id DESC', $builder->orderBy($query));
    }

    public function testApiKeySqlModelsDisplayedExpiredStatusWithoutSelectingSecrets(): void
    {
        $query = (new ApiKeyListQueryParser('UTC'))->parse(new Request('GET', '/', query: [
            'search' => 'rag_live_abcd', 'status' => 'expired', 'sort' => 'last_used',
        ]));
        $builder = new ApiKeyListSqlQueryBuilder();
        $where = $builder->where($query);

        self::assertStringContainsString('expires_at <= UTC_TIMESTAMP(6)', $where['sql']);
        self::assertStringContainsString('LOCATE(:search_prefix, visible_prefix)', $where['sql']);
        self::assertSame('rag_live_abcd', $where['parameters']['search_prefix']);
        self::assertSame(' ORDER BY last_used_at DESC, id DESC', $builder->orderBy($query));
    }
}
