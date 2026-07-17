<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Domain\Ingestion\FetchedPage;
use App\Networking\UrlFetcherInterface;

final class FakeUrlFetcher implements UrlFetcherInterface
{
    public function __construct(private readonly FetchedPage $page)
    {
    }

    public function fetch(string $url): FetchedPage
    {
        return $this->page;
    }
}
