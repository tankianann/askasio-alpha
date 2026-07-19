<?php

declare(strict_types=1);

namespace App\Services\Sources;

use App\Domain\Sources\SourceHistoryQuery;
use App\Http\Request;
use App\Services\Admin\AdminListQueryInput;
use App\Support\Pagination\PageRequest;

final readonly class SourceHistoryQueryParser
{
    private const PAGE_SIZE = 10;

    public function __construct(private string $timezone)
    {
    }

    public function parse(Request $request): SourceHistoryQuery
    {
        $input = new AdminListQueryInput($request, $this->timezone);

        return new SourceHistoryQuery(
            new PageRequest(
                $input->integer('revision_page', 1, 1, 1_000_000) ?? 1,
                self::PAGE_SIZE,
                [self::PAGE_SIZE],
            ),
            new PageRequest(
                $input->integer('job_page', 1, 1, 1_000_000) ?? 1,
                self::PAGE_SIZE,
                [self::PAGE_SIZE],
            ),
        );
    }
}
