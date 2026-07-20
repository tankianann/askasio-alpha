<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Auth\SessionStoreInterface;
use App\Domain\Admin\AdminUser;
use App\Domain\Chatbots\Chatbot;
use App\Domain\Chatbots\ChatbotListSort;
use App\Domain\Chatbots\ChatbotProviderConfiguration;
use App\Domain\Chatbots\ChatbotStatus;
use App\Domain\Sources\Source;
use App\Exceptions\HttpException;
use App\Exceptions\ChatbotExecutionException;
use App\Exceptions\StaleChatbotDraftException;
use App\Exceptions\ValidationException;
use App\Http\Request;
use App\Http\Response;
use App\Repositories\ChatbotRepositoryInterface;
use App\Repositories\SourceRepositoryInterface;
use App\Security\CsrfTokenManager;
use App\Services\Chatbots\ChatbotAdminFormParser;
use App\Services\Chatbots\ChatbotDraftDefaults;
use App\Services\Chatbots\ChatbotListQueryParser;
use App\Services\Chatbots\ChatbotService;
use App\Services\Chatbots\ChatbotPreviewService;
use App\Services\Sources\SourceListQueryParser;
use App\Support\QueryString;
use App\Support\SortDirection;
use App\Support\ViewRenderer;
use DateTimeImmutable;
use DateTimeZone;
use Ramsey\Uuid\Uuid;

final class ChatbotController
{
    private const FLASH_SUCCESS = '_flash_chatbot_success';
    private const FLASH_ERROR = '_flash_chatbot_error';

    public function __construct(
        private readonly ChatbotRepositoryInterface $chatbots,
        private readonly SourceRepositoryInterface $sources,
        private readonly ChatbotService $service,
        private readonly ChatbotDraftDefaults $defaults,
        private readonly ChatbotAdminFormParser $forms,
        private readonly ChatbotListQueryParser $listQueries,
        private readonly SourceListQueryParser $sourceQueries,
        private readonly ChatbotProviderConfiguration $provider,
        private readonly ViewRenderer $views,
        private readonly CsrfTokenManager $csrf,
        private readonly SessionStoreInterface $session,
        private readonly string $environment,
        private readonly bool $providerConfigured = true,
        private readonly int $maximumTopK = 8,
        private readonly int $maximumMessageCharacters = 4_000,
        private readonly ?ChatbotPreviewService $previewService = null,
        private readonly string $applicationSecret = '',
    ) {
    }

    public function index(Request $request): Response
    {
        try {
            $query = $this->listQueries->parse($request);
        } catch (ValidationException $exception) {
            $this->session->put(self::FLASH_ERROR, $exception->getMessage());

            return Response::redirect('/admin/chatbots');
        }

        $page = $this->chatbots->paginate($query);

        if ($page->pageRequest->page !== $query->pagination->page) {
            return Response::redirect(QueryString::url(
                '/admin/chatbots',
                $query->queryParameters(),
                ['page' => $page->pageRequest->page === 1 ? null : $page->pageRequest->page],
            ));
        }

        $parameters = $query->queryParameters();

        return Response::html($this->views->render('chatbots/index', [
            ...$this->layoutData($request, 'Chatbots'),
            'page' => $page,
            'query' => $query,
            'success' => $this->session->pull(self::FLASH_SUCCESS),
            'error' => $this->session->pull(self::FLASH_ERROR),
            'provider' => $this->provider,
            'providerConfigured' => $this->providerConfigured,
            'queryUrl' => static fn (array $overrides = []): string => QueryString::url(
                '/admin/chatbots',
                $parameters,
                $overrides,
            ),
            'sortUrl' => static function (ChatbotListSort $sort) use ($query, $parameters): string {
                $direction = $query->sort === $sort && $query->direction === SortDirection::Descending
                    ? SortDirection::Ascending
                    : SortDirection::Descending;

                return QueryString::url('/admin/chatbots', $parameters, [
                    'page' => null,
                    'sort' => $sort === ChatbotListSort::Updated ? null : $sort->value,
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
        $name = $this->inputString($request, 'name');
        $description = $this->inputString($request, 'description');

        try {
            $chatbot = $this->service->create($name, $description, $this->defaults->create(trim($name)));
        } catch (ValidationException $exception) {
            return $this->createView($request, $exception->getMessage(), [
                'name' => $name,
                'description' => $description,
            ], 422);
        }

        $this->session->put(self::FLASH_SUCCESS, 'Chatbot draft created. Add sources and an allowed origin before publishing.');

        return Response::redirect('/admin/chatbots/' . $chatbot->id . '/edit', 303);
    }

    public function edit(Request $request): Response
    {
        return $this->editView($request, $this->chatbotFromRequest($request));
    }

    public function preview(Request $request): Response
    {
        $chatbot = $this->chatbotFromRequest($request);

        if ($chatbot->status === ChatbotStatus::Archived) {
            throw new HttpException(404, 'Archived chatbots cannot be previewed.', 'chatbot_not_found');
        }

        $preview = $this->requirePreviewService();
        $state = $preview->current($chatbot);

        return Response::html($this->views->render('chatbots/preview', [
            ...$this->layoutData($request, 'Preview ' . $chatbot->name),
            'chatbot' => $chatbot,
            'state' => $state,
            'providerConfigured' => $this->providerConfigured,
            'success' => $this->session->pull(self::FLASH_SUCCESS),
            'error' => $this->session->pull(self::FLASH_ERROR),
            'idempotencyKey' => bin2hex(random_bytes(16)),
            'requestId' => Uuid::uuid7()->toString(),
        ], 'layouts/admin'));
    }

    public function sendPreviewMessage(Request $request): Response
    {
        $chatbot = $this->chatbotFromRequest($request);

        if (!$this->providerConfigured || $chatbot->status === ChatbotStatus::Archived) {
            $this->session->put(self::FLASH_ERROR, 'Preview is unavailable until the chatbot and installation provider are available.');

            return Response::redirect('/admin/chatbots/' . $chatbot->id . '/preview', 303);
        }

        try {
            $result = $this->requirePreviewService()->send(
                $chatbot,
                $this->inputString($request, 'message'),
                $this->inputString($request, 'idempotency_key'),
                $this->inputString($request, 'request_id'),
                $this->previewSafetyIdentifier($request, $chatbot),
                new DateTimeImmutable('now', new DateTimeZone('UTC')),
            );
            $this->session->put(
                self::FLASH_SUCCESS,
                $result->fallback ? 'Test fallback recorded.' : 'Test response recorded.',
            );
        } catch (ValidationException|ChatbotExecutionException $exception) {
            $this->session->put(self::FLASH_ERROR, $exception->getMessage());
        } catch (\Throwable) {
            $this->session->put(self::FLASH_ERROR, 'The preview message could not be completed.');
        }

        return Response::redirect('/admin/chatbots/' . $chatbot->id . '/preview', 303);
    }

    public function restartPreview(Request $request): Response
    {
        $chatbot = $this->chatbotFromRequest($request);

        try {
            $this->requirePreviewService()->restart(
                $chatbot,
                new DateTimeImmutable('now', new DateTimeZone('UTC')),
            );
            $this->session->put(self::FLASH_SUCCESS, 'A fresh draft test session was started.');
        } catch (ValidationException|ChatbotExecutionException $exception) {
            $this->session->put(self::FLASH_ERROR, $exception->getMessage());
        } catch (\Throwable) {
            $this->session->put(self::FLASH_ERROR, 'A fresh preview session could not be started.');
        }

        return Response::redirect('/admin/chatbots/' . $chatbot->id . '/preview', 303);
    }

    public function update(Request $request): Response
    {
        $chatbot = $this->chatbotFromRequest($request);

        try {
            $revision = $this->forms->expectedRevision($request);
            $updated = $this->service->updateDraft(
                $chatbot->id,
                $revision,
                $this->inputString($request, 'name'),
                $this->inputString($request, 'description'),
                $this->forms->draft($request, $revision),
            );
        } catch (ValidationException|StaleChatbotDraftException $exception) {
            return $this->editView($request, $chatbot, $exception->getMessage(), $this->forms->old($request), 422);
        }

        $this->session->put(self::FLASH_SUCCESS, 'Draft settings saved. Publish when the changes are ready.');

        return Response::redirect('/admin/chatbots/' . $updated->id . '/edit', 303);
    }

    public function updateOrigins(Request $request): Response
    {
        $chatbot = $this->chatbotFromRequest($request);

        try {
            $this->service->updateAssignments(
                $chatbot->id,
                $this->forms->expectedRevision($request),
                $chatbot->assignments->sourceIds,
                $this->forms->origins($request),
            );
            $this->session->put(self::FLASH_SUCCESS, 'Allowed origins saved.');
        } catch (ValidationException|StaleChatbotDraftException $exception) {
            $this->session->put(self::FLASH_ERROR, $exception->getMessage());
        }

        return Response::redirect('/admin/chatbots/' . $chatbot->id . '/edit', 303);
    }

    public function assignSource(Request $request): Response
    {
        return $this->changeSourceAssignment($request, true);
    }

    public function removeSource(Request $request): Response
    {
        return $this->changeSourceAssignment($request, false);
    }

    public function publish(Request $request): Response
    {
        return $this->lifecycle($request, function (Chatbot $chatbot): string {
            $publication = $this->service->publish($chatbot->id);

            return sprintf('Publication %d is now active.', $publication->publicationNumber);
        });
    }

    public function disable(Request $request): Response
    {
        return $this->lifecycle($request, function (Chatbot $chatbot): string {
            $this->service->disable($chatbot->id);

            return 'Chatbot disabled immediately.';
        });
    }

    public function enable(Request $request): Response
    {
        return $this->lifecycle($request, function (Chatbot $chatbot): string {
            $this->service->enable($chatbot->id);

            return 'Chatbot enabled.';
        });
    }

    public function archive(Request $request): Response
    {
        return $this->lifecycle($request, function (Chatbot $chatbot): string {
            $this->service->archive($chatbot->id);

            return 'Chatbot archived. Its active publication is no longer available publicly.';
        });
    }

    public function rotatePublicId(Request $request): Response
    {
        return $this->lifecycle($request, function (Chatbot $chatbot): string {
            $this->service->rotatePublicId($chatbot->id);

            return 'Public ID rotated. Any previous embed reference is now invalid.';
        });
    }

    public function permanentlyDelete(Request $request): Response
    {
        $chatbot = $this->chatbotFromRequest($request);
        $confirmation = $this->inputString($request, 'confirmation');

        if (!hash_equals($chatbot->name, $confirmation)) {
            $this->session->put(self::FLASH_ERROR, 'Type the chatbot name exactly to confirm permanent deletion.');

            return Response::redirect('/admin/chatbots/' . $chatbot->id . '/edit', 303);
        }

        try {
            $this->service->permanentlyDelete($chatbot->id);
        } catch (ValidationException $exception) {
            $this->session->put(self::FLASH_ERROR, $exception->getMessage());

            return Response::redirect('/admin/chatbots/' . $chatbot->id . '/edit', 303);
        }

        $this->session->put(self::FLASH_SUCCESS, sprintf('“%s” was permanently deleted.', $chatbot->name));

        return Response::redirect('/admin/chatbots', 303);
    }

    /** @param callable(Chatbot): string $operation */
    private function lifecycle(Request $request, callable $operation): Response
    {
        $chatbot = $this->chatbotFromRequest($request);

        try {
            $this->session->put(self::FLASH_SUCCESS, $operation($chatbot));
        } catch (ValidationException|StaleChatbotDraftException $exception) {
            $this->session->put(self::FLASH_ERROR, $exception->getMessage());
        }

        return Response::redirect('/admin/chatbots/' . $chatbot->id . '/edit', 303);
    }

    private function changeSourceAssignment(Request $request, bool $assign): Response
    {
        $chatbot = $this->chatbotFromRequest($request);
        $sourceId = $request->route('sourceId');

        if (!is_string($sourceId) || !ctype_digit($sourceId) || (int) $sourceId < 1) {
            throw new HttpException(404, 'The requested source was not found.', 'source_not_found');
        }

        $source = $this->sources->findById((int) $sourceId);

        if (!$source instanceof Source) {
            throw new HttpException(404, 'The requested source was not found.', 'source_not_found');
        }

        $sourceIds = $chatbot->assignments->sourceIds;

        if ($assign) {
            $sourceIds[] = $source->id;
        } else {
            $sourceIds = array_values(array_filter($sourceIds, static fn (int $id): bool => $id !== $source->id));
        }

        try {
            $this->service->updateAssignments(
                $chatbot->id,
                $this->forms->expectedRevision($request),
                $sourceIds,
                $chatbot->assignments->origins,
            );
            $this->session->put(
                self::FLASH_SUCCESS,
                sprintf('“%s” was %s the chatbot draft.', $source->name, $assign ? 'added to' : 'removed from'),
            );
        } catch (ValidationException|StaleChatbotDraftException $exception) {
            $this->session->put(self::FLASH_ERROR, $exception->getMessage());
        }

        return Response::redirect('/admin/chatbots/' . $chatbot->id . '/edit', 303);
    }

    /** @param array<string, mixed> $old */
    private function editView(
        Request $request,
        Chatbot $chatbot,
        ?string $formError = null,
        array $old = [],
        int $status = 200,
    ): Response {
        try {
            $sourceQuery = $this->sourceQueries->parse($request);
        } catch (ValidationException $exception) {
            $this->session->put(self::FLASH_ERROR, $exception->getMessage());

            return Response::redirect('/admin/chatbots/' . $chatbot->id . '/edit');
        }

        $sourcePage = $this->sources->paginate($sourceQuery);

        if ($sourcePage->pageRequest->page !== $sourceQuery->pagination->page) {
            return Response::redirect(QueryString::url(
                '/admin/chatbots/' . $chatbot->id . '/edit',
                $sourceQuery->queryParameters(),
                ['page' => $sourcePage->pageRequest->page === 1 ? null : $sourcePage->pageRequest->page],
            ));
        }

        $assignedSources = [];

        foreach ($chatbot->assignments->sourceIds as $sourceId) {
            $source = $this->sources->findById($sourceId);

            if ($source instanceof Source) {
                $assignedSources[] = $source;
            }
        }

        $readinessIds = array_values(array_unique([
            ...$chatbot->assignments->sourceIds,
            ...array_map(static fn (Source $source): int => $source->id, $sourcePage->items),
        ]));
        $readiness = [];

        foreach ($this->chatbots->sourceReadiness($readinessIds, $this->provider) as $item) {
            $readiness[$item->sourceId] = $item;
        }

        $parameters = $sourceQuery->queryParameters();

        return Response::html($this->views->render('chatbots/edit', [
            ...$this->layoutData($request, $chatbot->name),
            'chatbot' => $chatbot,
            'provider' => $this->provider,
            'providerConfigured' => $this->providerConfigured,
            'maximumTopK' => $this->maximumTopK,
            'maximumMessageCharacters' => $this->maximumMessageCharacters,
            'assignedSources' => $assignedSources,
            'sourcePage' => $sourcePage,
            'sourceQuery' => $sourceQuery,
            'readiness' => $readiness,
            'old' => $old,
            'formError' => $formError,
            'success' => $this->session->pull(self::FLASH_SUCCESS),
            'error' => $this->session->pull(self::FLASH_ERROR),
            'queryUrl' => static fn (array $overrides = []): string => QueryString::url(
                '/admin/chatbots/' . $chatbot->id . '/edit',
                $parameters,
                $overrides,
            ),
        ], 'layouts/admin'), $status);
    }

    /** @param array<string, string> $old */
    private function createView(Request $request, ?string $error = null, array $old = [], int $status = 200): Response
    {
        return Response::html($this->views->render('chatbots/create', [
            ...$this->layoutData($request, 'Create chatbot'),
            'error' => $error,
            'old' => $old,
        ], 'layouts/admin'), $status);
    }

    private function chatbotFromRequest(Request $request): Chatbot
    {
        $id = $request->route('chatbotId');

        if (!is_string($id) || !ctype_digit($id) || (int) $id < 1) {
            throw new HttpException(404, 'The requested chatbot was not found.', 'chatbot_not_found');
        }

        $chatbot = $this->chatbots->findById((int) $id);

        if (!$chatbot instanceof Chatbot) {
            throw new HttpException(404, 'The requested chatbot was not found.', 'chatbot_not_found');
        }

        return $chatbot;
    }

    private function inputString(Request $request, string $field): string
    {
        $value = $request->input($field);

        return is_string($value) ? $value : '';
    }

    private function requirePreviewService(): ChatbotPreviewService
    {
        return $this->previewService
            ?? throw new HttpException(503, 'Chatbot preview is unavailable.', 'preview_unavailable');
    }

    private function previewSafetyIdentifier(Request $request, Chatbot $chatbot): string
    {
        $admin = $request->attribute('admin_user');

        if (!$admin instanceof AdminUser || strlen($this->applicationSecret) < 32) {
            throw new \LogicException('Administrator preview safety identity is unavailable.');
        }

        return hash_hmac('sha256', 'admin:' . $admin->id . ':chatbot:' . $chatbot->id, $this->applicationSecret);
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
            'currentSection' => 'chatbots',
        ];
    }
}
