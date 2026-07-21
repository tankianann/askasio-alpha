<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Database\Connection;
use App\Repositories\PdoChatbotIntegrationCredentialRepository;
use App\Repositories\PdoChatbotRepository;
use App\Support\Config;
use Tests\Support\ChatbotFixtures;
use Tests\Support\DatabaseIntegrationTestCase;
use Tests\Support\TestDatabase;

final class ChatbotIntegrationCredentialRepositoryTest extends DatabaseIntegrationTestCase
{
    public function testCredentialPersistsHashScopesLifecycleAndUsage():void
    {
        self::$database?->exec("INSERT INTO admin_users (username,password_hash) VALUES ('admin','hash')");
        $connection=$this->connection();$chatbots=new PdoChatbotRepository($connection);
        $first=$chatbots->create('cb_'.str_repeat('A',43),'One',null,ChatbotFixtures::draft());$second=$chatbots->create('cb_'.str_repeat('B',43),'Two',null,ChatbotFixtures::draft());
        $repository=new PdoChatbotIntegrationCredentialRepository($connection);$hash=hash('sha256','chatint_live_secret');
        $credential=$repository->create(1,'Backend','chatint_live_secr',$hash,null,[$first->id,$second->id]);
        self::assertSame([$first->id,$second->id],$credential->chatbotIds);self::assertSame('',$credential->secretHash);
        $authenticated=$repository->findByHash($hash);self::assertSame($hash,$authenticated?->secretHash);
        $repository->touchUsage($credential->id);$repository->addProviderTokens($credential->id,42);
        self::assertSame(1,$repository->findById($credential->id)?->requestCount);self::assertSame(42,$repository->findById($credential->id)?->providerTokens);
        $repository->revoke($credential->id);self::assertSame('revoked',$repository->findById($credential->id)?->status);
        $repository->delete($credential->id);self::assertNull($repository->findById($credential->id));
    }
    private function connection():Connection
    {$e=TestDatabase::applicationEnvironment();return new Connection(new Config(['database'=>['host'=>$e['DB_HOST'],'port'=>(int)$e['DB_PORT'],'database'=>$e['DB_DATABASE'],'username'=>$e['DB_USERNAME'],'password'=>$e['DB_PASSWORD'],'charset'=>'utf8mb4']]));}
}
