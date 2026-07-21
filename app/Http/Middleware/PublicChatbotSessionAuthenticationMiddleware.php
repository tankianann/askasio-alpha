<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Chatbots\ChatbotSessionChannel;
use App\Domain\Chatbots\PublicChatbotContext;
use App\Exceptions\InvalidChatbotSessionTokenException;
use App\Http\Request;
use App\Http\Response;
use App\Services\Chatbots\ChatbotConversationService;
use Closure;

final readonly class PublicChatbotSessionAuthenticationMiddleware implements MiddlewareInterface
{
    public function __construct(private ChatbotConversationService $conversations)
    {
    }

    public function process(Request $request, Closure $next): Response
    {
        $context = $request->attribute('public_chatbot_context');
        $authorization = trim((string) $request->header('authorization', ''));

        if (!$context instanceof PublicChatbotContext
            || preg_match('/\ABearer ([^\s]+)\z/i', $authorization, $matches) !== 1) {
            return $this->unauthorized($request);
        }

        $token = $matches[1];

        try {
            $session = $this->conversations->authenticate((string) $request->route('sessionId'), $token);
        } catch (InvalidChatbotSessionTokenException) {
            return $this->unauthorized($request);
        }

        if ($session->chatbotId !== $context->chatbot->id
            || $session->channel !== ChatbotSessionChannel::Browser
            || $session->isTest
            || $session->normalizedOrigin !== $context->normalizedOrigin) {
            return $this->unauthorized($request);
        }

        return $next(
            $request
                ->withAttribute('public_chatbot_session', $session)
                ->withAttribute('public_chatbot_session_token', $token),
        );
    }

    private function unauthorized(Request $request): Response
    {
        $requestId = $request->attribute('request_id');

        return Response::json([
            'error' => [
                'code' => 'invalid_session',
                'message' => 'A valid chatbot session bearer token is required.',
                'request_id' => is_string($requestId) ? $requestId : null,
            ],
        ], 401)
            ->withHeader('WWW-Authenticate', 'Bearer realm="Ask Asio chatbot"')
            ->withHeader('Cache-Control', 'no-store');
    }
}
