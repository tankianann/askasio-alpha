<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

final class ViewRenderer
{
    public function __construct(private readonly string $viewDirectory)
    {
    }

    /** @param array<string, mixed> $data */
    public function render(string $view, array $data = [], ?string $layout = null): string
    {
        $escape = static fn (mixed $value): string => htmlspecialchars(
            (string) $value,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8',
        );
        $formatDate = static function (?string $value): string {
            if ($value === null || $value === '') {
                return '—';
            }

            $date = new \DateTimeImmutable($value, new \DateTimeZone('UTC'));

            return $date->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('j M Y, g:i a');
        };
        $formatBytes = static function (?int $bytes): string {
            if ($bytes === null) {
                return '—';
            }

            if ($bytes < 1024) {
                return $bytes . ' B';
            }

            if ($bytes < 1024 * 1024) {
                return number_format($bytes / 1024, 1) . ' KB';
            }

            return number_format($bytes / (1024 * 1024), 1) . ' MB';
        };
        $formatJson = static fn (array $value): string => json_encode(
            $value,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
        $viewFile = $this->resolve($view);
        extract($data, EXTR_SKIP);

        ob_start();
        require $viewFile;
        $content = (string) ob_get_clean();

        if ($layout === null) {
            return $content;
        }

        $layoutFile = $this->resolve($layout);
        ob_start();
        require $layoutFile;

        return (string) ob_get_clean();
    }

    private function resolve(string $view): string
    {
        if (preg_match('/^[A-Za-z0-9_\/-]+$/', $view) !== 1) {
            throw new RuntimeException('Invalid view name.');
        }

        $file = $this->viewDirectory . '/' . $view . '.php';

        if (!is_file($file)) {
            throw new RuntimeException(sprintf('View %s does not exist.', $view));
        }

        return $file;
    }
}
