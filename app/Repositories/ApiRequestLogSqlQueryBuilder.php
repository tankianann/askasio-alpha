<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Domain\Api\ApiRequestAuthenticationState;
use App\Domain\Api\ApiRequestLogQuery;
use App\Domain\Api\ApiRequestLogSort;

final class ApiRequestLogSqlQueryBuilder
{
    /** @return array{sql: string, parameters: array<string, int|string>} */
    public function where(ApiRequestLogQuery $query): array
    {
        $clauses = [];
        $parameters = [];

        if ($query->createdFromUtc !== null) {
            $clauses[] = 'logs.created_at >= :created_from';
            $parameters['created_from'] = $query->createdFromUtc;
        }

        if ($query->createdBeforeUtc !== null) {
            $clauses[] = 'logs.created_at < :created_before';
            $parameters['created_before'] = $query->createdBeforeUtc;
        }

        if ($query->apiKeyId !== null) {
            $clauses[] = 'logs.api_key_id = :api_key_id';
            $parameters['api_key_id'] = $query->apiKeyId;
        }

        if ($query->endpoint !== null) {
            $clauses[] = 'logs.endpoint = :endpoint';
            $parameters['endpoint'] = $query->endpoint;
        }

        if ($query->method !== null) {
            $clauses[] = 'logs.method = :method';
            $parameters['method'] = $query->method;
        }

        if ($query->statusCode !== null) {
            $clauses[] = 'logs.status_code = :status_code';
            $parameters['status_code'] = $query->statusCode;
        } elseif ($query->statusGroup !== null) {
            $clauses[] = 'logs.status_code BETWEEN :status_minimum AND :status_maximum';
            $parameters['status_minimum'] = $query->statusGroup->minimumStatus();
            $parameters['status_maximum'] = $query->statusGroup->maximumStatus();
        }

        if ($query->minimumDurationMilliseconds !== null) {
            $clauses[] = 'logs.duration_ms >= :duration_minimum';
            $parameters['duration_minimum'] = $query->minimumDurationMilliseconds;
        }

        if ($query->maximumDurationMilliseconds !== null) {
            $clauses[] = 'logs.duration_ms <= :duration_maximum';
            $parameters['duration_maximum'] = $query->maximumDurationMilliseconds;
        }

        if ($query->requestId !== null) {
            $clauses[] = 'logs.request_id = :request_id';
            $parameters['request_id'] = $query->requestId;
        }

        if ($query->authentication === ApiRequestAuthenticationState::Authenticated) {
            $clauses[] = 'logs.access_method <> \'unauthenticated\'';
        } elseif ($query->authentication === ApiRequestAuthenticationState::Unauthenticated) {
            $clauses[] = 'logs.access_method = \'unauthenticated\'';
        }

        if ($query->accessMethod->value !== 'all') {
            $clauses[] = 'logs.access_method = :access_method';
            $parameters['access_method'] = $query->accessMethod->value;
        }

        return [
            'sql' => $clauses === [] ? '' : ' WHERE ' . implode(' AND ', $clauses),
            'parameters' => $parameters,
        ];
    }

    public function orderBy(ApiRequestLogQuery $query): string
    {
        $column = match ($query->sort) {
            ApiRequestLogSort::Date => 'logs.created_at',
            ApiRequestLogSort::Duration => 'logs.duration_ms',
            ApiRequestLogSort::Status => 'logs.status_code',
            ApiRequestLogSort::Endpoint => 'logs.endpoint',
            ApiRequestLogSort::Connection => "COALESCE(api_key_records.name, chatbot_key_records.name, chatbot_records.name, logs.access_method, '')",
        };
        $direction = strtoupper($query->direction->value);

        if ($query->sort === ApiRequestLogSort::Date) {
            return sprintf(' ORDER BY logs.created_at %s, logs.id %s', $direction, $direction);
        }

        return sprintf(
            ' ORDER BY %s %s, logs.created_at %s, logs.id %s',
            $column,
            $direction,
            $direction,
            $direction,
        );
    }
}
