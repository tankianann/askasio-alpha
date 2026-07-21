<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Auth\SessionStoreInterface;
use App\Domain\Admin\AdminUser;
use App\Domain\ApiKeys\ApiKey;
use App\Domain\ApiKeys\ApiKeyListSort;
use App\Exceptions\HttpException;
use App\Exceptions\ValidationException;
use App\Http\Request;
use App\Http\Response;
use App\Repositories\ApiKeyRepositoryInterface;
use App\Security\CsrfTokenManager;
use App\Services\ApiKeys\ApiKeyService;
use App\Services\ApiKeys\ApiKeyListQueryParser;
use App\Services\ProviderQuota\ProviderQuotaService;
use App\Support\QueryString;
use App\Support\SortDirection;
use App\Support\ViewRenderer;
use DateTimeImmutable;
use DateTimeZone;

final class ApiKeyController
{
    private const FLASH_SUCCESS = '_flash_api_key_success';
    private const FLASH_ERROR = '_flash_api_key_error';

    public function __construct(
        private readonly ApiKeyRepositoryInterface $keys,
        private readonly ApiKeyService $service,
        private readonly ViewRenderer $views,
        private readonly CsrfTokenManager $csrf,
        private readonly SessionStoreInterface $session,
        private readonly string $environment,
        private readonly string $timezone,
        private readonly ApiKeyListQueryParser $listQueries,
        private readonly ?ProviderQuotaService $providerQuotas = null,
    ) {
    }

    public function index(Request $request): Response
    {
        try {
            $query = $this->listQueries->parse($request);
        } catch (ValidationException $exception) {
            $this->session->put(self::FLASH_ERROR, $exception->getMessage());

            return Response::redirect('/admin/api-keys');
        }

        $page = $this->keys->paginate($query);

        if ($page->pageRequest->page !== $query->pagination->page) {
            return Response::redirect(QueryString::url(
                '/admin/api-keys',
                $query->queryParameters(),
                ['page' => $page->pageRequest->page === 1 ? null : $page->pageRequest->page],
            ));
        }

        $parameters = $query->queryParameters();
        $quotaSnapshots = $this->providerQuotas?->dashboardSnapshots(array_map(
            static fn (ApiKey $key): int => $key->id,
            $page->items,
        ));

        return Response::html($this->views->render('api_keys/index', [
            ...$this->layoutData($request, 'API Access'),
            'page' => $page,
            'query' => $query,
            'success' => $this->session->pull(self::FLASH_SUCCESS),
            'filterError' => $this->session->pull(self::FLASH_ERROR),
            'globalQuota' => $quotaSnapshots['global'] ?? null,
            'apiKeyQuotas' => $quotaSnapshots['api_keys'] ?? [],
            'apiKeysTotalQuota' => $quotaSnapshots['api_keys_total'] ?? null,
            'chatbotQuota' => $quotaSnapshots['chatbots'] ?? null,
            'queryUrl' => static fn (array $overrides = []): string => QueryString::url(
                '/admin/api-keys',
                $parameters,
                $overrides,
            ),
            'sortUrl' => static function (ApiKeyListSort $sort) use ($query, $parameters): string {
                $direction = $query->sort === $sort && $query->direction === SortDirection::Descending
                    ? SortDirection::Ascending
                    : SortDirection::Descending;

                return QueryString::url('/admin/api-keys', $parameters, [
                    'page' => null,
                    'sort' => $sort === ApiKeyListSort::Created ? null : $sort->value,
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
        $admin = $this->admin($request);
        $name = $request->input('name');
        $expiryInput = $request->input('expires_at');
        $name = is_string($name) ? $name : '';
        $expiryInput = is_string($expiryInput) ? trim($expiryInput) : '';

        try {
            $expiresAt = $this->parseExpiry($expiryInput);
            $created = $this->service->create($admin->id, $name, $expiresAt);
        } catch (ValidationException $exception) {
            return $this->createView($request, $exception->getMessage(), [
                'name' => $name,
                'expires_at' => $expiryInput,
            ], 422);
        }

        return Response::html($this->views->render('api_keys/created', [
            ...$this->layoutData($request, 'Connection created'),
            'created' => $created,
        ], 'layouts/admin'), 201)->withHeader('Cache-Control', 'no-store');
    }

    public function revoke(Request $request): Response
    {
        $apiKey = $this->keyFromRequest($request);
        $this->keys->revoke($apiKey->id);
        $this->session->put(self::FLASH_SUCCESS, sprintf('API key “%s” was revoked.', $apiKey->name));

        return Response::redirect('/admin/api-keys', 303);
    }

    public function delete(Request $request): Response
    {
        $apiKey = $this->keyFromRequest($request);
        $this->keys->delete($apiKey->id);
        $this->session->put(self::FLASH_SUCCESS, sprintf('API key “%s” was permanently deleted.', $apiKey->name));

        return Response::redirect('/admin/api-keys', 303);
    }

    /** @param array<string, string> $old */
    private function createView(
        Request $request,
        ?string $error = null,
        array $old = [],
        int $status = 200,
    ): Response {
        return Response::html($this->views->render('api_keys/create', [
            ...$this->layoutData($request, 'Create connection'),
            'error' => $error,
            'old' => $old,
        ], 'layouts/admin'), $status);
    }

    private function parseExpiry(string $value): ?string
    {
        if ($value === '') {
            return null;
        }

        $timezone = new DateTimeZone($this->timezone);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value, $timezone);
        $errors = DateTimeImmutable::getLastErrors();

        if (!$date instanceof DateTimeImmutable
            || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw new ValidationException('Choose a valid API key expiry date and time.');
        }

        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private function keyFromRequest(Request $request): ApiKey
    {
        $id = $request->route('apiKeyId');

        if (!is_string($id) || !ctype_digit($id) || (int) $id < 1) {
            throw new HttpException(404, 'The requested API key was not found.', 'api_key_not_found');
        }

        $apiKey = $this->keys->findById((int) $id);

        if (!$apiKey instanceof ApiKey) {
            throw new HttpException(404, 'The requested API key was not found.', 'api_key_not_found');
        }

        return $apiKey;
    }

    private function admin(Request $request): AdminUser
    {
        $admin = $request->attribute('admin_user');

        if (!$admin instanceof AdminUser) {
            throw new \LogicException('Authenticated administrator is missing from the request.');
        }

        return $admin;
    }

    /** @return array<string, mixed> */
    private function layoutData(Request $request, string $title): array
    {
        return [
            'title' => $title,
            'admin' => $this->admin($request),
            'csrfToken' => $this->csrf->token(),
            'environment' => $this->environment,
            'currentSection' => 'api_keys',
        ];
    }
}
