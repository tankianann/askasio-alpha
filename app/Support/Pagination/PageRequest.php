<?php

declare(strict_types=1);

namespace App\Support\Pagination;

use InvalidArgumentException;

final readonly class PageRequest
{
    /** @var list<int> */
    private array $allowedPageSizes;

    /** @param list<int> $allowedPageSizes */
    public function __construct(
        public int $page = 1,
        public int $perPage = 25,
        array $allowedPageSizes = [25, 50, 100],
    ) {
        if ($this->page < 1) {
            throw new InvalidArgumentException('The page number must be positive.');
        }

        $allowedPageSizes = array_values(array_unique($allowedPageSizes));

        if ($allowedPageSizes === []) {
            throw new InvalidArgumentException('Allowed page sizes must be positive integers.');
        }

        foreach ($allowedPageSizes as $size) {
            if (!is_int($size) || $size < 1) {
                throw new InvalidArgumentException('Allowed page sizes must be positive integers.');
            }
        }

        sort($allowedPageSizes);

        if (!in_array($this->perPage, $allowedPageSizes, true)) {
            throw new InvalidArgumentException('The requested page size is not allowed.');
        }

        if (($this->page - 1) > intdiv(PHP_INT_MAX, $this->perPage)) {
            throw new InvalidArgumentException('The requested page is too large.');
        }

        $this->allowedPageSizes = $allowedPageSizes;
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }

    public function clampToTotal(int $total): self
    {
        if ($total < 0) {
            throw new InvalidArgumentException('The total result count cannot be negative.');
        }

        $lastPage = max(1, (int) ceil($total / $this->perPage));

        return new self(min($this->page, $lastPage), $this->perPage, $this->allowedPageSizes);
    }

    /** @return list<int> */
    public function allowedPageSizes(): array
    {
        return $this->allowedPageSizes;
    }
}
