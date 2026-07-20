<?php

declare(strict_types=1);

namespace App\Ingestion\Extractors;

use App\Domain\Ingestion\ExtractedDocument;
use App\Domain\Ingestion\ExtractedSection;
use App\Domain\Sources\SourceType;
use App\Domain\Sources\SourceVersion;
use App\Ingestion\PermanentIngestionException;
use App\Ingestion\DocumentSafetyLimits;
use App\Ingestion\SourceExtractorInterface;
use App\Ingestion\Ocr\PdfOcrEngineInterface;
use App\Services\Sources\PrivateSourceFileLocator;
use Smalot\PdfParser\Parser;

final class PdfExtractor implements SourceExtractorInterface
{
    public function __construct(
        private readonly PrivateSourceFileLocator $files,
        private readonly ?PdfOcrEngineInterface $ocr = null,
        private readonly Parser $parser = new Parser(),
        private readonly ?DocumentSafetyLimits $limits = null,
    ) {
    }

    public function supports(SourceVersion $version): bool
    {
        return $version->sourceType === SourceType::Pdf
            || $version->mimeType === 'application/pdf';
    }

    public function extract(SourceVersion $version): ExtractedDocument
    {
        if ($version->storedFilePath === null) {
            throw new PermanentIngestionException('The PDF source version has no stored file.');
        }

        $path = $this->files->locate($version->storedFilePath);

        [$details, $pages] = $this->parse($path, 'The PDF could not be parsed or may be encrypted.');
        $this->limits?->assertPdfPageCount(count($pages));

        $filename = $version->originalFilename ?? basename($version->storedFilePath);
        $title = trim((string) ($details['Title'] ?? ''));
        $title = $title !== '' ? $title : pathinfo($filename, PATHINFO_FILENAME);
        $sections = $this->sections($pages);
        $ocrApplied = false;
        $ocrEngine = null;
        $ocrLanguages = [];

        if ($sections === []) {
            if ($this->ocr === null) {
                throw new PermanentIngestionException('The PDF contains no extractable text and PDF OCR is not enabled.');
            }

            $ocrResult = $this->ocr->recognize($path);

            try {
                [, $ocrPages] = $this->parse($ocrResult->pdfPath, 'The OCR output PDF could not be parsed.');
                $this->limits?->assertPdfPageCount(count($ocrPages));
                $sections = $this->sections($ocrPages);
                $ocrApplied = true;
                $ocrEngine = $ocrResult->engine;
                $ocrLanguages = $ocrResult->languages;
                $pages = $ocrPages;
            } finally {
                $ocrResult->cleanup();
            }

            if ($sections === []) {
                throw new PermanentIngestionException('PDF OCR completed but did not recognize readable text.');
            }
        }

        $document = ExtractedDocument::fromSections($title, $sections, [
            'source_type' => SourceType::Pdf->value,
            'original_filename' => $filename,
            'mime_type' => $version->mimeType,
            'page_count' => count($pages),
            'author' => isset($details['Author']) ? trim((string) $details['Author']) : null,
            'ocr_applied' => $ocrApplied,
            'ocr_engine' => $ocrEngine,
            'ocr_languages' => $ocrLanguages,
        ]);
        $this->limits?->assertDocument($document);

        return $document;
    }

    /** @return array{array<string, mixed>, array<int, object>} */
    private function parse(string $path, string $safeError): array
    {
        try {
            $pdf = $this->parser->parseFile($path);

            return [$pdf->getDetails(), $pdf->getPages()];
        } catch (\Throwable $exception) {
            throw new PermanentIngestionException($safeError, previous: $exception);
        }
    }

    /** @param array<int, object> $pages @return list<ExtractedSection> */
    private function sections(array $pages): array
    {
        $sections = [];
        $extractedCharacters = 0;

        foreach ($pages as $index => $page) {
            if (!method_exists($page, 'getText')) {
                continue;
            }

            $text = $this->normalizeText((string) $page->getText());

            if ($text === '') {
                continue;
            }

            $extractedCharacters += mb_strlen($text, 'UTF-8');
            $this->limits?->assertExtractedCharacters($extractedCharacters);

            $pageNumber = $index + 1;
            $sections[] = new ExtractedSection(
                'Page ' . $pageNumber,
                $text,
                [],
                $pageNumber,
                ['page' => $pageNumber],
            );
        }

        return $sections;
    }

    private function normalizeText(string $text): string
    {
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        $text = str_replace(["\r\n", "\r", "\u{00A0}"], ["\n", "\n", ' '], $text);
        $text = preg_replace('/[\t ]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/ *\n */u', "\n", $text) ?? $text;
        $text = preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text;

        return trim($text);
    }
}
