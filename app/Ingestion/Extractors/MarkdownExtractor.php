<?php

declare(strict_types=1);

namespace App\Ingestion\Extractors;

use App\Domain\Ingestion\ExtractedDocument;
use App\Domain\Sources\SourceType;
use App\Domain\Sources\SourceVersion;
use App\Ingestion\PermanentIngestionException;
use App\Ingestion\SourceExtractorInterface;
use App\Services\Sources\PrivateSourceFileLocator;
use League\CommonMark\CommonMarkConverter;

final class MarkdownExtractor implements SourceExtractorInterface
{
    private readonly CommonMarkConverter $converter;

    public function __construct(
        private readonly PrivateSourceFileLocator $files,
        private readonly HtmlDocumentParser $html,
    ) {
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
        $markdown = file_get_contents($path);

        if (!is_string($markdown) || trim($markdown) === '' || preg_match('//u', $markdown) !== 1) {
            throw new PermanentIngestionException('The Markdown source does not contain valid UTF-8 text.');
        }

        $markdown = preg_replace('/\A---\s*\R.*?\R---\s*\R/s', '', $markdown) ?? $markdown;
        $rendered = $this->converter->convert($markdown)->getContent();
        $filename = $version->originalFilename ?? basename($version->storedFilePath);
        $fallbackTitle = pathinfo($filename, PATHINFO_FILENAME);

        return $this->html->parse($rendered, $fallbackTitle, [
            'source_type' => SourceType::Markdown->value,
            'original_filename' => $filename,
            'mime_type' => $version->mimeType,
        ]);
    }
}
