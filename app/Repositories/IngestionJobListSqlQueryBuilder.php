<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Domain\Ingestion\IngestionJobListQuery;
use App\Domain\Ingestion\IngestionJobListSort;
use App\Support\SortDirection;

final class IngestionJobListSqlQueryBuilder
{
    /** @return array{sql: string, parameters: array<string, int|string>} */
    public function where(IngestionJobListQuery $query): array
    {
        $clauses = [];
        $parameters = [];
        $this->add($clauses, $parameters, 'j.status = :status', 'status', $query->status?->value);
        $this->add($clauses, $parameters, 'sv.source_id = :source_id', 'source_id', $query->sourceId);
        $this->add($clauses, $parameters, 'j.created_at >= :created_from', 'created_from', $query->createdFromUtc);
        $this->add($clauses, $parameters, 'j.created_at < :created_before', 'created_before', $query->createdBeforeUtc);
        $this->add($clauses, $parameters, 'j.attempts >= :attempts_min', 'attempts_min', $query->minimumAttempts);
        $this->add($clauses, $parameters, 'j.attempts <= :attempts_max', 'attempts_max', $query->maximumAttempts);

        return [
            'sql' => $clauses === [] ? '' : ' WHERE ' . implode(' AND ', $clauses),
            'parameters' => $parameters,
        ];
    }

    public function orderBy(IngestionJobListQuery $query): string
    {
        $column = match ($query->sort) {
            IngestionJobListSort::Date => 'j.created_at',
            IngestionJobListSort::Status => 'j.status',
            IngestionJobListSort::Source => 's.name',
            IngestionJobListSort::Attempts => 'j.attempts',
            IngestionJobListSort::Available => 'j.available_at',
        };
        $direction = $query->direction === SortDirection::Ascending ? 'ASC' : 'DESC';

        return sprintf(' ORDER BY %s %s, j.id %s', $column, $direction, $direction);
    }

    /** @param list<string> $clauses
     *  @param array<string, int|string> $parameters
     */
    private function add(array &$clauses, array &$parameters, string $clause, string $key, int|string|null $value): void
    {
        if ($value === null) {
            return;
        }

        $clauses[] = $clause;
        $parameters[$key] = $value;
    }
}
