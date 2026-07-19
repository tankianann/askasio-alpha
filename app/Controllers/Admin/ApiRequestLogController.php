<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Auth\SessionStoreInterface;
use App\Domain\Admin\AdminUser;
use App\Domain\Api\ApiRequestConnectionOption;
use App\Domain\Api\ApiRequestLogPurgeCriteria;
use App\Domain\Api\ApiRequestLogPurgeIntent;
use App\Domain\Api\ApiRequestLogPurgeScope;
use App\Domain\Api\ApiRequestLogQuery;
use App\Domain\Api\ApiRequestLogSort;
use App\Exceptions\ValidationException;
use App\Http\Request;
use App\Http\Response;
use App\Repositories\ApiRequestLogRepositoryInterface;
use App\Security\CsrfTokenManager;
use App\Services\Api\ApiRequestLogQueryParser;
use App\Services\Api\ApiRequestLogPurgeIntentStore;
use App\Services\Api\ApiRequestLogPurgeRequestParser;
use App\Services\Api\ApiRequestLogPurgeService;
use App\Support\QueryString;
use App\Support\SortDirection;
use App\Support\ViewRenderer;

final class ApiRequestLogController
{
    private const FLASH_ERROR = '_flash_api_request_error';
    private const FLASH_SUCCESS = '_flash_api_request_success';

    public function __construct(
        private readonly ApiRequestLogRepositoryInterface $logs,
        private readonly ViewRenderer $views,
        private readonly CsrfTokenManager $csrf,
        private readonly SessionStoreInterface $session,
        private readonly ApiRequestLogQueryParser $queries,
        private readonly ApiRequestLogPurgeRequestParser $purgeRequests,
        private readonly ApiRequestLogPurgeService $purges,
        private readonly ApiRequestLogPurgeIntentStore $purgeIntents,
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
            'success' => $this->session->pull(self::FLASH_SUCCESS),
            'purgeUrl' => QueryString::url('/admin/api-requests/purge', $query->filterParameters()),
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

    public function purge(Request $request): Response
    {
        $admin = $this->admin($request);

        try {
            $query = $this->queries->parse($request);
        } catch (ValidationException $exception) {
            $this->session->put(self::FLASH_ERROR, $exception->getMessage());

            return Response::redirect('/admin/api-requests');
        }

        return $this->purgeView($admin, $query);
    }

    public function previewPurge(Request $request): Response
    {
        $admin = $this->admin($request);

        try {
            $query = $this->queries->parse($request);
            $criteria = $this->purgeRequests->parse($request, $query);
            $intent = $this->purgeIntents->create($this->purges->preview($criteria));
        } catch (ValidationException $exception) {
            if (!isset($query) || !$query instanceof ApiRequestLogQuery) {
                $this->session->put(self::FLASH_ERROR, $exception->getMessage());

                return Response::redirect('/admin/api-requests', 303);
            }

            return $this->purgeView($admin, $query, $exception->getMessage(), null, $request, 422);
        }

        return $this->purgeView($admin, $query, null, $intent, $request);
    }

    public function executePurge(Request $request): Response
    {
        $admin = $this->admin($request);
        $token = $request->input('purge_token');
        $confirmation = $request->input('confirmation');

        try {
            if (!is_string($token)) {
                throw new ValidationException('This purge confirmation is invalid. Review the purge scope again.');
            }

            $intent = $this->purgeIntents->require($token);

            if ($intent->snapshot->recordCount === 0) {
                throw new ValidationException('There are no records in this purge scope.');
            }

            if (!is_string($confirmation)
                || !hash_equals($intent->confirmationPhrase(), trim($confirmation))) {
                throw new ValidationException('Type the confirmation phrase exactly before purging API Activity.');
            }

            $result = $this->purges->purge($intent->snapshot, $admin->id);

            if (!$result->lockAcquired) {
                throw new ValidationException('Another API Activity purge is running. Wait for it to finish and review the scope again.');
            }
        } catch (ValidationException $exception) {
            $this->purgeIntents->forget();
            $this->session->put(self::FLASH_ERROR, $exception->getMessage());

            return Response::redirect('/admin/api-requests/purge', 303);
        }

        $this->purgeIntents->forget();
        $this->session->put(self::FLASH_SUCCESS, sprintf(
            '%d API Activity %s permanently deleted.',
            $result->deletedRecords,
            $result->deletedRecords === 1 ? 'record was' : 'records were',
        ));

        return Response::redirect('/admin/api-requests', 303);
    }

    private function purgeView(
        AdminUser $admin,
        ApiRequestLogQuery $query,
        ?string $error = null,
        ?ApiRequestLogPurgeIntent $intent = null,
        ?Request $submitted = null,
        int $status = 200,
    ): Response {
        $connections = $this->logs->connectionOptions();
        $activeFilters = $this->activeFilters($query, $connections);
        $filterParameters = $query->filterParameters();

        return Response::html($this->views->render('api_requests/purge', [
            'title' => 'Purge API Activity',
            'admin' => $admin,
            'csrfToken' => $this->csrf->token(),
            'environment' => $this->environment,
            'currentSection' => 'api_requests',
            'query' => $query,
            'activeFilters' => $activeFilters,
            'error' => $error ?? $this->session->pull(self::FLASH_ERROR),
            'intent' => $intent,
            'scopeDescription' => $intent instanceof ApiRequestLogPurgeIntent
                ? $this->purgeScopeDescription($intent->snapshot->criteria, $activeFilters)
                : null,
            'selectedScope' => $this->submittedString($submitted, 'scope', 'older_than_age'),
            'selectedDate' => $this->submittedString($submitted, 'before_date', ''),
            'selectedAge' => $this->submittedString($submitted, 'age_days', '90'),
            'previewUrl' => QueryString::url('/admin/api-requests/purge/preview', $filterParameters),
            'backUrl' => QueryString::url('/admin/api-requests', $filterParameters),
        ], 'layouts/admin'), $status);
    }

    /** @param list<string> $activeFilters */
    private function purgeScopeDescription(ApiRequestLogPurgeCriteria $criteria, array $activeFilters): string
    {
        return match ($criteria->scope) {
            ApiRequestLogPurgeScope::MatchingFilters => sprintf(
                'Requests matching %d active %s.',
                count($activeFilters),
                count($activeFilters) === 1 ? 'filter' : 'filters',
            ),
            ApiRequestLogPurgeScope::BeforeDate => sprintf(
                'Requests created before %s UTC.',
                $criteria->cutoffUtc,
            ),
            ApiRequestLogPurgeScope::OlderThanAge => sprintf(
                'Requests older than the selected age, using the UTC cutoff %s.',
                $criteria->cutoffUtc,
            ),
            ApiRequestLogPurgeScope::All => 'Every API Activity record currently in the reviewed snapshot.',
        };
    }

    private function admin(Request $request): AdminUser
    {
        $admin = $request->attribute('admin_user');

        if (!$admin instanceof AdminUser) {
            throw new \LogicException('Authenticated administrator is missing from the request.');
        }

        return $admin;
    }

    private function submittedString(?Request $request, string $key, string $default): string
    {
        $value = $request?->input($key);

        return is_string($value) ? $value : $default;
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
