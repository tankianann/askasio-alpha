<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Chatbots\ChatbotListQuery;
use App\Domain\Chatbots\ChatbotListSort;
use App\Domain\Chatbots\ChatbotListStatus;
use App\Domain\Chatbots\ChatbotPublicationFilter;
use App\Repositories\ChatbotListSqlQueryBuilder;
use App\Support\Pagination\PageRequest;
use App\Support\SortDirection;
use PHPUnit\Framework\TestCase;

final class ChatbotListSqlQueryBuilderTest extends TestCase
{
    public function testItBuildsParameterizedFiltersAndAllowlistedOrdering(): void
    {
        $query = new ChatbotListQuery(
            new PageRequest(),
            "Support%'",
            ChatbotListStatus::Disabled,
            ChatbotPublicationFilter::Published,
            ChatbotListSort::Publication,
            SortDirection::Ascending,
        );
        $builder = new ChatbotListSqlQueryBuilder();
        $where = $builder->where($query);

        self::assertStringContainsString('LOCATE(:search_name, c.name)', $where['sql']);
        self::assertStringContainsString('c.status = :status', $where['sql']);
        self::assertStringContainsString('c.active_publication_id IS NOT NULL', $where['sql']);
        self::assertSame("Support%'", $where['parameters']['search_name']);
        self::assertStringNotContainsString("Support%'", $where['sql']);
        self::assertSame(' ORDER BY cp.publication_number ASC, c.id ASC', $builder->orderBy($query));
    }
}

