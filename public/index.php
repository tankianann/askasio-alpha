<?php

declare(strict_types=1);

use App\Http\ErrorHandler;
use App\Http\Request;
use App\Http\Router;
use Ramsey\Uuid\Uuid;

ini_set('display_errors', '0');
header_remove('X-Powered-By');
$requestId = bin2hex(random_bytes(16));
$request = null;
$errorHandler = null;

try {
    $application = require dirname(__DIR__) . '/bootstrap/app.php';
    /** @var Router $router */
    $router = $application['router'];
    /** @var ErrorHandler $errorHandler */
    $errorHandler = $application['error_handler'];
    $requestId = Uuid::uuid7()->toString();
    $request = Request::fromGlobals()->withAttribute('request_id', $requestId);
    $response = $router->dispatch($request);
} catch (Throwable $exception) {
    if ($errorHandler instanceof ErrorHandler && $request instanceof Request) {
        $response = $errorHandler->handle($request, $exception);
    } else {
        error_log(sprintf('Application bootstrap failed. Request ID: %s. Exception: %s', $requestId, $exception::class));
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('X-Request-ID: ' . $requestId);
        echo 'The application could not start. Request ID: ' . $requestId;
        exit;
    }
}

$response->send();
