<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Domain\Chatbots\ChatbotListQuery;
use App\Domain\Chatbots\ChatbotListSort;
use App\Domain\Chatbots\ChatbotListStatus;
use App\Domain\Chatbots\ChatbotPublicationFilter;
use App\Support\SortDirection;

final class ChatbotListSqlQueryBuilder
{
    /** @return array{sql: string, parameters: array<string, string>} */
    public function where(ChatbotListQuery $query): array
    {
        $clauses = [];
        $parameters = [];

        if ($query->search !== null) {
            $clauses[] = '(LOCATE(:search_name, c.name) > 0 OR LOCATE(:search_public_id, c.public_id) > 0)';
            $parameters['search_name'] = $query->search;
            $parameters['search_public_id'] = $query->search;
        }

        if ($query->status !== ChatbotListStatus::All) {
            $clauses[] = 'c.status = :status';
            $parameters['status'] = $query->status->value;
        }

        match ($query->publication) {
            ChatbotPublicationFilter::All => null,
            ChatbotPublicationFilter::Draft => $clauses[] = 'c.active_publication_id IS NULL',
            ChatbotPublicationFilter::Published => $clauses[] = 'c.active_publication_id IS NOT NULL',
        };

        return [
            'sql' => $clauses === [] ? '' : ' WHERE ' . implode(' AND ', $clauses),
            'parameters' => $parameters,
        ];
    }

    public function orderBy(ChatbotListQuery $query): string
    {
        $column = match ($query->sort) {
            ChatbotListSort::Updated => 'c.updated_at',
            ChatbotListSort::Name => 'c.name',
            ChatbotListSort::Status => 'c.status',
            ChatbotListSort::Publication => 'cp.publication_number',
        };
        $direction = $query->direction === SortDirection::Ascending ? 'ASC' : 'DESC';

        return sprintf(' ORDER BY %s %s, c.id %s', $column, $direction, $direction);
    }
}

