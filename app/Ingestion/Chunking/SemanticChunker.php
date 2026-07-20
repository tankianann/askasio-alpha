<?php

declare(strict_types=1);

namespace App\Ingestion\Chunking;

use App\Domain\Ingestion\Chunk;
use App\Domain\Ingestion\ExtractedDocument;
use App\Domain\Ingestion\ExtractedSection;
use App\Ingestion\ChunkerInterface;
use InvalidArgumentException;

final class SemanticChunker implements ChunkerInterface
{
    public function __construct(
        private readonly HeuristicTokenEstimator $tokens,
        private readonly int $targetTokens = 500,
        private readonly int $overlapTokens = 75,
        private readonly int $minimumTokens = 20,
    ) {
        if ($this->targetTokens < 50) {
            throw new InvalidArgumentException('Chunk size must be at least 50 tokens.');
        }

        if ($this->overlapTokens < 0 || $this->overlapTokens >= $this->targetTokens) {
            throw new InvalidArgumentException('Chunk overlap must be non-negative and smaller than chunk size.');
        }

        if ($this->minimumTokens < 1 || $this->minimumTokens >= $this->targetTokens) {
            throw new InvalidArgumentException('Minimum chunk size must be positive and smaller than chunk size.');
        }
    }

    public function chunk(ExtractedDocument $document): array
    {
        $chunks = [];

        foreach ($document->sections as $section) {
            foreach ($this->sectionChunks($section) as $content) {
                $content = trim($content);

                if ($content === '') {
                    continue;
                }

                $chunks[] = new Chunk(
                    count($chunks) + 1,
                    $content,
                    $this->tokens->estimate($content),
                    $this->metadata($document, $section),
                );
            }
        }

        return $this->mergeSmallTail($chunks);
    }

    /** @return list<string> */
    private function sectionChunks(ExtractedSection $section): array
    {
        $units = $this->splitText($section->text);

        if ($units === []) {
            return [];
        }

        $chunks = [];
        $current = [];

        foreach ($units as $unit) {
            $candidate = [...$current, $unit];

            if ($current !== [] && $this->tokens->estimate(implode("\n\n", $candidate)) > $this->targetTokens) {
                $completed = implode("\n\n", $current);
                $chunks[] = $completed;
                $current = $this->overlap($completed);

                if ($current !== []
                    && $this->tokens->estimate(implode("\n\n", [...$current, $unit])) > $this->targetTokens) {
                    $current = [];
                }
            }

            $current[] = $unit;
        }

        $chunks[] = implode("\n\n", $current);

        return $chunks;
    }

    /** @return list<string> */
    private function splitText(string $text): array
    {
        $paragraphs = preg_split('/\n{2,}/u', trim($text)) ?: [];
        $units = [];

        foreach ($paragraphs as $paragraph) {
            $paragraph = trim($paragraph);

            if ($paragraph === '') {
                continue;
            }

            if ($this->tokens->estimate($paragraph) <= $this->targetTokens) {
                $units[] = $paragraph;
                continue;
            }

            foreach ($this->splitOversizedParagraph($paragraph) as $part) {
                if ($part !== '') {
                    $units[] = $part;
                }
            }
        }

        return $units;
    }

    /** @return list<string> */
    private function splitOversizedParagraph(string $paragraph): array
    {
        $sentences = preg_split('/(?<=[.!?])\s+(?=[\p{Lu}\p{N}])/u', $paragraph) ?: [$paragraph];
        $parts = [];
        $current = '';

        foreach ($sentences as $sentence) {
            $sentence = trim($sentence);

            if ($sentence === '') {
                continue;
            }

            if ($this->tokens->estimate($sentence) > $this->targetTokens) {
                if ($current !== '') {
                    $parts[] = $current;
                    $current = '';
                }

                array_push($parts, ...$this->splitByWords($sentence));
                continue;
            }

            $candidate = trim($current . ' ' . $sentence);

            if ($current !== '' && $this->tokens->estimate($candidate) > $this->targetTokens) {
                $parts[] = $current;
                $current = $sentence;
            } else {
                $current = $candidate;
            }
        }

        if ($current !== '') {
            $parts[] = $current;
        }

        return $parts;
    }

    /** @return list<string> */
    private function splitByWords(string $text): array
    {
        $words = preg_split('/\s+/u', trim($text)) ?: [];
        $parts = [];
        $current = [];

        foreach ($words as $word) {
            $candidate = [...$current, $word];

            if ($current !== [] && $this->tokens->estimate(implode(' ', $candidate)) > $this->targetTokens) {
                $parts[] = implode(' ', $current);
                $current = [];
            }

            $current[] = $word;
        }

        if ($current !== []) {
            $parts[] = implode(' ', $current);
        }

        return $parts;
    }

    /** @return list<string> */
    private function overlap(string $content): array
    {
        if ($this->overlapTokens === 0) {
            return [];
        }

        $words = preg_split('/\s+/u', trim($content)) ?: [];
        $overlap = [];

        while ($words !== []) {
            array_unshift($overlap, (string) array_pop($words));

            if ($this->tokens->estimate(implode(' ', $overlap)) >= $this->overlapTokens) {
                break;
            }
        }

        return $overlap === [] ? [] : [implode(' ', $overlap)];
    }

    /** @return array<string, mixed> */
    private function metadata(ExtractedDocument $document, ExtractedSection $section): array
    {
        return array_filter([
            ...$document->metadata,
            ...$section->metadata,
            'document_title' => $document->title,
            'section_title' => $section->title,
            'heading_hierarchy' => $section->headingHierarchy,
            'page' => $section->page,
            'character_start' => $section->startOffset,
            'character_end' => $section->endOffset,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /** @param list<Chunk> $chunks @return list<Chunk> */
    private function mergeSmallTail(array $chunks): array
    {
        if (count($chunks) < 2) {
            return $chunks;
        }

        $last = $chunks[array_key_last($chunks)];

        if ($last->tokenCount >= $this->minimumTokens) {
            return $chunks;
        }

        $previousIndex = count($chunks) - 2;
        $previous = $chunks[$previousIndex];

        if ($previous->metadata !== $last->metadata) {
            return $chunks;
        }

        $combined = trim($previous->content . "\n\n" . $last->content);
        $chunks[$previousIndex] = new Chunk(
            $previous->number,
            $combined,
            $this->tokens->estimate($combined),
            $previous->metadata,
        );
        array_pop($chunks);

        return $chunks;
    }
}
