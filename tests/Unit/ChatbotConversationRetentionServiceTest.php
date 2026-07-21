<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Chatbots\ChatbotSessionChannel;
use App\Domain\Chatbots\ChatbotSessionCredentials;
use App\Services\Chatbots\ChatbotConversationService;
use App\Services\Chatbots\ChatbotConversationRetentionService;
use App\Services\Chatbots\ChatbotSessionCredentialGeneratorInterface;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Tests\Fakes\InMemoryChatbotConversationRepository;
use Tests\Fakes\InMemoryMaintenanceLock;

final class ChatbotConversationRetentionServiceTest extends TestCase
{
    public function testScheduledRunExpiresAndPurgesUnderOneLock():void
    {
        $repository=new InMemoryChatbotConversationRepository();$repository->defineChatbot(1,1,origins:['https://example.com'],idleMinutes:5,absoluteMinutes:10,retentionDays:0);
        $credentials=new ChatbotSessionCredentials('cs_'.str_repeat('S',43),'cst_v1_'.str_repeat('T',43),'cst_v1_'.str_repeat('T',8),hash('sha256','cst_v1_'.str_repeat('T',43)));
        $generator=new class($credentials) implements ChatbotSessionCredentialGeneratorInterface{public function __construct(private ChatbotSessionCredentials $credentials){}public function generate():ChatbotSessionCredentials{return $this->credentials;}};
        $conversations=new ChatbotConversationService($repository,$generator);$conversations->createSession(1,ChatbotSessionChannel::Browser,'https://example.com',false,new DateTimeImmutable('2026-07-20 00:00:00 UTC'));
        $lock=new InMemoryMaintenanceLock();$result=(new ChatbotConversationRetentionService($repository,$lock,new NullLogger(),10,'test'))->runScheduled(new DateTimeImmutable('2026-07-21 00:00:00 UTC'));
        self::assertSame(['lock_acquired'=>true,'expired'=>1,'purged'=>1],$result);self::assertSame(1,$lock->releaseCount);self::assertNull($repository->findSessionByPublicId($credentials->publicId));
    }
    public function testConcurrentRunSkipsWithoutMutation():void
    {$lock=new InMemoryMaintenanceLock();$lock->available=false;$result=(new ChatbotConversationRetentionService(new InMemoryChatbotConversationRepository(),$lock,new NullLogger(),10,'test'))->runScheduled(new DateTimeImmutable());self::assertFalse($result['lock_acquired']);self::assertSame(0,$lock->releaseCount);}
}
