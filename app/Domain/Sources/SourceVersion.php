<?php

declare(strict_types=1);

namespace App\Domain\Sources;

final class SourceVersion
{
    public function __construct(
        public readonly int $id,
        public readonly int $sourceId,
        public readonly int $versionNumber,
        public readonly ?string $originalFilename,
        public readonly ?string $originalUrl,
        public readonly ?string $storedFilePath,
        public readonly ?string $contentHash,
        public readonly ?string $mimeType,
        public readonly ?int $fileSize,
        public readonly ProcessingStatus $processingStatus,
        public readonly ?string $errorMessage,
        public readonly string $createdAt,
        public readonly ?string $processedAt,
        public readonly ?string $activatedAt,
    ) {
    }
}
