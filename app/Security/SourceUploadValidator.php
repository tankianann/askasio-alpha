<?php

declare(strict_types=1);

namespace App\Security;

use App\Domain\Sources\SourceType;
use App\Exceptions\ValidationException;
use App\Http\UploadedFile;
use finfo;

final class SourceUploadValidator
{
    public function __construct(private readonly int $maximumBytes)
    {
        if ($this->maximumBytes < 1) {
            throw new \InvalidArgumentException('Maximum upload size must be positive.');
        }
    }

    public function validate(UploadedFile $file, SourceType $type): string
    {
        if (!in_array($type, [SourceType::Markdown, SourceType::Pdf], true)) {
            throw new ValidationException('This source type does not accept file uploads.');
        }

        if ($file->error() !== UPLOAD_ERR_OK) {
            throw new ValidationException($this->uploadErrorMessage($file->error()));
        }

        $size = $file->actualSize();

        if ($size < 1) {
            throw new ValidationException('The uploaded file is empty.');
        }

        if ($size > $this->maximumBytes || $file->reportedSize() > $this->maximumBytes) {
            throw new ValidationException('The uploaded file exceeds the configured size limit.');
        }

        $originalName = $file->originalName();

        if ($originalName === ''
            || strlen($originalName) > 255
            || preg_match('/[\x00-\x1F\x7F]/', $originalName) === 1) {
            throw new ValidationException('The original filename is invalid.');
        }

        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file->path());

        if (!is_string($mime)) {
            throw new ValidationException('The uploaded file type could not be detected.');
        }

        if ($type === SourceType::Pdf) {
            if ($extension !== 'pdf' || $mime !== 'application/pdf') {
                throw new ValidationException('PDF sources must be valid .pdf files.');
            }

            $prefix = file_get_contents($file->path(), false, null, 0, 5);

            if ($prefix !== '%PDF-') {
                throw new ValidationException('The uploaded file does not have a valid PDF signature.');
            }

            return $mime;
        }

        if (!in_array($extension, ['md', 'markdown'], true)
            || !in_array($mime, ['text/plain', 'text/markdown', 'text/x-markdown'], true)) {
            throw new ValidationException('Markdown sources must be valid .md or .markdown text files.');
        }

        $contents = file_get_contents($file->path());

        if (!is_string($contents)
            || str_contains($contents, "\0")
            || preg_match('//u', $contents) !== 1) {
            throw new ValidationException('Markdown sources must contain valid UTF-8 text.');
        }

        return $mime;
    }

    private function uploadErrorMessage(int $error): string
    {
        return match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The uploaded file exceeds the allowed size.',
            UPLOAD_ERR_PARTIAL => 'The file upload was incomplete. Please try again.',
            UPLOAD_ERR_NO_FILE => 'Choose a file to upload.',
            default => 'The file could not be uploaded.',
        };
    }
}
