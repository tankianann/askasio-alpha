<?php

declare(strict_types=1);

namespace App\Support\Pagination;

use InvalidArgumentException;

/** @template T */
final readonly class PaginatedResult
{
    /** @var list<T> */
    public array $items;

    /** @param list<T> $items */
    public function __construct(
        array $items,
        public int $total,
        public PageRequest $pageRequest,
    ) {
        if ($this->total < 0) {
            throw new InvalidArgumentException('The total result count cannot be negative.');
        }

        if (count($items) > $this->pageRequest->perPage || count($items) > $this->total) {
            throw new InvalidArgumentException('The page items do not match the pagination metadata.');
        }

        if ($this->pageRequest->page > $this->totalPages()) {
            throw new InvalidArgumentException('The page number exceeds the final result page.');
        }

        $this->items = array_values($items);
    }

    public function totalPages(): int
    {
        return max(1, (int) ceil($this->total / $this->pageRequest->perPage));
    }

    public function from(): int
    {
        return $this->items === [] ? 0 : $this->pageRequest->offset() + 1;
    }

    public function to(): int
    {
        return $this->items === [] ? 0 : min($this->total, $this->pageRequest->offset() + count($this->items));
    }

    public function previousPage(): ?int
    {
        return $this->pageRequest->page > 1 ? $this->pageRequest->page - 1 : null;
    }

    public function nextPage(): ?int
    {
        return $this->pageRequest->page < $this->totalPages() ? $this->pageRequest->page + 1 : null;
    }

    /** @return list<int|null> Null entries represent an ellipsis. */
    public function pageWindow(int $radius = 2): array
    {
        if ($radius < 0) {
            throw new InvalidArgumentException('The page-window radius cannot be negative.');
        }

        $lastPage = $this->totalPages();
        $pages = [1, $lastPage];

        for (
            $page = max(1, $this->pageRequest->page - $radius);
            $page <= min($lastPage, $this->pageRequest->page + $radius);
            $page++
        ) {
            $pages[] = $page;
        }

        $pages = array_values(array_unique($pages));
        sort($pages);
        $window = [];
        $previous = null;

        foreach ($pages as $page) {
            if ($previous !== null && $page > $previous + 1) {
                $window[] = null;
            }

            $window[] = $page;
            $previous = $page;
        }

        return $window;
    }
}
