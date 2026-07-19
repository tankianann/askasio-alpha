<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\RAG\RetrievedChunk;
use App\Ingestion\Chunking\HeuristicTokenEstimator;
use App\RAG\ContextSelector;
use PHPUnit\Framework\TestCase;

final class ContextSelectorTest extends TestCase
{
    public function testItKeepsRankingOrderAndEnforcesTheContextBudget(): void
    {
        $first = $this->chunk(1, str_repeat('first content ', 70), 0.9);
        $second = $this->chunk(2, str_repeat('second content ', 70), 0.8);
        $selector = new ContextSelector(new HeuristicTokenEstimator(), 256);
        $result = $selector->select([$first, $second]);

        self::assertNotEmpty($result->chunks);
        self::assertSame(1, $result->chunks[0]->chunkId);
        self::assertLessThanOrEqual(256, $result->estimatedTokens);
        self::assertCount(1, $result->chunks);
    }

    private function chunk(int $id, string $content, float $similarity): RetrievedChunk
    {
        return new RetrievedChunk(
            $id, 1, 1, $id, $content, $similarity, 'Source', 'markdown', null, [],
        );
    }
}
