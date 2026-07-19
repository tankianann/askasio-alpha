<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Auth\SessionStoreInterface;
use App\Domain\Admin\AdminUser;
use App\Domain\Api\ApiRequestConnectionOption;
use App\Domain\Api\ApiRequestLogQuery;
use App\Domain\Api\ApiRequestLogSort;
use App\Exceptions\ValidationException;
use App\Http\Request;
use App\Http\Response;
use App\Repositories\ApiRequestLogRepositoryInterface;
use App\Security\CsrfTokenManager;
use App\Services\Api\ApiRequestLogQueryParser;
use App\Support\QueryString;
use App\Support\SortDirection;
use App\Support\ViewRenderer;

final class ApiRequestLogController
{
    private const FLASH_ERROR = '_flash_api_request_error';

    public function __construct(
        private readonly ApiRequestLogRepositoryInterface $logs,
        private readonly ViewRenderer $views,
        private readonly CsrfTokenManager $csrf,
        private readonly SessionStoreInterface $session,
        private readonly ApiRequestLogQueryParser $queries,
        private readonly string $environment,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $admin = $request->attribute('admin_user');

        if (!$admin instanceof AdminUser) {
            throw new \LogicException('Authenticated administrator is missing from the request.');
        }

        try {
            $query = $this->queries->parse($request);
        } catch (ValidationException $exception) {
            $this->session->put(self::FLASH_ERROR, $exception->getMessage());

            return Response::redirect('/admin/api-requests');
        }

        $page = $this->logs->paginate($query);

        if ($page->pageRequest->page !== $query->pagination->page) {
            return Response::redirect(QueryString::url(
                '/admin/api-requests',
                $query->queryParameters(),
                ['page' => $page->pageRequest->page === 1 ? null : $page->pageRequest->page],
            ));
        }

        $connections = $this->logs->connectionOptions();
        $queryParameters = $query->queryParameters();

        return Response::html($this->views->render('api_requests/index', [
            'title' => 'API Activity',
            'admin' => $admin,
            'csrfToken' => $this->csrf->token(),
            'environment' => $this->environment,
            'currentSection' => 'api_requests',
            'page' => $page,
            'query' => $query,
            'connections' => $connections,
            'activeFilters' => $this->activeFilters($query, $connections),
            'filterError' => $this->session->pull(self::FLASH_ERROR),
            'queryUrl' => static fn (array $overrides = []): string => QueryString::url(
                '/admin/api-requests',
                $queryParameters,
                $overrides,
            ),
            'sortUrl' => static function (ApiRequestLogSort $sort) use ($query, $queryParameters): string {
                $direction = $query->sort === $sort && $query->direction === SortDirection::Descending
                    ? SortDirection::Ascending
                    : SortDirection::Descending;

                return QueryString::url('/admin/api-requests', $queryParameters, [
                    'page' => null,
                    'sort' => $sort === ApiRequestLogSort::Date ? null : $sort->value,
                    'direction' => $direction === SortDirection::Descending ? null : $direction->value,
                ]);
            },
        ], 'layouts/admin'));
    }

    /**
     * @param list<ApiRequestConnectionOption> $connections
     * @return list<string>
     */
    private function activeFilters(ApiRequestLogQuery $query, array $connections): array
    {
        $filters = [];

        if ($query->dateFrom !== null || $query->dateTo !== null) {
            $filters[] = sprintf('Date: %s to %s', $query->dateFrom ?? 'any', $query->dateTo ?? 'any');
        }

        if ($query->apiKeyId !== null) {
            $name = null;

            foreach ($connections as $connection) {
                if ($connection->id === $query->apiKeyId) {
                    $name = $connection->name;
                    break;
                }
            }

            $filters[] = 'Connection: ' . ($name ?? '#' . $query->apiKeyId);
        }

        if ($query->endpoint !== null) {
            $filters[] = 'Endpoint: ' . $query->endpoint;
        }

        if ($query->method !== null) {
            $filters[] = 'Method: ' . $query->method;
        }

        if ($query->statusCode !== null) {
            $filters[] = 'Status: ' . $query->statusCode;
        } elseif ($query->statusGroup !== null) {
            $filters[] = 'Status: ' . match ($query->statusGroup->value) {
                'success' => 'Success',
                'client_error' => 'Client error',
                'server_error' => 'Server error',
            };
        }

        if ($query->minimumDurationMilliseconds !== null || $query->maximumDurationMilliseconds !== null) {
            $filters[] = sprintf(
                'Duration: %s–%s ms',
                $query->minimumDurationMilliseconds ?? 'any',
                $query->maximumDurationMilliseconds ?? 'any',
            );
        }

        if ($query->requestId !== null) {
            $filters[] = 'Request ID: ' . $query->requestId;
        }

        if ($query->authentication->value !== 'all') {
            $filters[] = 'Authentication: ' . ucfirst($query->authentication->value);
        }

        return $filters;
    }
}
