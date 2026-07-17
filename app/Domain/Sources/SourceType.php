<?php

declare(strict_types=1);

namespace App\Domain\Sources;

enum SourceType: string
{
    case Url = 'url';
    case Markdown = 'markdown';
    case Pdf = 'pdf';

    public function label(): string
    {
        return match ($this) {
            self::Url => 'URL',
            self::Markdown => 'Markdown',
            self::Pdf => 'PDF',
        };
    }
}
