<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exceptions\ValidationException;
use App\Http\UploadedFile;
use App\Services\Sources\MarkdownFrontMatterTitleParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MarkdownFrontMatterTitleParserTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryFiles = [];

    public function testItReadsAQuotedTitleFromYamlFrontmatter(): void
    {
        $file = $this->markdown("\xEF\xBB\xBF---\ntitle: \"Week 15: Are You Lucky?\"\nsource_id: 1314\n---\n\n# Are You Lucky?\n");

        self::assertSame(
            'Week 15: Are You Lucky?',
            (new MarkdownFrontMatterTitleParser())->title($file),
        );
    }

    #[DataProvider('invalidFrontmatter')]
    public function testItRejectsMissingOrInvalidTextTitles(string $contents, string $message): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage($message);

        (new MarkdownFrontMatterTitleParser())->title($this->markdown($contents));
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidFrontmatter(): iterable
    {
        yield 'missing block' => ["# Heading\n", 'must begin with YAML frontmatter'];
        yield 'missing title' => ["---\nslug: handbook\n---\nBody\n", 'must contain a title field'];
        yield 'non-text title' => ["---\ntitle:\n  - one\n  - two\n---\nBody\n", 'title must be text'];
        yield 'invalid yaml' => ["---\ntitle: [broken\n---\nBody\n", 'contains invalid YAML'];
    }

    private function markdown(string $contents): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'frontmatter-test-');
        self::assertIsString($path);
        file_put_contents($path, $contents);
        $this->temporaryFiles[] = $path;

        return new UploadedFile('document.md', $path, UPLOAD_ERR_OK, strlen($contents));
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        $this->temporaryFiles = [];
    }
}
