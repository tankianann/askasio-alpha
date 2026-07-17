<?php

declare(strict_types=1);

namespace App\Ingestion\Extractors;

use App\Domain\Ingestion\ExtractedDocument;
use App\Domain\Ingestion\ExtractedSection;
use App\Ingestion\PermanentIngestionException;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

final class HtmlDocumentParser
{
    /** @param array<string, mixed> $metadata */
    public function parse(string $html, string $fallbackTitle, array $metadata = []): ExtractedDocument
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML(
            '<?xml encoding="UTF-8">' . $html,
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded) {
            throw new PermanentIngestionException('The HTML document could not be parsed.');
        }

        $xpath = new DOMXPath($document);

        foreach ($xpath->query('//script|//style|//noscript|//nav|//header|//footer|//aside|//form|//svg|//canvas|//template') ?: [] as $node) {
            $node->parentNode?->removeChild($node);
        }

        $title = $this->firstText($xpath, '//title')
            ?? $this->firstText($xpath, '//h1')
            ?? trim($fallbackTitle);
        $title = $title !== '' ? $title : 'Untitled document';
        $canonicalNode = $xpath->query('//link[translate(@rel, "CANONICAL", "canonical")="canonical"]/@href')?->item(0);
        $descriptionNode = $xpath->query('//meta[translate(@name, "DESCRIPTION", "description")="description"]/@content')?->item(0);

        if ($canonicalNode instanceof DOMNode && trim($canonicalNode->nodeValue ?? '') !== '') {
            $metadata['canonical_url'] = trim((string) $canonicalNode->nodeValue);
        }

        if ($descriptionNode instanceof DOMNode && trim($descriptionNode->nodeValue ?? '') !== '') {
            $metadata['description'] = $this->normalizeText((string) $descriptionNode->nodeValue);
        }

        $metadata['title'] = $title;
        $root = $xpath->query('//main[1]')?->item(0)
            ?? $xpath->query('//article[1]')?->item(0)
            ?? $xpath->query('//body[1]')?->item(0);

        if (!$root instanceof DOMNode) {
            throw new PermanentIngestionException('The HTML document contains no readable body.');
        }

        $nodes = $xpath->query(
            './/*[self::h1 or self::h2 or self::h3 or self::h4 or self::h5 or self::h6
                or self::p or self::pre or self::blockquote
                or (self::li and not(.//p)) or (self::td and not(.//p))]',
            $root,
        );
        $sections = [];
        $blocks = [];
        $hierarchy = [];
        $sectionTitle = $title;

        if ($nodes !== false) {
            foreach ($nodes as $node) {
                if (!$node instanceof DOMElement) {
                    continue;
                }

                $text = $this->normalizeText($node->textContent);

                if ($text === '') {
                    continue;
                }

                if (preg_match('/^h([1-6])$/', strtolower($node->tagName), $match) === 1) {
                    $this->flushSection($sections, $sectionTitle, $blocks, array_values($hierarchy));
                    $level = (int) $match[1];

                    foreach (array_keys($hierarchy) as $existingLevel) {
                        if ($existingLevel >= $level) {
                            unset($hierarchy[$existingLevel]);
                        }
                    }

                    $hierarchy[$level] = $text;
                    ksort($hierarchy);
                    $sectionTitle = $text;
                    $blocks = [$text];
                    continue;
                }

                $blocks[] = $text;
            }
        }

        $this->flushSection($sections, $sectionTitle, $blocks, array_values($hierarchy));

        if ($sections === []) {
            $text = $this->normalizeText($root->textContent);

            if ($text !== '') {
                $sections[] = new ExtractedSection($title, $text, [$title]);
            }
        }

        if ($sections === []) {
            throw new PermanentIngestionException('The HTML document contains no readable text.');
        }

        return ExtractedDocument::fromSections($title, $sections, $metadata);
    }

    private function firstText(DOMXPath $xpath, string $query): ?string
    {
        $node = $xpath->query($query)?->item(0);

        if (!$node instanceof DOMNode) {
            return null;
        }

        $text = $this->normalizeText($node->textContent);

        return $text === '' ? null : $text;
    }

    private function normalizeText(string $text): string
    {
        $text = str_replace(["\r\n", "\r", "\u{00A0}"], ["\n", "\n", ' '], $text);
        $text = preg_replace('/[\t ]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/ *\n */u', "\n", $text) ?? $text;
        $text = preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text;

        return trim($text);
    }

    /**
     * @param list<ExtractedSection> $sections
     * @param list<string> $blocks
     * @param list<string> $hierarchy
     */
    private function flushSection(array &$sections, string $title, array &$blocks, array $hierarchy): void
    {
        $blocks = array_values(array_unique(array_filter(array_map('trim', $blocks))));

        if ($blocks !== []) {
            $sections[] = new ExtractedSection($title, implode("\n\n", $blocks), $hierarchy);
        }

        $blocks = [];
    }
}
