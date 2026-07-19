<?php

declare(strict_types=1);

namespace App\Services\Sources;

use App\Domain\Sources\Source;
use App\Exceptions\ValidationException;
use App\Repositories\SourceRepositoryInterface;
use Psr\Log\LoggerInterface;
use Throwable;

final class SourcePermanentDeletionService
{
    public function __construct(
        private readonly SourceRepositoryInterface $sources,
        private readonly SourceFileStorage $storage,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function delete(Source $source, string $confirmation): void
    {
        if (!hash_equals($source->name, $confirmation)) {
            throw new ValidationException('Type the source name exactly to confirm permanent deletion.');
        }

        $staged = null;

        try {
            $this->sources->transaction(function () use ($source, &$staged): void {
                $locked = $this->sources->lockById($source->id);

                if (!$locked instanceof Source) {
                    throw new ValidationException('The source no longer exists.');
                }

                if (!$locked->isDeleted()) {
                    throw new ValidationException('Soft-delete the source before deleting it permanently.');
                }

                if ($this->sources->hasInFlightJobs($locked->id)) {
                    throw new ValidationException('Wait for pending or processing source jobs before permanent deletion.');
                }

                $staged = $this->storage->stageSourceDirectory($locked->id);
                $this->sources->permanentlyDelete($locked->id);
            });
        } catch (Throwable $exception) {
            if ($staged instanceof StagedSourceDirectory) {
                $this->storage->restoreStagedDirectory($staged);
            }

            throw $exception;
        }

        if ($staged instanceof StagedSourceDirectory) {
            try {
                $this->storage->finalizeStagedDirectory($staged);
            } catch (Throwable $exception) {
                $this->logger->critical('A permanently deleted source left files in private deletion staging.', [
                    'source_id' => $source->id,
                    'exception' => $exception,
                ]);
            }
        }
    }
}
