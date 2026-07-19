<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Domain\RAG\RetrievedChunk;
use App\Exceptions\HttpException;
use App\Http\JsonRequestParser;
use App\Http\Request;
use App\Http\Response;
use App\RAG\Retriever;

final class RetrieveController
{
    public function __construct(
        private readonly Retriever $retriever,
        private readonly int $maximumTopK,
        private readonly int $maximumQueryCharacters,
        private readonly JsonRequestParser $json,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $payload = $this->json->object($request);

        $query = $payload['query'] ?? null;

        if (!is_string($query) || trim($query) === '') {
            throw new HttpException(422, 'The query field is required.', 'invalid_request');
        }

        $query = trim($query);

        if (mb_strlen($query) > $this->maximumQueryCharacters) {
            throw new HttpException(
                422,
                sprintf('The query may not exceed %d characters.', $this->maximumQueryCharacters),
                'invalid_request',
            );
        }

        $topK = $payload['top_k'] ?? null;

        if ($topK !== null && (!is_int($topK) || $topK < 1 || $topK > $this->maximumTopK)) {
            throw new HttpException(
                422,
                sprintf('top_k must be an integer between 1 and %d.', $this->maximumTopK),
                'invalid_request',
            );
        }

        $filters = $this->validateFilters($payload['filters'] ?? null);
        $matches = $this->retriever->retrieve($query, $topK, $filters);
        $requestId = $request->attribute('request_id');

        return Response::json([
            'matches' => array_map($this->serializeMatch(...), $matches),
            'usage' => ['retrieved_chunks' => count($matches)],
            'request_id' => is_string($requestId) ? $requestId : null,
        ]);
    }

    /** @return array<string, mixed> */
    private function validateFilters(mixed $filters): array
    {
        if ($filters === null) {
            return [];
        }

        if (!is_array($filters) || array_is_list($filters)) {
            throw new HttpException(422, 'filters must be a JSON object.', 'invalid_request');
        }

        $allowed = ['source_ids', 'source_types'];

        foreach (array_keys($filters) as $key) {
            if (!is_string($key) || !in_array($key, $allowed, true)) {
                throw new HttpException(422, 'An unsupported retrieval filter was supplied.', 'invalid_request');
            }
        }

        if (isset($filters['source_ids'])) {
            if (!is_array($filters['source_ids']) || $filters['source_ids'] === []) {
                throw new HttpException(422, 'filters.source_ids must be a non-empty array.', 'invalid_request');
            }

            foreach ($filters['source_ids'] as $id) {
                if (!is_int($id) || $id < 1) {
                    throw new HttpException(422, 'filters.source_ids must contain positive integers.', 'invalid_request');
                }
            }
        }

        if (isset($filters['source_types'])) {
            if (!is_array($filters['source_types']) || $filters['source_types'] === []) {
                throw new HttpException(422, 'filters.source_types must be a non-empty array.', 'invalid_request');
            }

            foreach ($filters['source_types'] as $type) {
                if (!is_string($type) || !in_array($type, ['url', 'markdown', 'pdf'], true)) {
                    throw new HttpException(422, 'filters.source_types contains an unsupported source type.', 'invalid_request');
                }
            }
        }

        return $filters;
    }

    /** @return array<string, mixed> */
    private function serializeMatch(RetrievedChunk $match): array
    {
        return [
            'source_id' => $match->sourceId,
            'source_version_id' => $match->sourceVersionId,
            'chunk_id' => $match->chunkId,
            'chunk_number' => $match->chunkNumber,
            'similarity' => round($match->similarity, 6),
            'content' => $match->content,
            'source_name' => $match->sourceName,
            'source_type' => $match->sourceType,
            'source_url' => $match->sourceUrl,
            'page' => $match->page(),
            'heading' => $match->heading(),
            'metadata' => $match->metadata,
        ];
    }
}
