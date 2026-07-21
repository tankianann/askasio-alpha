<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Domain\Chatbots\PublicChatbotConfiguration;
use App\Domain\Chatbots\PublicChatbotContext;
use App\Domain\Chatbots\PublicChatbotSession;
use App\Exceptions\ChatbotSessionUnavailableException;
use App\Exceptions\HttpException;
use App\Http\JsonRequestParser;
use App\Http\Request;
use App\Http\Response;
use App\Services\Chatbots\ChatbotConversationService;
use Closure;
use DateTimeImmutable;
use DateTimeZone;

final readonly class PublicChatbotController
{
    public function __construct(
        private ChatbotConversationService $conversations,
        private JsonRequestParser $json,
        private ?Closure $clock = null,
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
