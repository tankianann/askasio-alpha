<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Pagination\PageRequest;
use App\Support\Pagination\PaginatedResult;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PaginationTest extends TestCase
{
    public function testPageRequestCalculatesOffsetsAndClampsInvalidatedPages(): void
    {
        $requested = new PageRequest(12, 25);
        $clamped = $requested->clampToTotal(126);

        self::assertSame(275, $requested->offset());
        self::assertSame(6, $clamped->page);
        self::assertSame(125, $clamped->offset());
        self::assertSame([25, 50, 100], $clamped->allowedPageSizes());
        self::assertSame(1, $requested->clampToTotal(0)->page);
    }

    public function testPaginatedResultProvidesCountsNavigationAndACompactPageWindow(): void
    {
        $result = new PaginatedResult(
            array_fill(0, 25, 'request'),
            300,
            new PageRequest(6, 25),
        );

        self::assertSame(12, $result->totalPages());
        self::assertSame(126, $result->from());
        self::assertSame(150, $result->to());
        self::assertSame(5, $result->previousPage());
        self::assertSame(7, $result->nextPage());
        self::assertSame([1, null, 4, 5, 6, 7, 8, null, 12], $result->pageWindow());
    }

    public function testEmptyResultsStillHaveOneCanonicalPage(): void
    {
        $result = new PaginatedResult([], 0, new PageRequest());

        self::assertSame(1, $result->totalPages());
        self::assertSame(0, $result->from());
        self::assertSame(0, $result->to());
        self::assertNull($result->previousPage());
        self::assertNull($result->nextPage());
        self::assertSame([1], $result->pageWindow());
    }

    public function testItRejectsUnsupportedPageSizes(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new PageRequest(1, 20);
    }

    public function testItRejectsResultMetadataForAPagePastTheEnd(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new PaginatedResult([], 25, new PageRequest(2, 25));
    }
}
