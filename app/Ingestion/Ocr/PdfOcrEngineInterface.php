<?php

declare(strict_types=1);

namespace App\Ingestion\Ocr;

interface PdfOcrEngineInterface
{
    public function isAvailable(): bool;

    public function recognize(string $inputPdf): OcrResult;
}
