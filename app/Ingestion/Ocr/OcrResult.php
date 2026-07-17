<?php

declare(strict_types=1);

namespace App\Ingestion\Ocr;

final class OcrResult
{
    private bool $cleaned = false;

    /** @param list<string> $languages */
    public function __construct(
        public readonly string $pdfPath,
        public readonly string $engine,
        public readonly array $languages,
        private readonly string $temporaryDirectory,
    ) {
    }

    public function cleanup(): void
    {
        if ($this->cleaned) {
            return;
        }

        $this->cleaned = true;

        if (is_file($this->pdfPath)) {
            @unlink($this->pdfPath);
        }

        if (is_dir($this->temporaryDirectory)) {
            @rmdir($this->temporaryDirectory);
        }
    }

    public function __destruct()
    {
        $this->cleanup();
    }
}
