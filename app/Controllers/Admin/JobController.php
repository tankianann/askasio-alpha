<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Auth\SessionStoreInterface;
use App\Domain\Admin\AdminUser;
use App\Domain\Ingestion\IngestionJobListSort;
use App\Exceptions\ValidationException;
use App\Http\Request;
use App\Http\Response;
use App\Security\CsrfTokenManager;
use App\Services\Ingestion\IngestionQueue;
use App\Services\Ingestion\IngestionJobListQueryParser;
use App\Support\QueryString;
use App\Support\SortDirection;
use App\Support\ViewRenderer;

final class JobController
{
    private const FLASH_ERROR = '_flash_job_list_error';

    public function __construct(
        private readonly IngestionQueue $queue,
        private readonly ViewRenderer $views,
        private readonly CsrfTokenManager $csrf,
        private readonly string $environment,
        private readonly bool $pipelineAvailable,
        private readonly SessionStoreInterface $session,
        private readonly IngestionJobListQueryParser $listQueries,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $admin = $request->attribute('admin_user');

        if (!$admin instanceof AdminUser) {
            throw new \LogicException('Authenticated administrator is missing from the request.');
        }

        try {
            $query = $this->listQueries->parse($request);
        } catch (ValidationException $exception) {
            $this->session->put(self::FLASH_ERROR, $exception->getMessage());

            return Response::redirect('/admin/jobs');
        }

        $page = $this->queue->paginate($query);

        if ($page->pageRequest->page !== $query->pagination->page) {
            return Response::redirect(QueryString::url(
                '/admin/jobs',
                $query->queryParameters(),
                ['page' => $page->pageRequest->page === 1 ? null : $page->pageRequest->page],
            ));
        }

        $parameters = $query->queryParameters();

        return Response::html($this->views->render('jobs/index', [
            'title' => 'Processing',
            'admin' => $admin,
            'csrfToken' => $this->csrf->token(),
            'environment' => $this->environment,
            'currentSection' => 'jobs',
            'page' => $page,
            'query' => $query,
            'sources' => $this->queue->sourceOptions(),
            'counts' => $this->queue->counts(),
            'pipelineAvailable' => $this->pipelineAvailable,
            'filterError' => $this->session->pull(self::FLASH_ERROR),
            'queryUrl' => static fn (array $overrides = []): string => QueryString::url(
                '/admin/jobs',
                $parameters,
                $overrides,
            ),
            'sortUrl' => static function (IngestionJobListSort $sort) use ($query, $parameters): string {
                $direction = $query->sort === $sort && $query->direction === SortDirection::Descending
                    ? SortDirection::Ascending
                    : SortDirection::Descending;

                return QueryString::url('/admin/jobs', $parameters, [
                    'page' => null,
                    'sort' => $sort === IngestionJobListSort::Date ? null : $sort->value,
                    'direction' => $direction === SortDirection::Descending ? null : $direction->value,
                ]);
            },
        ], 'layouts/admin'));
    }
}
