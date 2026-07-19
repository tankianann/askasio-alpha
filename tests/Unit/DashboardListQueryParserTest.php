<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exceptions\ValidationException;
use App\Http\Request;
use App\Services\ApiKeys\ApiKeyListQueryParser;
use App\Services\Ingestion\IngestionJobListQueryParser;
use App\Services\Sources\SourceListQueryParser;
use PHPUnit\Framework\TestCase;

final class DashboardListQueryParserTest extends TestCase
{
    public function testKnowledgeQueryPreservesValidatedFiltersAndSortState(): void
    {
        $query = (new SourceListQueryParser('Asia/Singapore'))->parse(new Request(
            'GET',
            '/admin/sources',
            query: [
                'page' => '2', 'per_page' => '50', 'search' => 'Policy', 'type' => 'pdf',
                'availability' => 'enabled', 'processing' => 'ready', 'sort' => 'name', 'direction' => 'asc',
            ],
        ));

        self::assertSame(2, $query->pagination->page);
        self::assertSame(50, $query->pagination->perPage);
        self::assertSame('pdf', $query->type?->value);
        self::assertSame('ready', $query->processingStatus?->value);
        self::assertSame('Policy', $query->queryParameters()['search']);
        self::assertSame('asc', $query->queryParameters()['direction']);
    }

    public function testProcessingQueryConvertsDatesToUtcAndValidatesAttemptRange(): void
    {
        $query = (new IngestionJobListQueryParser('Asia/Singapore'))->parse(new Request(
            'GET',
            '/admin/jobs',
            query: [
                'status' => 'failed', 'source_id' => '3', 'date_from' => '2026-07-19',
                'date_to' => '2026-07-20', 'attempts_min' => '1', 'attempts_max' => '3',
            ],
        ));

        self::assertSame('2026-07-18 16:00:00', $query->createdFromUtc);
        self::assertSame('2026-07-20 16:00:00', $query->createdBeforeUtc);
        self::assertSame(3, $query->sourceId);
        self::assertSame('failed', $query->status?->value);
    }

    public function testApiAccessQuerySupportsSearchStatusAndLastUsedSort(): void
    {
        $query = (new ApiKeyListQueryParser('UTC'))->parse(new Request(
            'GET',
            '/admin/api-keys',
            query: ['search' => 'storefront', 'status' => 'active', 'sort' => 'last_used', 'direction' => 'asc'],
        ));

        self::assertSame('storefront', $query->search);
        self::assertSame('active', $query->status->value);
        self::assertSame('last_used', $query->sort->value);
        self::assertTrue($query->hasActiveFilters());
    }

    public function testUnknownSortIsRejectedBeforeRepositoryAccess(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('sort parameter is invalid');

        (new SourceListQueryParser('UTC'))->parse(new Request(
            'GET',
            '/admin/sources',
            query: ['sort' => 'DROP TABLE sources'],
        ));
    }

    public function testInvalidPageSizeIsRejectedConsistently(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('25, 50, or 100');

        (new ApiKeyListQueryParser('UTC'))->parse(new Request(
            'GET',
            '/admin/api-keys',
            query: ['per_page' => '500'],
        ));
    }
}
