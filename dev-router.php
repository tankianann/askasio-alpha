<?php

declare(strict_types=1);

$publicPath = __DIR__ . '/public';
$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$candidate = realpath($publicPath . '/' . ltrim($requestPath, '/'));

if (
    $candidate !== false
    && str_starts_with($candidate, $publicPath . DIRECTORY_SEPARATOR)
    && is_file($candidate)
) {
    return false;
}

require $publicPath . '/index.php';
