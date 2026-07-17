<?php

declare(strict_types=1);

namespace App\Domain\Ingestion;

use InvalidArgumentException;

final class ExtractedDocument
{
    /**
     * @param list<ExtractedSection> $sections
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public readonly string $title,
        public readonly string $content,
        public readonly array $sections,
        public readonly array $metadata = [],
    ) {
        if (trim($this->content) === '' || $this->sections === []) {
            throw new InvalidArgumentException('An extracted document must contain text and at least one section.');
        }
    }

    /**
     * @param list<ExtractedSection> $sections
     * @param array<string, mixed> $metadata
     */
    public static function fromSections(string $title, array $sections, array $metadata = []): self
    {
        $normalized = [];
        $parts = [];
        $offset = 0;

        foreach ($sections as $section) {
            $text = trim($section->text);

            if ($text === '') {
                continue;
            }

            if ($parts !== []) {
                $offset += 2;
            }

            $start = $offset;
            $parts[] = $text;
            $offset += mb_strlen($text, 'UTF-8');
            $normalized[] = (new ExtractedSection(
                $section->title,
                $text,
                $section->headingHierarchy,
                $section->page,
                $section->metadata,
            ))->withOffsets($start, $offset);
        }

        return new self(trim($title), implode("\n\n", $parts), $normalized, $metadata);
    }

    public function contentHash(): string
    {
        return hash('sha256', $this->content);
    }
}
