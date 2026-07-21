<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Chatbots\ChatbotSessionStatus;
use App\Exceptions\ValidationException;
use App\Http\Request;
use App\Services\Chatbots\ChatbotConversationListQueryParser;
use PHPUnit\Framework\TestCase;

final class ChatbotConversationListQueryParserTest extends TestCase
{
    public function testParsesBoundedProductionAndTestFilters():void
    {
        $query=(new ChatbotConversationListQueryParser('Asia/Singapore'))->parse(new Request('GET','/',query:['traffic'=>'test','status'=>'completed','chatbot_id'=>'4','date_from'=>'2026-07-20','date_to'=>'2026-07-21','sort'=>'usage','per_page'=>'50']));
        self::assertTrue($query->isTest);self::assertSame(ChatbotSessionStatus::Completed,$query->status);self::assertSame(4,$query->chatbotId);self::assertSame('usage',$query->sort);self::assertSame(50,$query->pagination->perPage);self::assertSame('2026-07-19 16:00:00',$query->dateFromUtc);
    }
    public function testRejectsUnknownTraffic():void
    {$this->expectException(ValidationException::class);(new ChatbotConversationListQueryParser('UTC'))->parse(new Request('GET','/',query:['traffic'=>'tenant']));}
}
