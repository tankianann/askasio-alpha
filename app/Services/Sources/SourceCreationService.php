<?php

declare(strict_types=1);

namespace App\Services\Sources;

use App\Domain\Sources\Source;
use App\Domain\Sources\SourceType;
use App\Exceptions\ValidationException;
use App\Http\UploadedFile;
use App\Repositories\SourceRepositoryInterface;
use App\Security\SourceUploadValidator;
use App\Security\UrlSourceValidator;
use App\Services\Ingestion\IngestionQueue;
use Throwable;

final class SourceCreationService
{
    public function __construct(
        private readonly SourceRepositoryInterface $sources,
        private readonly UrlSourceValidator $urls,
        private readonly SourceUploadValidator $uploads,
        private readonly SourceFileStorage $storage,
        private readonly IngestionQueue $queue,
        private readonly MarkdownFrontMatterTitleParser $frontMatterTitles,
    ) {
    }

    public function createUrl(string $name, string $url): Source
    {
        $name = $this->validateName($name);
        $url = $this->urls->validate($url);

        return $this->sources->transaction(function () use ($name, $url): Source {
            $source = $this->sources->createSource($name, SourceType::Url);
            $version = $this->sources->createUrlVersion($source->id, $url);
            $this->queue->enqueue($version->id);

            return $source;
        });
    }

    public function createUpload(string $name, SourceType $type, UploadedFile $file): Source
    {
        $name = $this->validateName($name);
        $mimeType = $this->uploads->validate($file, $type);

        return $this->persistUpload($name, $type, $file, $mimeType);
    }

    public function createMarkdownFromFrontMatter(UploadedFile $file): Source
    {
        $mimeType = $this->uploads->validate($file, SourceType::Markdown);
        $name = $this->validateName($this->frontMatterTitles->title($file));

        return $this->persistUpload($name, SourceType::Markdown, $file, $mimeType);
    }

    private function persistUpload(string $name, SourceType $type, UploadedFile $file, string $mimeType): Source
    {
        $hash = $file->sha256();
        $fileSize = $file->actualSize();
        $storedPath = null;

        try {
            return $this->sources->transaction(function () use (
                $name,
                $type,
                $file,
                $mimeType,
                $hash,
                $fileSize,
                &$storedPath,
            ): Source {
                $source = $this->sources->createSource($name, $type);
                $storedPath = $this->storage->store($file, $source->id, $type);
                $version = $this->sources->createFileVersion(
                    $source->id,
                    $file->originalName(),
                    $storedPath,
                    $hash,
                    $mimeType,
                    $fileSize,
                );
                $this->queue->enqueue($version->id);

                return $source;
            });
        } catch (Throwable $exception) {
            if (is_string($storedPath)) {
                $this->storage->delete($storedPath);
            }

            throw $exception;
        }
    }

    private function validateName(string $name): string
    {
        $name = trim($name);

        if ($name === '' || mb_strlen($name, 'UTF-8') > 190 || preg_match('/[\x00-\x1F\x7F]/', $name) === 1) {
            throw new ValidationException('Name is required and must not exceed 190 characters.');
        }

        return $name;
    }
}
