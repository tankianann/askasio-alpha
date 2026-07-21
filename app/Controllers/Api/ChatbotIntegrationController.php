<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Domain\Chatbots\Chatbot;
use App\Domain\Chatbots\ChatbotExecutionAudience;
use App\Domain\Chatbots\ChatbotIntegrationCredential;
use App\Domain\Chatbots\ChatbotSession;
use App\Domain\Chatbots\ChatbotSessionChannel;
use App\Domain\Chatbots\PublicChatbotMessage;
use App\Domain\Chatbots\PublicChatbotSession;
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
use App\Repositories\ChatbotIntegrationCredentialRepositoryInterface;
use App\Services\Chatbots\ChatbotConversationService;
use App\Services\Chatbots\SharedChatExecutionService;
use DateTimeImmutable;
use DateTimeZone;

final readonly class ChatbotIntegrationController
{
    public function __construct(private ChatbotConversationService $conversations, private JsonRequestParser $json, private ?SharedChatExecutionService $executor, private ChatbotIntegrationCredentialRepositoryInterface $credentials, private string $secret) {}

    public function createSession(Request $request): Response
    {
        if ($this->json->object($request)!==[]) { throw new HttpException(422,'Session creation does not accept request fields.','invalid_request'); }
        $chatbot=$this->chatbot($request);
        $credential=$this->credential($request);
        try { $created=$this->conversations->createSession($chatbot->id,ChatbotSessionChannel::Integration,null,false,$this->now(),$credential->id); }
        catch (ChatbotSessionUnavailableException) { throw new HttpException(404,'The chatbot was not found.','chatbot_not_found'); }
        return Response::json([...PublicChatbotSession::fromCreated($created)->toArray(),'request_id'=>$request->attribute('request_id')],201)->withHeader('Cache-Control','no-store');
    }

    public function message(Request $request): Response
    {
        $session=$this->session($request); $payload=$this->json->object($request);
        foreach(array_keys($payload) as $key){if(!in_array($key,['message','idempotency_key'],true)){throw new HttpException(422,'An unsupported request field was supplied.','invalid_request');}}
        $message=$payload['message']??null; $key=$payload['idempotency_key']??null;
        if(!is_string($message)||trim($message)===''||!is_string($key)){throw new HttpException(422,'message and idempotency_key are required strings.','invalid_request');}
        if(mb_strlen(trim($message))>$session->maximumMessageCharacters){throw new HttpException(422,'The message is too long.','message_too_long');}
        try {
            $reservation=$this->conversations->reserveMessage($session->publicId,$this->sessionToken($request),$key,$message,(string)$request->attribute('request_id'),$this->now());
            $result=($this->executor??throw new HttpException(503,'The chatbot is temporarily unavailable.','knowledge_unavailable'))->execute($reservation,ChatbotExecutionAudience::Public,hash_hmac('sha256','integration-session:'.$session->id,$this->secret),$this->now());
        } catch(ChatbotMessageInProgressException){throw new HttpException(409,'The matching message is still being processed.','message_in_progress');}
        catch(ChatbotIdempotencyConflictException){throw new HttpException(409,'The idempotency key conflicts with an earlier request.','idempotency_conflict');}
        catch(ChatbotMessageLimitException){throw new HttpException(429,'The session message limit has been reached.','session_limit_reached');}
        catch(ChatbotSessionUnavailableException){throw new HttpException(410,'The session is no longer available.','session_expired');}
        catch(ValidationException $e){throw new HttpException(422,$e->getMessage(),'invalid_request');}
        catch(ChatbotExecutionException $e){throw new HttpException($e->statusCode,$e->getMessage(),$e->errorCode);}
        $credential=$this->credential($request);
        $this->credentials->addProviderTokens($credential->id,(int)($result->usage['provider_total_tokens']??0));
        return Response::json([...PublicChatbotMessage::fromResult($session,$result)->toArray(),'request_id'=>$request->attribute('request_id')])->withHeader('Cache-Control','no-store');
    }

    private function chatbot(Request $request): Chatbot { $c=$request->attribute('integration_chatbot'); return $c instanceof Chatbot?$c:throw new \LogicException('Scoped integration chatbot is missing.'); }
    private function credential(Request $request): ChatbotIntegrationCredential { $c=$request->attribute('chatbot_integration_credential'); return $c instanceof ChatbotIntegrationCredential?$c:throw new \LogicException('Scoped chatbot API key is missing.'); }
    private function session(Request $request): ChatbotSession
    {
        $token=$this->sessionToken($request);
        try{$session=$this->conversations->authenticate((string)$request->route('sessionId'),$token);}catch(InvalidChatbotSessionTokenException){throw new HttpException(401,'A valid integration session token is required.','invalid_session');}
        if($session->chatbotId!==$this->chatbot($request)->id||$session->channel!==ChatbotSessionChannel::Integration||$session->isTest){throw new HttpException(401,'A valid integration session token is required.','invalid_session');}
        return $session;
    }
    private function sessionToken(Request $request): string { $token=trim((string)$request->header('x-chatbot-session-token','')); return $token!==''?$token:throw new HttpException(401,'A valid integration session token is required.','invalid_session'); }
    private function now():DateTimeImmutable{return new DateTimeImmutable('now',new DateTimeZone('UTC'));}
}
