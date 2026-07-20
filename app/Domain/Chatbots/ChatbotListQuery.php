<?php

declare(strict_types=1);

namespace App\Domain\Chatbots;

use App\Support\Pagination\PageRequest;
use App\Support\SortDirection;

final readonly class ChatbotListQuery
{
    public function __construct(
        public PageRequest $pagination,
        public ?string $search,
        public ChatbotListStatus $status,
        public ChatbotPublicationFilter $publication,
        public ChatbotListSort $sort,
        public SortDirection $direction,
    ) {
    }
}

