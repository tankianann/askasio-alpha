<?php

declare(strict_types=1);

namespace App\Services\Sources;

use App\Domain\Sources\ProcessingStatus;
use App\Domain\Sources\Source;
use App\Domain\Sources\SourceType;
use App\Domain\Sources\SourceVersion;
use App\Exceptions\ValidationException;
use App\Http\UploadedFile;
use App\Repositories\SourceRepositoryInterface;
use App\Security\SourceUploadValidator;
use App\Security\UrlSourceValidator;
use App\Services\Ingestion\IngestionQueue;
use Throwable;

final class SourceUpdateService
{
    public function __construct(
        private readonly SourceRepositoryInterface $sources,
        private readonly UrlSourceValidator $urls,
        private readonly SourceUploadValidator $uploads,
        private readonly SourceFileStorage $storage,
        private readonly IngestionQueue $queue,
    ) {
    }

    public function replaceUpload(Source $source, UploadedFile $file): SourceVersion
    {
        $this->assertFileSource($source);
        $mimeType = $this->uploads->validate($file, $source->type);
        $hash = $file->sha256();
        $size = $file->actualSize();
        $storedPath = null;

        try {
            return $this->sources->transaction(function () use (
                $source,
                $file,
                $mimeType,
                $hash,
                $size,
                &$storedPath,
            ): SourceVersion {
                $locked = $this->mutableLockedSource($source->id);
                $this->assertNoInFlightJob($locked->id);
                $storedPath = $this->storage->store($file, $locked->id, $locked->type);
                $version = $this->sources->createFileVersion(
                    $locked->id,
                    $file->originalName(),
                    $storedPath,
                    $hash,
                    $mimeType,
                    $size,
                );
                $this->queue->enqueue($version->id);

                return $version;
            });
        } catch (Throwable $exception) {
            if (is_string($storedPath)) {
                $this->storage->delete($storedPath);
            }

            throw $exception;
        }
    }

    public function refreshUrl(Source $source): SourceVersion
    {
        if ($source->type !== SourceType::Url) {
            throw new ValidationException('Only URL sources can be refreshed.');
        }

        $origin = $this->latestUrlOrigin($source->id);

        return $this->sources->transaction(function () use ($source, $origin): SourceVersion {
            $locked = $this->mutableLockedSource($source->id);
            $this->assertNoInFlightJob($locked->id);
            $version = $this->sources->createUrlVersion($locked->id, $origin);
            $this->queue->enqueue($version->id);

            return $version;
        });
    }

    public function reprocess(Source $source, SourceVersion $version): SourceVersion
    {
        if ($version->sourceId !== $source->id) {
            throw new ValidationException('The selected version does not belong to this source.');
        }

        if ($version->processingStatus === ProcessingStatus::Pending
            || $version->processingStatus === ProcessingStatus::Processing) {
            throw new ValidationException('A pending or processing version cannot be reprocessed.');
        }

        return $this->sources->transaction(function () use ($source, $version): SourceVersion {
            $locked = $this->mutableLockedSource($source->id);
            $this->assertNoInFlightJob($locked->id);

            if ($locked->type === SourceType::Url) {
                if ($version->originalUrl === null) {
                    throw new ValidationException('The selected URL version has no original URL.');
                }

                $copy = $this->sources->createUrlVersion($locked->id, $this->urls->validate($version->originalUrl));
            } else {
                if ($version->originalFilename === null
                    || $version->storedFilePath === null
                    || $version->fileHash === null
                    || $version->mimeType === null
                    || $version->fileSize === null) {
                    throw new ValidationException('The selected file version has incomplete origin metadata.');
                }

                $copy = $this->sources->createFileVersion(
                    $locked->id,
                    $version->originalFilename,
                    $version->storedFilePath,
                    $version->fileHash,
                    $version->mimeType,
                    $version->fileSize,
                );
            }

            $this->queue->enqueue($copy->id);

            return $copy;
        });
    }

    private function mutableLockedSource(int $sourceId): Source
    {
        $source = $this->sources->lockById($sourceId);

        if (!$source instanceof Source) {
            throw new ValidationException('The source no longer exists.');
        }

        if ($source->isDeleted()) {
            throw new ValidationException('A deleted source cannot be updated.');
        }

        return $source;
    }

    private function assertFileSource(Source $source): void
    {
        if (!in_array($source->type, [SourceType::Markdown, SourceType::Pdf], true)) {
            throw new ValidationException('URL sources are refreshed instead of receiving replacement uploads.');
        }

        if ($source->isDeleted()) {
            throw new ValidationException('A deleted source cannot be updated.');
        }
    }

    private function assertNoInFlightJob(int $sourceId): void
    {
        if ($this->sources->hasInFlightJobs($sourceId)) {
            throw new ValidationException('Wait for the current source job to finish before creating another version.');
        }
    }

    private function latestUrlOrigin(int $sourceId): string
    {
        foreach ($this->sources->versionsForSource($sourceId) as $version) {
            if ($version->originalUrl !== null) {
                return $this->urls->validate($version->originalUrl);
            }
        }

        throw new ValidationException('This URL source has no refreshable origin URL.');
    }
}
