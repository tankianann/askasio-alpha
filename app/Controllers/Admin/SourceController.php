<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Auth\SessionStoreInterface;
use App\Domain\Admin\AdminUser;
use App\Domain\Sources\Source;
use App\Domain\Sources\SourceType;
use App\Exceptions\HttpException;
use App\Exceptions\ValidationException;
use App\Http\Request;
use App\Http\Response;
use App\Repositories\SourceRepositoryInterface;
use App\Security\CsrfTokenManager;
use App\Services\Sources\SourceCreationService;
use App\Support\ViewRenderer;
use ValueError;

final class SourceController
{
    private const FLASH_SUCCESS = '_flash_source_success';

    public function __construct(
        private readonly SourceRepositoryInterface $sources,
        private readonly SourceCreationService $creation,
        private readonly ViewRenderer $views,
        private readonly CsrfTokenManager $csrf,
        private readonly SessionStoreInterface $session,
        private readonly string $environment,
        private readonly int $maximumUploadMegabytes,
    ) {
    }

    public function index(Request $request): Response
    {
        return Response::html($this->views->render('sources/index', [
            ...$this->layoutData($request, 'Sources'),
            'sources' => $this->sources->all(),
            'success' => $this->session->pull(self::FLASH_SUCCESS),
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

        $this->session->put(self::FLASH_SUCCESS, sprintf('Source “%s” was added and is pending ingestion.', $source->name));

        return Response::redirect('/admin/sources/' . $source->id, 303);
    }

    public function show(Request $request): Response
    {
        $source = $this->sourceFromRequest($request);

        return Response::html($this->views->render('sources/show', [
            ...$this->layoutData($request, $source->name),
            'source' => $source,
            'versions' => $this->sources->versionsForSource($source->id),
            'success' => $this->session->pull(self::FLASH_SUCCESS),
        ], 'layouts/admin'));
    }

    public function disable(Request $request): Response
    {
        $source = $this->sourceFromRequest($request);
        $this->sources->disable($source->id);
        $this->session->put(self::FLASH_SUCCESS, 'Source disabled. It will be excluded from retrieval.');

        return Response::redirect('/admin/sources/' . $source->id, 303);
    }

    public function enable(Request $request): Response
    {
        $source = $this->sourceFromRequest($request);

        if ($source->isDeleted()) {
            throw new HttpException(409, 'A deleted source cannot be enabled.', 'source_deleted');
        }

        $this->sources->enable($source->id);
        $this->session->put(self::FLASH_SUCCESS, 'Source enabled.');

        return Response::redirect('/admin/sources/' . $source->id, 303);
    }

    public function delete(Request $request): Response
    {
        $source = $this->sourceFromRequest($request);
        $this->sources->softDelete($source->id);
        $this->session->put(self::FLASH_SUCCESS, sprintf('Source “%s” was moved to deleted sources.', $source->name));

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
            ...$this->layoutData($request, 'Add source'),
            'error' => $error,
            'old' => $old,
            'maximumUploadMegabytes' => $this->maximumUploadMegabytes,
        ], 'layouts/admin'), $status);
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
