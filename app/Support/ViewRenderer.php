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
