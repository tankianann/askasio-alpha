<?php

declare(strict_types=1);

namespace App\Domain\Sources;

use App\Support\Pagination\PageRequest;

final readonly class SourceHistoryQuery
{
    public function __construct(
        public PageRequest $revisions,
        public PageRequest $jobs,
    ) {
    }

    /** @return array<string, int> */
    public function queryParameters(): array
    {
        return array_filter([
            'revision_page' => $this->revisions->page === 1 ? null : $this->revisions->page,
            'job_page' => $this->jobs->page === 1 ? null : $this->jobs->page,
        ], static fn (?int $value): bool => $value !== null);
    }
}
