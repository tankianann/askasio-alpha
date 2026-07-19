<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Domain\Api\ApiRequestAuthenticationState;
use App\Domain\Api\ApiRequestLogPurgeCriteria;

final class ApiRequestLogPurgeSqlQueryBuilder
{
    /** @return array{sql: string, parameters: array<string, int|string>} */
    public function where(ApiRequestLogPurgeCriteria $criteria, ?int $maximumId = null): array
    {
        $clauses = [];
        $parameters = [];

        $this->add($clauses, $parameters, 'created_at < :purge_cutoff', 'purge_cutoff', $criteria->cutoffUtc);
        $this->add($clauses, $parameters, 'created_at >= :created_from', 'created_from', $criteria->createdFromUtc);
        $this->add($clauses, $parameters, 'created_at < :created_before', 'created_before', $criteria->createdBeforeUtc);
        $this->add($clauses, $parameters, 'api_key_id = :api_key_id', 'api_key_id', $criteria->apiKeyId);
        $this->add($clauses, $parameters, 'endpoint = :endpoint', 'endpoint', $criteria->endpoint);
        $this->add($clauses, $parameters, 'method = :method', 'method', $criteria->method);
        $this->add($clauses, $parameters, 'status_code = :status_code', 'status_code', $criteria->statusCode);

        if ($criteria->statusCode === null && $criteria->statusGroup !== null) {
            $clauses[] = 'status_code BETWEEN :status_minimum AND :status_maximum';
            $parameters['status_minimum'] = $criteria->statusGroup->minimumStatus();
            $parameters['status_maximum'] = $criteria->statusGroup->maximumStatus();
        }

        $this->add($clauses, $parameters, 'duration_ms >= :duration_min', 'duration_min', $criteria->minimumDurationMilliseconds);
        $this->add($clauses, $parameters, 'duration_ms <= :duration_max', 'duration_max', $criteria->maximumDurationMilliseconds);
        $this->add($clauses, $parameters, 'request_id = :request_id', 'request_id', $criteria->requestId);

        if ($criteria->authentication === ApiRequestAuthenticationState::Authenticated) {
            $clauses[] = 'api_key_id IS NOT NULL';
        } elseif ($criteria->authentication === ApiRequestAuthenticationState::Unauthenticated) {
            $clauses[] = 'api_key_id IS NULL';
        }

        $this->add($clauses, $parameters, 'id <= :maximum_id', 'maximum_id', $maximumId);

        return [
            'sql' => $clauses === [] ? '' : ' WHERE ' . implode(' AND ', $clauses),
            'parameters' => $parameters,
        ];
    }

    /**
     * @param list<string> $clauses
     * @param array<string, int|string> $parameters
     */
    private function add(array &$clauses, array &$parameters, string $clause, string $parameter, int|string|null $value): void
    {
        if ($value === null) {
            return;
        }

        $clauses[] = $clause;
        $parameters[$parameter] = $value;
    }
}
