<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Domain\ApiKeys\ApiKeyListQuery;
use App\Domain\ApiKeys\ApiKeyListSort;
use App\Domain\ApiKeys\ApiKeyListStatus;
use App\Support\SortDirection;

final class ApiKeyListSqlQueryBuilder
{
    /** @return array{sql: string, parameters: array<string, string>} */
    public function where(ApiKeyListQuery $query): array
    {
        $clauses = [];
        $parameters = [];

        if ($query->search !== null) {
            $clauses[] = '(LOCATE(:search_name, name) > 0 OR LOCATE(:search_prefix, visible_prefix) > 0)';
            $parameters['search_name'] = $query->search;
            $parameters['search_prefix'] = $query->search;
        }

        match ($query->status) {
            ApiKeyListStatus::All => null,
            ApiKeyListStatus::Active => $clauses[] = "status = 'active' AND (expires_at IS NULL OR expires_at > UTC_TIMESTAMP(6))",
            ApiKeyListStatus::Revoked => $clauses[] = "status = 'revoked' AND (expires_at IS NULL OR expires_at > UTC_TIMESTAMP(6))",
            ApiKeyListStatus::Expired => $clauses[] = 'expires_at IS NOT NULL AND expires_at <= UTC_TIMESTAMP(6)',
        };

        return [
            'sql' => $clauses === [] ? '' : ' WHERE ' . implode(' AND ', $clauses),
            'parameters' => $parameters,
        ];
    }

    public function orderBy(ApiKeyListQuery $query): string
    {
        $column = match ($query->sort) {
            ApiKeyListSort::Created => 'created_at',
            ApiKeyListSort::Name => 'name',
            ApiKeyListSort::Status => "CASE WHEN expires_at IS NOT NULL AND expires_at <= UTC_TIMESTAMP(6) THEN 'expired' ELSE status END",
            ApiKeyListSort::LastUsed => 'last_used_at',
            ApiKeyListSort::Expires => 'expires_at',
        };
        $direction = $query->direction === SortDirection::Ascending ? 'ASC' : 'DESC';

        return sprintf(' ORDER BY %s %s, id %s', $column, $direction, $direction);
    }
}
