<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Auth\SessionStoreInterface;
use App\Domain\Admin\AdminUser;
use App\Domain\Sources\Source;
use App\Domain\Sources\SourceListSort;
use App\Domain\Sources\SourceHistoryQuery;
use App\Domain\Sources\SourceVersion;
use App\Domain\Sources\SourceType;
use App\Exceptions\HttpException;
use App\Exceptions\ValidationException;
use App\Http\Request;
use App\Http\Response;
use App\Repositories\SourceRepositoryInterface;
use App\Security\CsrfTokenManager;
use App\Services\Sources\SourceCreationService;
use App\Services\Sources\SourcePermanentDeletionService;
use App\Services\Sources\SourceUpdateService;
use App\Support\ViewRenderer;
use App\Services\Ingestion\IngestionQueue;
use App\Services\Sources\SourceListQueryParser;
use App\Services\Sources\SourceHistoryQueryParser;
use App\Support\QueryString;
use App\Support\SortDirection;
use ValueError;

final class SourceController
{
    private const FLASH_SUCCESS = '_flash_source_success';
    private const FLASH_ERROR = '_flash_source_error';

    public function __construct(
        private readonly SourceRepositoryInterface $sources,
        private readonly SourceCreationService $creation,
        private readonly ViewRenderer $views,
        private readonly CsrfTokenManager $csrf,
        private readonly SessionStoreInterface $session,
        private readonly string $environment,
        private readonly int $maximumUploadMegabytes,
        private readonly IngestionQueue $queue,
        private readonly SourceUpdateService $updates,
        private readonly SourcePermanentDeletionService $deletion,
        private readonly SourceListQueryParser $listQueries,
        private readonly SourceHistoryQueryParser $historyQueries,
    ) {
    }

    public function index(Request $request): Response
    {
        try {
            $query = $this->listQueries->parse($request);
        } catch (ValidationException $exception) {
            $this->session->put(self::FLASH_ERROR, $exception->getMessage());

            return Response::redirect('/admin/sources');
        }

        $page = $this->sources->paginate($query);

        if ($page->pageRequest->page !== $query->pagination->page) {
            return Response::redirect(QueryString::url(
                '/admin/sources',
                $query->queryParameters(),
                ['page' => $page->pageRequest->page === 1 ? null : $page->pageRequest->page],
            ));
        }

        $parameters = $query->queryParameters();

        return Response::html($this->views->render('sources/index', [
            ...$this->layoutData($request, 'Knowledge Base'),
            'page' => $page,
            'query' => $query,
            'success' => $this->session->pull(self::FLASH_SUCCESS),
            'error' => $this->session->pull(self::FLASH_ERROR),
            'queryUrl' => static fn (array $overrides = []): string => QueryString::url(
                '/admin/sources',
                $parameters,
                $overrides,
            ),
            'sortUrl' => static function (SourceListSort $sort) use ($query, $parameters): string {
                $direction = $query->sort === $sort && $query->direction === SortDirection::Descending
                    ? SortDirection::Ascending
                    : SortDirection::Descending;

                return QueryString::url('/admin/sources', $parameters, [
                    'page' => null,
                    'sort' => $sort === SourceListSort::Updated ? null : $sort->value,
                    'direction' => $direction === SortDirection::Descending ? null : $direction->value,
                ]);
            },
        ], 'layouts/admin'));
    }

    public function create(Request $request): Response
    {
        return $this->createView($request);
    }

    public function store(Request $request): Response
    {
        $name = $request->input('name');
        $url = $request->input('url');
        $typeValue = $request->input('source_type');
        $name = is_string($name) ? $name : '';
        $url = is_string($url) ? $url : '';
        $typeValue = is_string($typeValue) ? $typeValue : '';

        try {
            $type = SourceType::from($typeValue);

            if ($type === SourceType::Url) {
                $source = $this->creation->createUrl($name, $url);
            } else {
                $file = $request->file($type === SourceType::Markdown ? 'markdown_file' : 'pdf_file');

                if ($file === null) {
                    throw new ValidationException('Choose a file to upload.');
                }

                $source = $this->creation->createUpload($name, $type, $file);
            }
        } catch (ValueError) {
            return $this->createView($request, 'Choose a supported source type.', [
                'name' => $name,
                'url' => $url,
                'source_type' => $typeValue,
            ], 422);
        } catch (ValidationException $exception) {
            return $this->createView($request, $exception->getMessage(), [
                'name' => $name,
                'url' => $url,
                'source_type' => $typeValue,
            ], 422);
        }

        $this->session->put(self::FLASH_SUCCESS, sprintf('“%s” was added and is ready for processing.', $source->name));

        return Response::redirect('/admin/sources/' . $source->id, 303);
    }

    public function bulkMarkdown(Request $request): Response
    {
        return Response::html($this->views->render('sources/bulk_markdown', [
            ...$this->layoutData($request, 'Bulk upload Markdown'),
            'maximumUploadMegabytes' => $this->maximumUploadMegabytes,
        ], 'layouts/admin'));
    }

    public function storeBulkMarkdown(Request $request): Response
    {
        $file = $request->file('markdown_file');

        if ($file === null) {
            return Response::json([
                'error' => [
                    'code' => 'validation_error',
                    'message' => 'Choose a Markdown file to upload.',
                ],
            ], 422);
        }

        try {
            $source = $this->creation->createMarkdownFromFrontMatter($file);
        } catch (ValidationException $exception) {
            return Response::json([
                'error' => [
                    'code' => 'validation_error',
                    'message' => $exception->getMessage(),
                ],
            ], 422);
        }

        return Response::json([
            'source' => [
                'id' => $source->id,
                'name' => $source->name,
                'url' => '/admin/sources/' . $source->id,
            ],
        ], 201);
    }

    public function show(Request $request): Response
    {
        $source = $this->sourceFromRequest($request);

        try {
            $query = $this->historyQueries->parse($request);
        } catch (ValidationException $exception) {
            $this->session->put(self::FLASH_ERROR, $exception->getMessage());

            return Response::redirect('/admin/sources/' . $source->id);
        }

        $versionsPage = $this->sources->paginateVersionsForSource($source->id, $query->revisions);
        $jobsPage = $this->queue->paginateForSource($source->id, $query->jobs);

        if ($versionsPage->pageRequest->page !== $query->revisions->page
            || $jobsPage->pageRequest->page !== $query->jobs->page) {
            $canonical = new SourceHistoryQuery($versionsPage->pageRequest, $jobsPage->pageRequest);

            return Response::redirect(QueryString::url(
                '/admin/sources/' . $source->id,
                $canonical->queryParameters(),
            ));
        }

        $parameters = $query->queryParameters();

        return Response::html($this->views->render('sources/show', [
            ...$this->layoutData($request, $source->name),
            'source' => $source,
            'versionsPage' => $versionsPage,
            'jobsPage' => $jobsPage,
            'historyUrl' => static fn (array $overrides = []): string => QueryString::url(
                '/admin/sources/' . $source->id,
                $parameters,
                $overrides,
            ),
            'versionUrl' => static fn (int $versionId): string => QueryString::url(
                '/admin/sources/' . $source->id . '/versions/' . $versionId,
                $parameters,
            ),
            'success' => $this->session->pull(self::FLASH_SUCCESS),
            'error' => $this->session->pull(self::FLASH_ERROR),
        ], 'layouts/admin'));
    }

    public function version(Request $request): Response
    {
        $source = $this->sourceFromRequest($request);
        $version = $this->versionFromRequest($request, $source);

        try {
            $historyQuery = $this->historyQueries->parse($request);
        } catch (ValidationException) {
            $historyQuery = $this->historyQueries->parse(new Request('GET', '/'));
        }

        return Response::html($this->views->render('sources/version', [
            ...$this->layoutData($request, sprintf('%s revision %d', $source->name, $version->versionNumber)),
            'source' => $source,
            'version' => $version,
            'backUrl' => QueryString::url(
                '/admin/sources/' . $source->id,
                $historyQuery->queryParameters(),
            ),
        ], 'layouts/admin'));
    }

    public function replace(Request $request): Response
    {
        $source = $this->sourceFromRequest($request);

        return $this->replacementView($request, $source);
    }

    public function storeReplacement(Request $request): Response
    {
        $source = $this->sourceFromRequest($request);
        $file = $request->file('replacement_file');

        if ($file === null) {
            return $this->replacementView($request, $source, 'Choose a replacement file.', 422);
        }

        try {
            $version = $this->updates->replaceUpload($source, $file);
        } catch (ValidationException $exception) {
            return $this->replacementView($request, $source, $exception->getMessage(), 422);
        }

        $this->session->put(
            self::FLASH_SUCCESS,
            sprintf('Replacement revision %d is ready for processing.', $version->versionNumber),
        );

        return Response::redirect('/admin/sources/' . $source->id, 303);
    }

    public function refresh(Request $request): Response
    {
        $source = $this->sourceFromRequest($request);

        try {
            $version = $this->updates->refreshUrl($source);
            $this->session->put(
                self::FLASH_SUCCESS,
                sprintf('URL refresh revision %d is ready for processing.', $version->versionNumber),
            );
        } catch (ValidationException $exception) {
            $this->session->put(self::FLASH_ERROR, $exception->getMessage());
        }

        return Response::redirect('/admin/sources/' . $source->id, 303);
    }

    public function reprocess(Request $request): Response
    {
        $source = $this->sourceFromRequest($request);
        $version = $this->versionFromRequest($request, $source);

        try {
            $copy = $this->updates->reprocess($source, $version);
            $this->session->put(
                self::FLASH_SUCCESS,
                sprintf(
                    'Revision %d was copied to revision %d and is ready for reprocessing.',
                    $version->versionNumber,
                    $copy->versionNumber,
                ),
            );
        } catch (ValidationException $exception) {
            $this->session->put(self::FLASH_ERROR, $exception->getMessage());
        }

        return Response::redirect('/admin/sources/' . $source->id, 303);
    }

    public function disable(Request $request): Response
    {
        $source = $this->sourceFromRequest($request);
        $this->sources->disable($source->id);
        $this->session->put(self::FLASH_SUCCESS, 'Document disabled. Ask Asio will exclude it from answers.');

        return Response::redirect('/admin/sources/' . $source->id, 303);
    }

    public function enable(Request $request): Response
    {
        $source = $this->sourceFromRequest($request);

        if ($source->isDeleted()) {
            throw new HttpException(409, 'A deleted source cannot be enabled.', 'source_deleted');
        }

        $this->sources->enable($source->id);
        $this->session->put(self::FLASH_SUCCESS, 'Document enabled.');

        return Response::redirect('/admin/sources/' . $source->id, 303);
    }

    public function delete(Request $request): Response
    {
        $source = $this->sourceFromRequest($request);
        $this->sources->softDelete($source->id);
        $this->session->put(self::FLASH_SUCCESS, sprintf('“%s” was moved to deleted items.', $source->name));

        return Response::redirect('/admin/sources', 303);
    }

    public function permanentDelete(Request $request): Response
    {
        $source = $this->sourceFromRequest($request);
        $confirmation = $request->input('confirmation');

        try {
            $this->deletion->delete($source, is_string($confirmation) ? $confirmation : '');
        } catch (ValidationException $exception) {
            $this->session->put(self::FLASH_ERROR, $exception->getMessage());

            return Response::redirect('/admin/sources/' . $source->id, 303);
        }

        $this->session->put(self::FLASH_SUCCESS, sprintf('“%s” was permanently deleted.', $source->name));

        return Response::redirect('/admin/sources', 303);
    }

    /** @param array<string, string> $old */
    private function createView(
        Request $request,
        ?string $error = null,
        array $old = [],
        int $status = 200,
    ): Response {
        return Response::html($this->views->render('sources/create', [
            ...$this->layoutData($request, 'Add knowledge'),
            'error' => $error,
            'old' => $old,
            'maximumUploadMegabytes' => $this->maximumUploadMegabytes,
        ], 'layouts/admin'), $status);
    }

    private function replacementView(
        Request $request,
        Source $source,
        ?string $error = null,
        int $status = 200,
    ): Response {
        if ($source->type === SourceType::Url) {
            throw new HttpException(409, 'URL sources are refreshed rather than replaced with files.', 'invalid_source_operation');
        }

        if ($source->isDeleted()) {
            throw new HttpException(409, 'A deleted source cannot receive a replacement.', 'source_deleted');
        }

        return Response::html($this->views->render('sources/replace', [
            ...$this->layoutData($request, 'Replace ' . $source->name),
            'source' => $source,
            'error' => $error,
            'maximumUploadMegabytes' => $this->maximumUploadMegabytes,
        ], 'layouts/admin'), $status);
    }

    private function versionFromRequest(Request $request, Source $source): SourceVersion
    {
        $versionId = $request->route('versionId');

        if (!is_string($versionId) || !ctype_digit($versionId) || (int) $versionId < 1) {
            throw new HttpException(404, 'The requested source version was not found.', 'source_version_not_found');
        }

        $version = $this->sources->findVersionById((int) $versionId);

        if (!$version instanceof SourceVersion || $version->sourceId !== $source->id) {
            throw new HttpException(404, 'The requested source version was not found.', 'source_version_not_found');
        }

        return $version;
    }

    private function sourceFromRequest(Request $request): Source
    {
        $id = $request->route('sourceId');

        if (!is_string($id) || !ctype_digit($id) || (int) $id < 1) {
            throw new HttpException(404, 'The requested source was not found.', 'source_not_found');
        }

        $source = $this->sources->findById((int) $id);

        if (!$source instanceof Source) {
            throw new HttpException(404, 'The requested source was not found.', 'source_not_found');
        }

        return $source;
    }

    /** @return array<string, mixed> */
    private function layoutData(Request $request, string $title): array
    {
        $admin = $request->attribute('admin_user');

        if (!$admin instanceof AdminUser) {
            throw new \LogicException('Authenticated administrator is missing from the request.');
        }

        return [
            'title' => $title,
            'admin' => $admin,
            'csrfToken' => $this->csrf->token(),
            'environment' => $this->environment,
            'currentSection' => 'sources',
        ];
    }
}
