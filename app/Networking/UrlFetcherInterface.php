<?php

declare(strict_types=1);

namespace App\Networking;

use App\Domain\Ingestion\FetchedPage;

interface UrlFetcherInterface
{
    public function fetch(string $url): FetchedPage;
}
