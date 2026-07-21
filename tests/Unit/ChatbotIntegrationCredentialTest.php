<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\Middleware\ChatbotIntegrationAuthenticationMiddleware;
use App\Http\Request;
use App\Http\Response;
use App\Services\Chatbots\ChatbotIntegrationCredentialService;
use PHPUnit\Framework\TestCase;
use Tests\Fakes\InMemoryChatbotIntegrationCredentialRepository;
use Tests\Fakes\InMemoryChatbotRepository;

final class ChatbotIntegrationCredentialTest extends TestCase
{
    public function testSecretIsOneTimeHashOnlyAndScopeAuthenticationIsEnforced():void
    {
        $repository=new InMemoryChatbotIntegrationCredentialRepository();$service=new ChatbotIntegrationCredentialService($repository);
        $created=$service->create(1,'Backend',null,[1]);
        self::assertStringStartsWith('chatint_live_',$created->plaintext);
        self::assertNotSame($created->plaintext,$created->credential->secretHash);
        self::assertSame(hash('sha256',$created->plaintext),$created->credential->secretHash);
        self::assertSame([1],$service->authenticate($created->plaintext)?->chatbotIds);

        $chatbots=new InMemoryChatbotRepository();$one=$chatbots->create('cb_'.str_repeat('A',43),'One',null,\Tests\Support\ChatbotFixtures::draft());$two=$chatbots->create('cb_'.str_repeat('B',43),'Two',null,\Tests\Support\ChatbotFixtures::draft());
        self::assertSame(1,$one->id);self::assertSame(2,$two->id);
        $middleware=new ChatbotIntegrationAuthenticationMiddleware($service,$chatbots,$repository);
        $request=new Request('POST','/',headers:['authorization'=>'Bearer '.$created->plaintext],attributes:['route_parameters'=>['chatbotPublicId'=>$one->publicId],'request_id'=>'test']);
        $allowed=$middleware->process($request,static fn(Request $r):Response=>Response::json(['ok'=>true]));
        self::assertSame(200,$allowed->status());self::assertSame(1,$repository->items[1]->requestCount);
        $denied=$middleware->process(new Request('POST','/',headers:['authorization'=>'Bearer '.$created->plaintext],attributes:['route_parameters'=>['chatbotPublicId'=>$two->publicId],'request_id'=>'test']),static fn(Request $r):Response=>Response::json(['ok'=>true]));
        self::assertSame(401,$denied->status());self::assertStringContainsString('invalid_integration_credential',$denied->body());
        $repository->revoke(1);self::assertNull($service->authenticate($created->plaintext));
    }
}
