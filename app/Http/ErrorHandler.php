<?php

declare(strict_types=1);

namespace App\Http;

use App\Exceptions\HttpException;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Throwable;

final class ErrorHandler
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly string $viewDirectory,
    ) {
    }

    public function handle(Request $request, Throwable $exception): Response
    {
        $requestId = $request->attribute('request_id');
        $requestId = is_string($requestId) && $requestId !== '' ? $requestId : Uuid::uuid7()->toString();
        $status = $exception instanceof HttpException ? $exception->statusCode : 500;
        $code = $exception instanceof HttpException ? $exception->errorCode : 'internal_error';
        $safeMessage = $exception instanceof HttpException
            ? $exception->getMessage()
            : 'An unexpected error occurred.';

        $this->logger->error('HTTP request failed.', [
            'request_id' => $requestId,
            'method' => $request->method(),
            'path' => $request->path(),
            'status' => $status,
            'error_category' => $code,
            'exception' => $exception,
        ]);

        if ($request->expectsJson()) {
            return $this->withSecurityHeaders(Response::json([
                'error' => [
                    'code' => $code,
                    'message' => $safeMessage,
                    'request_id' => $requestId,
                ],
            ], $status)->withHeader('X-Request-ID', $requestId));
        }

        $title = match ($status) {
            404 => 'Page not found',
            419 => 'Form session expired',
            429 => 'Too many requests',
            default => 'Something went wrong',
        };
        $message = $safeMessage;
        $view = $status === 404 ? '404.php' : '500.php';

        ob_start();
        require $this->viewDirectory . '/' . $view;
        $html = (string) ob_get_clean();

        return $this->withSecurityHeaders(
            Response::html($html, $status)->withHeader('X-Request-ID', $requestId),
        );
    }

    private function withSecurityHeaders(Response $response): Response
    {
        return $response
            ->withHeader('Content-Security-Policy', "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'")
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->withHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
    }
}
