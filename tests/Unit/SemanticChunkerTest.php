<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Ingestion\ExtractedDocument;
use App\Domain\Ingestion\ExtractedSection;
use App\Ingestion\Chunking\HeuristicTokenEstimator;
use App\Ingestion\Chunking\SemanticChunker;
use PHPUnit\Framework\TestCase;

final class SemanticChunkerTest extends TestCase
{
    public function testItChunksOnParagraphBoundariesWithOverlapAndMetadata(): void
    {
        $paragraphs = [];

        for ($index = 1; $index <= 10; $index++) {
            $paragraphs[] = "Paragraph {$index} contains enough policy detail to make semantic chunk boundaries predictable and useful for retrieval.";
        }

        $document = ExtractedDocument::fromSections('Policy', [
            new ExtractedSection('Eligibility', implode("\n\n", $paragraphs), ['Policy', 'Eligibility'], 3),
        ], ['source_type' => 'pdf']);
        $chunks = (new SemanticChunker(new HeuristicTokenEstimator(), 80, 15, 10))->chunk($document);

        self::assertGreaterThan(1, count($chunks));
        self::assertSame(range(1, count($chunks)), array_column($chunks, 'number'));
        self::assertSame('Eligibility', $chunks[0]->metadata['section_title']);
        self::assertSame(['Policy', 'Eligibility'], $chunks[0]->metadata['heading_hierarchy']);
        self::assertSame(3, $chunks[0]->metadata['page']);
        self::assertGreaterThan(0, $chunks[0]->tokenCount);

        $firstWords = array_slice(preg_split('/\s+/', $chunks[0]->content) ?: [], -5);
        self::assertStringContainsString(implode(' ', $firstWords), $chunks[1]->content);
    }
}
