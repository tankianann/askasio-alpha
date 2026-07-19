<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Domain\Sources\SourceListAvailability;
use App\Domain\Sources\SourceListQuery;
use App\Domain\Sources\SourceListSort;
use App\Support\SortDirection;

final class SourceListSqlQueryBuilder
{
    /** @return array{sql: string, parameters: array<string, int|string>} */
    public function where(SourceListQuery $query): array
    {
        $clauses = [];
        $parameters = [];

        if ($query->search !== null) {
            $clauses[] = 'LOCATE(:search, s.name) > 0';
            $parameters['search'] = $query->search;
        }

        if ($query->type !== null) {
            $clauses[] = 's.source_type = :source_type';
            $parameters['source_type'] = $query->type->value;
        }

        match ($query->availability) {
            SourceListAvailability::All => null,
            SourceListAvailability::Enabled => $clauses[] = "s.status = 'enabled' AND s.deleted_at IS NULL",
            SourceListAvailability::Disabled => $clauses[] = "s.status = 'disabled' AND s.deleted_at IS NULL",
            SourceListAvailability::Deleted => $clauses[] = 's.deleted_at IS NOT NULL',
        };

        if ($query->processingStatus !== null) {
            $clauses[] = 'latest.processing_status = :processing_status';
            $parameters['processing_status'] = $query->processingStatus->value;
        }

        return [
            'sql' => $clauses === [] ? '' : ' WHERE ' . implode(' AND ', $clauses),
            'parameters' => $parameters,
        ];
    }

    public function orderBy(SourceListQuery $query): string
    {
        $column = match ($query->sort) {
            SourceListSort::Updated => 's.updated_at',
            SourceListSort::Name => 's.name',
            SourceListSort::Type => 's.source_type',
            SourceListSort::Availability => "CASE WHEN s.deleted_at IS NOT NULL THEN 2 WHEN s.status = 'disabled' THEN 1 ELSE 0 END",
            SourceListSort::Processing => 'latest.processing_status',
            SourceListSort::Revisions => 'version_count',
        };
        $direction = $query->direction === SortDirection::Ascending ? 'ASC' : 'DESC';

        return sprintf(' ORDER BY %s %s, s.id %s', $column, $direction, $direction);
    }
}
