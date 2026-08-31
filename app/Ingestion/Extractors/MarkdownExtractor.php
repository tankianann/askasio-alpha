<?php

declare(strict_types=1);

namespace App\Ingestion\Extractors;

use App\Domain\Ingestion\ExtractedDocument;
use App\Domain\Sources\SourceType;
use App\Domain\Sources\SourceVersion;
use App\Exceptions\ValidationException;
use App\Ingestion\DocumentSafetyLimits;
use App\Ingestion\PermanentIngestionException;
use App\Ingestion\SourceExtractorInterface;
use App\Security\UrlSourceValidator;
use App\Services\Sources\PrivateSourceFileLocator;
use League\CommonMark\CommonMarkConverter;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

final class MarkdownExtractor implements SourceExtractorInterface
{
    private readonly CommonMarkConverter $converter;
    private readonly UrlSourceValidator $urls;

    public function __construct(
        private readonly PrivateSourceFileLocator $files,
        private readonly HtmlDocumentParser $html,
        private readonly ?DocumentSafetyLimits $limits = null,
        ?UrlSourceValidator $urls = null,
    ) {
        $this->urls = $urls ?? new UrlSourceValidator();
        $this->converter = new CommonMarkConverter([
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);
    }

    public function supports(SourceVersion $version): bool
    {
        return $version->sourceType === SourceType::Markdown
            || ($version->storedFilePath !== null
                && in_array(strtolower(pathinfo($version->originalFilename ?? '', PATHINFO_EXTENSION)), ['md', 'markdown'], true));
    }

    public function extract(SourceVersion $version): ExtractedDocument
    {
        if ($version->storedFilePath === null) {
            throw new PermanentIngestionException('The Markdown source version has no stored file.');
        }

        $path = $this->files->locate($version->storedFilePath);
        $fileSize = filesize($path);

        if (is_int($fileSize)
            && $this->limits instanceof DocumentSafetyLimits
            && $fileSize > $this->limits->maximumExtractedCharacters * 4) {
            throw new PermanentIngestionException('The Markdown source is too large to extract safely.');
        }

        $markdown = file_get_contents($path);

        if (!is_string($markdown) || trim($markdown) === '' || preg_match('//u', $markdown) !== 1) {
            throw new PermanentIngestionException('The Markdown source does not contain valid UTF-8 text.');
        }

        $this->limits?->assertExtractedCharacters(mb_strlen($markdown, 'UTF-8'));

        $filename = $version->originalFilename ?? basename($version->storedFilePath);
        $metadata = [
            'source_type' => SourceType::Markdown->value,
            'original_filename' => $filename,
            'mime_type' => $version->mimeType,
        ];
        $frontMatterPattern = '/\A(?:\xEF\xBB\xBF)?---[\t ]*\R(.*?)\R---[\t ]*(?:\R|\z)/s';

        if (preg_match($frontMatterPattern, $markdown, $matches) === 1) {
            $metadata = [...$metadata, ...$this->frontMatterMetadata($matches[1])];
            $markdown = preg_replace($frontMatterPattern, '', $markdown) ?? $markdown;
        }

        $rendered = $this->converter->convert($markdown)->getContent();
        $fallbackTitle = pathinfo($filename, PATHINFO_FILENAME);

        return $this->html->parse($rendered, $fallbackTitle, $metadata);
    }

    /** @return array<string, string> */
    private function frontMatterMetadata(string $yaml): array
    {
        try {
            $frontMatter = Yaml::parse($yaml);
        } catch (ParseException) {
            throw new PermanentIngestionException('The Markdown frontmatter contains invalid YAML.');
        }

        if (!is_array($frontMatter)) {
            return [];
        }

        $canonical = $frontMatter['canonical_url'] ?? $frontMatter['canonical'] ?? null;

        if ($canonical === null) {
            return [];
        }

        if (!is_string($canonical)) {
            throw new PermanentIngestionException('The Markdown canonical URL must be text.');
        }

        try {
            return ['canonical_url' => $this->urls->validate($canonical)];
        } catch (ValidationException $exception) {
            throw new PermanentIngestionException('The Markdown canonical URL must be a public HTTP or HTTPS URL.', previous: $exception);
        }
    }
}
