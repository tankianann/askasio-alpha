<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Sources\SourceType;
use App\Exceptions\ValidationException;
use App\Http\UploadedFile;
use App\Security\SourceUploadValidator;
use PHPUnit\Framework\TestCase;

final class SourceUploadValidatorTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    public function testItAcceptsUtf8MarkdownAndReturnsDetectedMime(): void
    {
        $file = $this->upload('policy.md', "# Refunds\n\nRefunds are available.\n");
        $mime = (new SourceUploadValidator(1024 * 1024))->validate($file, SourceType::Markdown);

        self::assertContains($mime, ['text/plain', 'text/markdown', 'text/x-markdown']);
        self::assertSame(hash('sha256', "# Refunds\n\nRefunds are available.\n"), $file->sha256());
    }

    public function testItAcceptsPdfMimeAndSignature(): void
    {
        $file = $this->upload('policy.pdf', "%PDF-1.7\n1 0 obj\n<<>>\nendobj\n%%EOF\n");

        self::assertSame(
            'application/pdf',
            (new SourceUploadValidator(1024 * 1024))->validate($file, SourceType::Pdf),
        );
    }

    public function testItRejectsSpoofedPdfExtension(): void
    {
        $file = $this->upload('policy.pdf', 'This is not a PDF.');

        $this->expectException(ValidationException::class);
        (new SourceUploadValidator(1024 * 1024))->validate($file, SourceType::Pdf);
    }

    public function testItRejectsBinaryMarkdown(): void
    {
        $file = $this->upload('policy.md', "valid text\0binary");

        $this->expectException(ValidationException::class);
        (new SourceUploadValidator(1024 * 1024))->validate($file, SourceType::Markdown);
    }

    public function testItRejectsFilesOverConfiguredLimit(): void
    {
        $file = $this->upload('policy.md', str_repeat('a', 50));

        $this->expectException(ValidationException::class);
        (new SourceUploadValidator(20))->validate($file, SourceType::Markdown);
    }

    private function upload(string $name, string $contents): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'rag-upload-test-');

        if (!is_string($path)) {
            self::fail('Unable to create a temporary test file.');
        }

        file_put_contents($path, $contents);
        $this->temporaryFiles[] = $path;

        return new UploadedFile($name, $path, UPLOAD_ERR_OK, strlen($contents));
    }
}
