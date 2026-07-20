<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Ingestion\Ocr\OcrmyPdfEngine;
use App\Ingestion\PermanentIngestionException;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class OcrmyPdfEngineTest extends TestCase
{
    public function testItReportsAConfiguredBinaryThatIsUnavailable(): void
    {
        $engine = new OcrmyPdfEngine(
            '/definitely/missing/ocrmypdf',
            ['eng'],
            60,
            30,
            1,
            10 * 1024 * 1024,
        );

        self::assertFalse($engine->isAvailable());
    }

    public function testItFailsSafelyWhenOcrIsRequiredButUnavailable(): void
    {
        $path = sys_get_temp_dir() . '/rag-ocr-input-' . bin2hex(random_bytes(6)) . '.pdf';
        file_put_contents($path, "%PDF-1.4\n%%EOF\n");
        $engine = new OcrmyPdfEngine(
            '/definitely/missing/ocrmypdf',
            ['eng'],
            60,
            30,
            1,
            10 * 1024 * 1024,
        );

        try {
            $this->expectException(PermanentIngestionException::class);
            $this->expectExceptionMessage('configured OCRmyPDF engine is unavailable');
            $engine->recognize($path);
        } finally {
            @unlink($path);
        }
    }

    public function testItRejectsUnsafeLanguageCodesBeforeStartingAProcess(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new OcrmyPdfEngine(
            'ocrmypdf',
            ['eng;touch /tmp/not-allowed'],
            60,
            30,
            1,
            10 * 1024 * 1024,
        );
    }

    public function testItRejectsAPageTimeoutLongerThanTheOverallProcessTimeout(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot exceed');

        new OcrmyPdfEngine(
            'ocrmypdf',
            ['eng'],
            60,
            61,
            1,
            10 * 1024 * 1024,
        );
    }
}
