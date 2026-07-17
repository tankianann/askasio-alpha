<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Sources\ProcessingStatus;
use App\Domain\Sources\SourceType;
use App\Domain\Sources\SourceVersion;
use App\Ingestion\Extractors\PdfExtractor;
use App\Ingestion\Ocr\OcrResult;
use App\Ingestion\Ocr\PdfOcrEngineInterface;
use App\Services\Sources\PrivateSourceFileLocator;
use PHPUnit\Framework\TestCase;

final class PdfExtractorTest extends TestCase
{
    public function testItExtractsTextAndPreservesPageNumber(): void
    {
        $directory = sys_get_temp_dir() . '/rag-pdf-' . bin2hex(random_bytes(6));
        mkdir($directory, 0700);
        file_put_contents($directory . '/document.pdf', $this->minimalPdf('Refunds are available within thirty days.'));

        try {
            $version = new SourceVersion(
                1, 1, 1, 'refunds.pdf', null, 'document.pdf', null, 'application/pdf', 100,
                ProcessingStatus::Processing, null, '2026-01-01', null, null, null, SourceType::Pdf,
            );
            $document = (new PdfExtractor(new PrivateSourceFileLocator($directory)))->extract($version);

            self::assertStringContainsString('Refunds are available within thirty days.', $document->content);
            self::assertSame(1, $document->sections[0]->page);
            self::assertSame(1, $document->metadata['page_count']);
            self::assertFalse($document->metadata['ocr_applied']);
        } finally {
            @unlink($directory . '/document.pdf');
            @rmdir($directory);
        }
    }

    public function testItAutomaticallyUsesOcrWhenThePdfHasNoTextLayer(): void
    {
        $directory = sys_get_temp_dir() . '/rag-pdf-ocr-' . bin2hex(random_bytes(6));
        mkdir($directory, 0700);
        file_put_contents($directory . '/scan.pdf', $this->minimalPdf(''));
        file_put_contents($directory . '/recognized.pdf', $this->minimalPdf('Text recovered by OCR.'));
        $ocr = new class ($directory) implements PdfOcrEngineInterface {
            public int $calls = 0;

            public function __construct(private readonly string $directory)
            {
            }

            public function isAvailable(): bool
            {
                return true;
            }

            public function recognize(string $inputPdf): OcrResult
            {
                $this->calls++;

                return new OcrResult(
                    $this->directory . '/recognized.pdf',
                    'fake-ocr',
                    ['eng'],
                    $this->directory,
                );
            }
        };

        try {
            $version = new SourceVersion(
                2, 1, 2, 'scan.pdf', null, 'scan.pdf', null, 'application/pdf', 100,
                ProcessingStatus::Processing, null, '2026-01-01', null, null, null, SourceType::Pdf,
            );
            $document = (new PdfExtractor(new PrivateSourceFileLocator($directory), $ocr))->extract($version);

            self::assertSame(1, $ocr->calls);
            self::assertStringContainsString('Text recovered by OCR.', $document->content);
            self::assertTrue($document->metadata['ocr_applied']);
            self::assertSame('fake-ocr', $document->metadata['ocr_engine']);
            self::assertSame(['eng'], $document->metadata['ocr_languages']);
            self::assertFileDoesNotExist($directory . '/recognized.pdf');
        } finally {
            @unlink($directory . '/scan.pdf');
            @unlink($directory . '/recognized.pdf');
            @rmdir($directory);
        }
    }

    private function minimalPdf(string $text): string
    {
        $escaped = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
        $stream = "BT /F1 12 Tf 72 720 Td ({$escaped}) Tj ET";
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
            "<< /Length " . strlen($stream) . " >>\nstream\n{$stream}\nendstream",
        ];
        $pdf = "%PDF-1.4\n";
        $offsets = [0];

        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1) . " 0 obj\n{$object}\nendobj\n";
        }

        $xref = strlen($pdf);
        $pdf .= "xref\n0 6\n0000000000 65535 f \n";

        for ($index = 1; $index <= 5; $index++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$index]);
        }

        return $pdf . "trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
    }
}
