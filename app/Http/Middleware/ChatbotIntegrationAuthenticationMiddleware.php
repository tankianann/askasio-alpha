<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Request;
use App\Http\Response;
use App\Repositories\ChatbotRepositoryInterface;
use App\Repositories\ChatbotIntegrationCredentialRepositoryInterface;
use App\Services\Chatbots\ChatbotIntegrationCredentialService;
use Closure;

final readonly class ChatbotIntegrationAuthenticationMiddleware implements MiddlewareInterface
{
    public function __construct(private ChatbotIntegrationCredentialService $credentials, private ChatbotRepositoryInterface $chatbots, private ChatbotIntegrationCredentialRepositoryInterface $repository) {}
    public function process(Request $request, Closure $next): Response
    {
        $authorization = trim((string)$request->header('authorization',''));
        if (preg_match('/\ABearer ([^\s]+)\z/i',$authorization,$matches)!==1) { return $this->unauthorized($request); }
        $credential = $this->credentials->authenticate($matches[1]);
        $chatbot = $this->chatbots->findByPublicId((string)$request->route('chatbotPublicId'));
        if ($credential === null || $chatbot === null || !in_array($chatbot->id,$credential->chatbotIds,true)) { return $this->unauthorized($request); }
        $this->repository->touchUsage($credential->id);
        return $next($request->withAttribute('chatbot_integration_credential',$credential)->withAttribute('integration_chatbot',$chatbot));
    }
    private function unauthorized(Request $request): Response
    {
        $id=$request->attribute('request_id');
        return Response::json(['error'=>['code'=>'invalid_integration_credential','message'=>'A valid scoped chatbot integration credential is required.','request_id'=>is_string($id)?$id:null]],401)
            ->withHeader('WWW-Authenticate','Bearer realm="Ask Asio chatbot integration"')->withHeader('Cache-Control','no-store');
    }
}
