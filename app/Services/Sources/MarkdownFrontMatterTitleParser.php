<?php

declare(strict_types=1);

namespace App\Services\Sources;

use App\Exceptions\ValidationException;
use App\Http\UploadedFile;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

final class MarkdownFrontMatterTitleParser
{
    public function title(UploadedFile $file): string
    {
        $markdown = file_get_contents($file->path());

        if (!is_string($markdown)) {
            throw new ValidationException('The Markdown file could not be read.');
        }

        $markdown = preg_replace('/\A\xEF\xBB\xBF/', '', $markdown) ?? $markdown;

        if (preg_match('/\A---[\t ]*\R(.*?)\R---[\t ]*(?:\R|\z)/s', $markdown, $matches) !== 1) {
            throw new ValidationException('The Markdown file must begin with YAML frontmatter enclosed by --- lines.');
        }

        try {
            $frontMatter = Yaml::parse($matches[1]);
        } catch (ParseException) {
            throw new ValidationException('The Markdown frontmatter contains invalid YAML.');
        }

        if (!is_array($frontMatter) || !array_key_exists('title', $frontMatter)) {
            throw new ValidationException('The Markdown frontmatter must contain a title field.');
        }

        if (!is_string($frontMatter['title'])) {
            throw new ValidationException('The Markdown frontmatter title must be text.');
        }

        return $frontMatter['title'];
    }
}
