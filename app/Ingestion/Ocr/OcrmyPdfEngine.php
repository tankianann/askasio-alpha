<?php

declare(strict_types=1);

namespace App\Ingestion\Ocr;

use App\Ingestion\IngestionException;
use App\Ingestion\PermanentIngestionException;
use InvalidArgumentException;

final class OcrmyPdfEngine implements PdfOcrEngineInterface
{
    /** @param list<string> $languages */
    public function __construct(
        private readonly string $binary,
        private readonly array $languages,
        private readonly int $processTimeoutSeconds,
        private readonly int $pageTimeoutSeconds,
        private readonly int $jobs,
        private readonly int $maximumOutputBytes,
        private readonly bool $rotatePages = true,
        private readonly bool $deskew = true,
    ) {
        if (trim($this->binary) === '') {
            throw new InvalidArgumentException('The OCRmyPDF binary must be configured.');
        }

        if ($this->languages === []) {
            throw new InvalidArgumentException('At least one OCR language must be configured.');
        }

        foreach ($this->languages as $language) {
            if (preg_match('/^[a-z]{3}(?:_[a-z0-9]+)?$/i', $language) !== 1) {
                throw new InvalidArgumentException('OCR languages must use Tesseract language codes.');
            }
        }

        if ($this->processTimeoutSeconds < 30 || $this->processTimeoutSeconds > 7200) {
            throw new InvalidArgumentException('The OCR process timeout must be between 30 and 7200 seconds.');
        }

        if ($this->pageTimeoutSeconds < 1 || $this->pageTimeoutSeconds > 1800) {
            throw new InvalidArgumentException('The OCR page timeout must be between 1 and 1800 seconds.');
        }

        if ($this->jobs < 1 || $this->jobs > 8) {
            throw new InvalidArgumentException('OCR jobs must be between 1 and 8.');
        }

        if ($this->maximumOutputBytes < 1024 * 1024) {
            throw new InvalidArgumentException('The OCR output limit must be at least one megabyte.');
        }
    }

    public function isAvailable(): bool
    {
        if (str_contains($this->binary, DIRECTORY_SEPARATOR)) {
            return is_file($this->binary) && is_executable($this->binary);
        }

        $path = getenv('PATH');

        if (!is_string($path)) {
            return false;
        }

        foreach (explode(PATH_SEPARATOR, $path) as $directory) {
            $candidate = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $this->binary;

            if (is_file($candidate) && is_executable($candidate)) {
                return true;
            }
        }

        return false;
    }

    public function recognize(string $inputPdf): OcrResult
    {
        if (!is_file($inputPdf) || !is_readable($inputPdf)) {
            throw new PermanentIngestionException('The PDF is unavailable for OCR.');
        }

        if (!$this->isAvailable() || !function_exists('proc_open')) {
            throw new PermanentIngestionException('PDF OCR is required but the configured OCRmyPDF engine is unavailable.');
        }

        $temporaryDirectory = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . 'rag-ocr-'
            . bin2hex(random_bytes(12));

        if (!mkdir($temporaryDirectory, 0700) && !is_dir($temporaryDirectory)) {
            throw new IngestionException('A private temporary directory could not be created for PDF OCR.');
        }

        $outputPdf = $temporaryDirectory . DIRECTORY_SEPARATOR . 'recognized.pdf';

        try {
            $this->run($this->command($inputPdf, $outputPdf), $outputPdf);

            if (!is_file($outputPdf) || !is_readable($outputPdf)) {
                throw new IngestionException('The OCR engine did not produce a readable PDF.');
            }

            $signature = file_get_contents($outputPdf, false, null, 0, 5);

            if ($signature !== '%PDF-') {
                throw new PermanentIngestionException('The OCR engine produced an invalid PDF.');
            }

            return new OcrResult($outputPdf, 'ocrmypdf', $this->languages, $temporaryDirectory);
        } catch (\Throwable $exception) {
            if (is_file($outputPdf)) {
                @unlink($outputPdf);
            }

            @rmdir($temporaryDirectory);
            throw $exception;
        }
    }

    /** @return list<string> */
    private function command(string $inputPdf, string $outputPdf): array
    {
        $command = [
            $this->binary,
            '--quiet',
            '--mode',
            'force',
            '--output-type',
            'pdf',
            '--optimize',
            '0',
            '--jobs',
            (string) $this->jobs,
            '--language',
            implode('+', $this->languages),
            '--tesseract-timeout',
            (string) $this->pageTimeoutSeconds,
            '--invalidate-digital-signatures',
            '--no-overwrite',
        ];

        if ($this->rotatePages) {
            $command[] = '--rotate-pages';
        }

        if ($this->deskew) {
            $command[] = '--deskew';
        }

        $command[] = $inputPdf;
        $command[] = $outputPdf;

        return $command;
    }

    /** @param list<string> $command */
    private function run(array $command, string $outputPdf): void
    {
        $process = proc_open(
            $command,
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            null,
            null,
            ['bypass_shell' => true],
        );

        if (!is_resource($process)) {
            throw new IngestionException('The OCR engine process could not be started.');
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $deadline = microtime(true) + $this->processTimeoutSeconds;
        $timedOut = false;
        $tooLarge = false;
        $exitCode = null;

        try {
            while (true) {
                stream_get_contents($pipes[1], 8192);
                stream_get_contents($pipes[2], 8192);
                clearstatcache(true, $outputPdf);

                if (is_file($outputPdf) && filesize($outputPdf) > $this->maximumOutputBytes) {
                    $tooLarge = true;
                    $this->terminate($process);
                    break;
                }

                $status = proc_get_status($process);

                if (!$status['running']) {
                    $exitCode = (int) $status['exitcode'];
                    break;
                }

                if (microtime(true) >= $deadline) {
                    $timedOut = true;
                    $this->terminate($process);
                    break;
                }

                usleep(100_000);
            }
        } finally {
            fclose($pipes[1]);
            fclose($pipes[2]);
            $closedExitCode = proc_close($process);
            $exitCode ??= $closedExitCode;
        }

        if ($timedOut) {
            throw new IngestionException('PDF OCR exceeded the configured processing timeout.');
        }

        if ($tooLarge) {
            throw new PermanentIngestionException('The OCR output exceeded the configured size limit.');
        }

        if ($exitCode !== 0) {
            throw new IngestionException('The OCR engine could not recognize the PDF.');
        }
    }

    /** @param resource $process */
    private function terminate($process): void
    {
        proc_terminate($process);
        usleep(250_000);
        $status = proc_get_status($process);

        if ($status['running']) {
            proc_terminate($process, 9);
        }
    }
}
