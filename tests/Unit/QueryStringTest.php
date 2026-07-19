<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\QueryString;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class QueryStringTest extends TestCase
{
    public function testItBuildsLocalUrlsAndPreservesStateWhileOverridingThePage(): void
    {
        $url = QueryString::url('/admin/api-requests', [
            'status_group' => 'client_error',
            'endpoint' => '/api/v1/chat',
            'page' => 2,
        ], ['page' => 3]);

        self::assertSame(
            '/admin/api-requests?endpoint=%2Fapi%2Fv1%2Fchat&page=3&status_group=client_error',
            $url,
        );
    }

    public function testNullAndEmptyOverridesRemoveParameters(): void
    {
        self::assertSame('?status=failed', QueryString::build(
            ['page' => 4, 'status' => 'failed'],
            ['page' => null],
        ));
        self::assertSame('', QueryString::build(['page' => '']));
    }

    public function testItRejectsExternalPaths(): void
    {
        $this->expectException(InvalidArgumentException::class);

        QueryString::url('https://example.com', []);
    }

    public function testItRejectsNestedQueryValues(): void
    {
        $this->expectException(InvalidArgumentException::class);

        QueryString::build(['filter' => ['unsafe']]);
    }
}
