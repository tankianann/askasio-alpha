<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\RAG\RetrievedChunk;
use App\Ingestion\Chunking\HeuristicTokenEstimator;
use App\Providers\Chat\ChatMalformedResponseException;
use App\RAG\AnswerGenerator;
use App\RAG\ContextSelector;
use App\RAG\PromptBuilder;
use App\RAG\Retriever;
use PHPUnit\Framework\TestCase;
use Tests\Fakes\FakeChatProvider;
use Tests\Fakes\FakeEmbeddingProvider;
use Tests\Fakes\InMemoryVectorStore;

final class AnswerGeneratorTest extends TestCase
{
    public function testItReturnsInsufficientInformationWithoutCallingChatWhenRetrievalIsEmpty(): void
    {
        $chat = new FakeChatProvider();
        $generator = $this->generator([], $chat);
        $result = $generator->generate('Unknown question');

        self::assertSame(AnswerGenerator::INSUFFICIENT_INFORMATION, $result->answer);
        self::assertSame([], $result->chunks);
        self::assertSame(0, $result->usage['total_tokens']);
        self::assertSame([], $chat->requests);
    }

    public function testItCombinesRetrievalContextAndProviderUsage(): void
    {
        $chunk = new RetrievedChunk(
            18, 1, 3, 2, 'Refunds are available within 30 days.', 0.9,
            'Refund Policy', 'url', 'https://example.com/refunds', [],
        );
        $chat = new FakeChatProvider('Thirty days [S1].');
        $result = $this->generator([$chunk], $chat)->generate('When can I get a refund?', 5);

        self::assertSame('Thirty days [S1].', $result->answer);
        self::assertSame(1, $result->usage['retrieved_chunks']);
        self::assertSame(160, $result->usage['total_tokens']);
        self::assertCount(1, $chat->requests);
    }

    public function testItRejectsAnswersThatDoNotLinkClaimsToSuppliedSources(): void
    {
        $chunk = new RetrievedChunk(1, 1, 1, 1, 'Context', 0.9, 'Source', 'url', null, []);

        $this->expectException(ChatMalformedResponseException::class);
        $this->generator([$chunk], new FakeChatProvider('An uncited answer.'))->generate('Question');
    }

    public function testItRejectsReferencesOutsideTheSuppliedContext(): void
    {
        $chunk = new RetrievedChunk(1, 1, 1, 1, 'Context', 0.9, 'Source', 'url', null, []);

        $this->expectException(ChatMalformedResponseException::class);
        $this->generator([$chunk], new FakeChatProvider('An invalid answer [S9].'))->generate('Question');
    }

    /** @param list<RetrievedChunk> $matches */
    private function generator(array $matches, FakeChatProvider $chat): AnswerGenerator
    {
        return new AnswerGenerator(
            new Retriever(
                new FakeEmbeddingProvider(),
                new InMemoryVectorStore($matches),
                5,
                8,
                0.2,
            ),
            new ContextSelector(new HeuristicTokenEstimator(), 4000),
            new PromptBuilder(),
            $chat,
        );
    }
}
