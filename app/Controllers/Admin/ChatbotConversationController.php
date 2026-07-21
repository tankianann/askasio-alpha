<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Auth\SessionStoreInterface;
use App\Domain\Admin\AdminUser;
use App\Domain\Chatbots\ChatbotConversationListQuery;
use App\Domain\Chatbots\ChatbotConversationPurgeSnapshot;
use App\Exceptions\HttpException;
use App\Exceptions\ValidationException;
use App\Http\Request;
use App\Http\Response;
use App\Repositories\ChatbotConversationRepositoryInterface;
use App\Repositories\ChatbotRepositoryInterface;
use App\Security\CsrfTokenManager;
use App\Services\Chatbots\ChatbotConversationListQueryParser;
use App\Services\Chatbots\ChatbotConversationRetentionService;
use App\Support\QueryString;
use App\Support\ViewRenderer;
use DateTimeImmutable;
use DateTimeZone;

final readonly class ChatbotConversationController
{
    private const INTENT = '_chatbot_conversation_purge_intent';
    private const FLASH = '_flash_chatbot_conversation';

    public function __construct(
        private ChatbotConversationRepositoryInterface $conversations,
        private ChatbotRepositoryInterface $chatbots,
        private ChatbotConversationListQueryParser $queries,
        private ChatbotConversationRetentionService $retention,
        private ViewRenderer $views,
        private CsrfTokenManager $csrf,
        private SessionStoreInterface $session,
        private string $environment,
    ) {
    }

    public function index(Request $request): Response
    {
        try { $query = $this->queries->parse($request); }
        catch (ValidationException $e) { $this->session->put(self::FLASH, $e->getMessage()); return Response::redirect('/admin/conversations'); }
        $page = $this->conversations->paginateSessions($query);
        if ($page->pageRequest->page !== $query->pagination->page) {
            return Response::redirect(QueryString::url('/admin/conversations', $query->queryParameters(), ['page' => $page->pageRequest->page === 1 ? null : $page->pageRequest->page]));
        }
        return Response::html($this->views->render('conversations/index', [
            ...$this->layout($request, 'Conversations'), 'page' => $page, 'query' => $query,
            'chatbots' => $this->chatbots->listOptions(), 'message' => $this->session->pull(self::FLASH),
            'queryUrl' => static fn (array $overrides = []): string => QueryString::url('/admin/conversations', $query->queryParameters(), $overrides),
        ], 'layouts/admin'));
    }

    public function show(Request $request): Response
    {
        $publicId = (string) $request->route('sessionId');
        $conversation = $this->conversations->findSessionByPublicId($publicId);
        if ($conversation === null) { throw new HttpException(404, 'The conversation was not found.', 'conversation_not_found'); }
        $chatbot = $this->chatbots->findById($conversation->chatbotId);
        return Response::html($this->views->render('conversations/show', [
            ...$this->layout($request, 'Conversation'), 'conversation' => $conversation,
            'chatbot' => $chatbot, 'messages' => $this->conversations->messagesForSession($conversation->id),
        ], 'layouts/admin'));
    }

    public function previewPurge(Request $request): Response
    {
        $parameters = [];
        foreach (['search', 'chatbot_id', 'status', 'traffic', 'date_from', 'date_to', 'sort', 'direction', 'per_page'] as $key) {
            $value = $request->input($key);
            if (is_string($value) && $value !== '') { $parameters[$key] = $value; }
        }
        $query = $this->queries->parse(new Request('GET', '/admin/conversations', query: $parameters));
        $snapshot = $this->conversations->purgeSnapshot($query, $this->now());
        $token = bin2hex(random_bytes(32));
        $this->session->put(self::INTENT, [
            'token' => $token, 'created_at' => time(), 'count' => $snapshot->recordCount,
            'maximum_id' => $snapshot->maximumId, 'query' => $query->queryParameters(),
            'eligible_before' => $snapshot->eligibleBefore,
        ]);
        return Response::html($this->views->render('conversations/purge', [
            ...$this->layout($request, 'Purge conversations'), 'snapshot' => $snapshot,
            'token' => $token, 'confirmationPhrase' => 'PURGE ' . $snapshot->recordCount . ' CONVERSATIONS',
        ], 'layouts/admin'))->withHeader('Cache-Control', 'no-store');
    }

    public function executePurge(Request $request): Response
    {
        $stored = $this->session->get(self::INTENT);
        $token = $request->input('purge_token');
        if (!is_array($stored) || !is_string($token) || !is_string($stored['token'] ?? null)
            || !hash_equals($stored['token'], $token) || !is_int($stored['created_at'] ?? null)
            || time() - $stored['created_at'] > 900 || !is_array($stored['query'] ?? null)
            || !is_int($stored['count'] ?? null)) {
            throw new ValidationException('The purge confirmation expired or is invalid.');
        }
        $synthetic = new Request('GET', '/admin/conversations', query: array_map('strval', $stored['query']));
        $query = $this->queries->parse($synthetic);
        if (!is_string($stored['eligible_before'] ?? null)) { throw new ValidationException('The purge confirmation is invalid.'); }
        $snapshot = new ChatbotConversationPurgeSnapshot($query, $stored['count'], is_int($stored['maximum_id'] ?? null) ? $stored['maximum_id'] : null, $stored['eligible_before']);
        $expected = 'PURGE ' . $snapshot->recordCount . ' CONVERSATIONS';
        if (!is_string($request->input('confirmation')) || !hash_equals($expected, trim((string) $request->input('confirmation')))) {
            throw new ValidationException('Type the confirmation phrase exactly.');
        }
        $admin = $this->admin($request);
        $deleted = $this->retention->purgeReviewed($snapshot, $this->now(), $admin->id);
        $this->session->remove(self::INTENT);
        $this->session->put(self::FLASH, $deleted . ' eligible conversations permanently deleted. Backups follow their separate retention policy.');
        return Response::redirect('/admin/conversations', 303);
    }

    /** @return array<string, mixed> */
    private function layout(Request $request, string $title): array
    { return ['title' => $title, 'admin' => $this->admin($request), 'csrfToken' => $this->csrf->token(), 'environment' => $this->environment, 'currentSection' => 'conversations']; }
    private function admin(Request $request): AdminUser
    { $admin = $request->attribute('admin_user'); return $admin instanceof AdminUser ? $admin : throw new \LogicException('Authenticated administrator is missing.'); }
    private function now(): DateTimeImmutable { return new DateTimeImmutable('now', new DateTimeZone('UTC')); }
}
