<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Domain\Chatbots\PublicChatbotConfiguration;
use App\Domain\Chatbots\PublicChatbotContext;
use App\Domain\Chatbots\PublicChatbotMessage;
use App\Domain\Chatbots\PublicChatbotSession;
use App\Domain\Chatbots\ChatbotExecutionAudience;
use App\Domain\Chatbots\ChatbotSession;
use App\Exceptions\ChatbotExecutionException;
use App\Exceptions\ChatbotIdempotencyConflictException;
use App\Exceptions\ChatbotMessageInProgressException;
use App\Exceptions\ChatbotMessageLimitException;
use App\Exceptions\ChatbotSessionUnavailableException;
use App\Exceptions\HttpException;
use App\Exceptions\InvalidChatbotSessionTokenException;
use App\Exceptions\ValidationException;
use App\Http\JsonRequestParser;
use App\Http\Request;
use App\Http\Response;
use App\Services\Chatbots\ChatbotConversationService;
use App\Services\Chatbots\SharedChatExecutionService;
use Closure;
use DateTimeImmutable;
use DateTimeZone;

final readonly class PublicChatbotController
{
    public function __construct(
        private ChatbotConversationService $conversations,
        private JsonRequestParser $json,
        private ?Closure $clock = null,
        private ?SharedChatExecutionService $executor = null,
        private string $applicationSecret = '',
    ) {
    }

    public function configuration(Request $request): Response
    {
        $context = $this->context($request);
        $this->requireExecutionAvailable($context);

        return Response::json([
            'chatbot' => PublicChatbotConfiguration::fromContext($context)->toArray(),
            'api_version' => 'v1',
            'request_id' => $this->requestId($request),
        ])->withHeader('Cache-Control', 'no-store');
    }

    public function createSession(Request $request): Response
    {
        $payload = $this->json->object($request);

        if ($payload !== []) {
            throw new HttpException(422, 'Session creation does not accept request fields.', 'invalid_request');
        }

        $context = $this->context($request);
        $this->requireExecutionAvailable($context);

        try {
            $created = $this->conversations->createSession(
                $context->chatbot->id,
                \App\Domain\Chatbots\ChatbotSessionChannel::Browser,
                $context->normalizedOrigin,
                false,
                $this->now(),
            );
        } catch (ChatbotSessionUnavailableException) {
            throw new HttpException(404, 'The chatbot was not found.', 'chatbot_not_found');
        }

        return Response::json([
            ...PublicChatbotSession::fromCreated($created)->toArray(),
            'request_id' => $this->requestId($request),
        ], 201)
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('Pragma', 'no-cache');
    }

    public function message(Request $request): Response
    {
        $context = $this->context($request);
        $this->requireExecutionAvailable($context);
        $session = $this->session($request);
        $payload = $this->json->object($request);
        $this->rejectUnknownFields($payload, ['message', 'idempotency_key']);
        $message = $payload['message'] ?? null;
        $idempotencyKey = $payload['idempotency_key'] ?? null;

        if (!is_string($message) || trim($message) === '' || !is_string($idempotencyKey)) {
            throw new HttpException(422, 'message and idempotency_key are required strings.', 'invalid_request');
        }

        if (mb_strlen(trim($message)) > $session->maximumMessageCharacters) {
            throw new HttpException(
                422,
                sprintf('The message may not exceed %d characters.', $session->maximumMessageCharacters),
                'message_too_long',
            );
        }

        try {
            $reservation = $this->conversations->reserveMessage(
                $session->publicId,
                $this->sessionToken($request),
                $idempotencyKey,
                $message,
                $this->requiredRequestId($request),
                $this->now(),
            );
            $result = $this->requireExecutor()->execute(
                $reservation,
                ChatbotExecutionAudience::Public,
                $this->safetyIdentifier($session),
                $this->now(),
            );
        } catch (ChatbotMessageInProgressException) {
            throw new HttpException(409, 'The matching message is still being processed.', 'message_in_progress');
        } catch (ChatbotIdempotencyConflictException) {
            throw new HttpException(409, 'The idempotency key conflicts with an earlier request.', 'idempotency_conflict');
        } catch (ChatbotMessageLimitException) {
            throw new HttpException(429, 'The chatbot session message limit has been reached.', 'session_limit_reached');
        } catch (ChatbotSessionUnavailableException) {
            throw new HttpException(410, 'The chatbot session is no longer available.', 'session_expired');
        } catch (ValidationException $exception) {
            throw new HttpException(422, $exception->getMessage(), 'invalid_request');
        } catch (ChatbotExecutionException $exception) {
            $code = match ($exception->errorCode) {
                'chatbot_unavailable' => 'chatbot_not_found',
                'publication_configuration_stale', 'execution_configuration_unavailable' => 'knowledge_unavailable',
                default => $exception->errorCode,
            };
            $status = $code === 'chatbot_not_found' ? 404 : $exception->statusCode;
            $message = match ($code) {
                'chatbot_not_found' => 'The chatbot was not found.',
                'knowledge_unavailable' => 'The chatbot is temporarily unavailable.',
                default => $exception->getMessage(),
            };
            throw new HttpException($status, $message, $code);
        }

        return Response::json([
            ...PublicChatbotMessage::fromResult($session, $result)->toArray(),
            'request_id' => $this->requestId($request),
        ])->withHeader('Cache-Control', 'no-store');
    }

    public function completeSession(Request $request): Response
    {
        $payload = $this->json->object($request);

        if ($payload !== []) {
            throw new HttpException(422, 'Session completion does not accept request fields.', 'invalid_request');
        }

        try {
            $this->conversations->completeSession(
                $this->session($request)->publicId,
                $this->sessionToken($request),
                $this->now(),
            );
        } catch (InvalidChatbotSessionTokenException|ChatbotSessionUnavailableException) {
            throw new HttpException(401, 'A valid chatbot session bearer token is required.', 'invalid_session');
        }

        return (new Response('', 204))->withHeader('Cache-Control', 'no-store');
    }

    private function context(Request $request): PublicChatbotContext
    {
        $context = $request->attribute('public_chatbot_context');

        return $context instanceof PublicChatbotContext
            ? $context
            : throw new \LogicException('Authorized public chatbot context is missing.');
    }

    private function requestId(Request $request): ?string
    {
        $requestId = $request->attribute('request_id');

        return is_string($requestId) ? $requestId : null;
    }

    private function requiredRequestId(Request $request): string
    {
        return $this->requestId($request)
            ?? throw new \LogicException('A public chatbot request ID is required.');
    }

    private function session(Request $request): ChatbotSession
    {
        $session = $request->attribute('public_chatbot_session');

        return $session instanceof ChatbotSession
            ? $session
            : throw new \LogicException('An authenticated public chatbot session is required.');
    }

    private function sessionToken(Request $request): string
    {
        $token = $request->attribute('public_chatbot_session_token');

        return is_string($token) && $token !== ''
            ? $token
            : throw new \LogicException('An authenticated public chatbot session token is required.');
    }

    /** @param array<string, mixed> $payload @param list<string> $allowed */
    private function rejectUnknownFields(array $payload, array $allowed): void
    {
        foreach (array_keys($payload) as $key) {
            if (!is_string($key) || !in_array($key, $allowed, true)) {
                throw new HttpException(422, 'An unsupported request field was supplied.', 'invalid_request');
            }
        }
    }

    private function requireExecutor(): SharedChatExecutionService
    {
        return $this->executor
            ?? throw new HttpException(503, 'The chatbot is temporarily unavailable.', 'knowledge_unavailable');
    }

    private function safetyIdentifier(ChatbotSession $session): string
    {
        if (strlen($this->applicationSecret) < 32) {
            throw new \LogicException('A strong application secret is required for public chatbot execution.');
        }

        return hash_hmac('sha256', 'public-chatbot-session:' . $session->id, $this->applicationSecret);
    }

    private function requireExecutionAvailable(PublicChatbotContext $context): void
    {
        if (!$context->executionAvailable) {
            throw new HttpException(503, 'The chatbot is temporarily unavailable.', 'knowledge_unavailable');
        }
    }

    private function now(): DateTimeImmutable
    {
        $now = $this->clock instanceof Closure
            ? ($this->clock)()
            : new DateTimeImmutable('now', new DateTimeZone('UTC'));

        return $now instanceof DateTimeImmutable
            ? $now
            : throw new \LogicException('The public chatbot clock must return DateTimeImmutable.');
    }
}
