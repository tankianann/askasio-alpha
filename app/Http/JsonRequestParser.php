<?php

declare(strict_types=1);

namespace App\Http;

use App\Exceptions\HttpException;
use JsonException;

final class JsonRequestParser
{
    public function __construct(private readonly int $maximumBodyBytes)
    {
        if ($this->maximumBodyBytes < 2) {
            throw new \InvalidArgumentException('The JSON request body limit is invalid.');
        }
    }

    /** @return array<string, mixed> */
    public function object(Request $request): array
    {
        $contentType = strtolower(trim(explode(';', (string) $request->header('content-type', ''))[0]));

        if ($contentType !== 'application/json') {
            throw new HttpException(415, 'Content-Type must be application/json.', 'unsupported_media_type');
        }

        if (strlen($request->rawBody()) > $this->maximumBodyBytes) {
            throw new HttpException(413, 'The request body is too large.', 'request_too_large');
        }

        try {
            $payload = json_decode($request->rawBody(), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new HttpException(400, 'The request body contains invalid JSON.', 'invalid_json');
        }

        $emptyObject = $payload === [] && preg_match('/\A\s*\{\s*\}\s*\z/', $request->rawBody()) === 1;

        if (!is_array($payload) || (array_is_list($payload) && !$emptyObject)) {
            throw new HttpException(400, 'The request body must be a JSON object.', 'invalid_request');
        }

        return $payload;
    }
}
