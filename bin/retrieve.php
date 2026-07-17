<?php

declare(strict_types=1);

use App\Domain\RAG\RetrievedChunk;
use App\RAG\Retriever;

$arguments = array_values(array_slice($argv, 1));
$topK = null;
$minimumSimilarity = null;
$queryParts = [];

foreach ($arguments as $argument) {
    if (str_starts_with($argument, '--top-k=')) {
        $rawTopK = substr($argument, strlen('--top-k='));

        if (filter_var($rawTopK, FILTER_VALIDATE_INT) === false) {
            fwrite(STDERR, "--top-k must be an integer.\n");
            exit(2);
        }

        $topK = (int) $rawTopK;
        continue;
    }

    if (str_starts_with($argument, '--min-similarity=')) {
        $rawThreshold = substr($argument, strlen('--min-similarity='));

        if (!is_numeric($rawThreshold) || (float) $rawThreshold < -1.0 || (float) $rawThreshold > 1.0) {
            fwrite(STDERR, "--min-similarity must be between -1 and 1.\n");
            exit(2);
        }

        $minimumSimilarity = (float) $rawThreshold;
        continue;
    }

    $queryParts[] = $argument;
}

$query = trim(implode(' ', $queryParts));

if ($query === '') {
    fwrite(STDERR, "Usage: php bin/retrieve.php \"your query\" [--top-k=8] [--min-similarity=0.20]\n");
    exit(2);
}

try {
    $application = require dirname(__DIR__) . '/bootstrap/rag.php';
    /** @var Retriever $retriever */
    $retriever = $application['retriever'];
    $filters = $minimumSimilarity === null ? [] : ['minimum_similarity' => $minimumSimilarity];
    $matches = $retriever->retrieve($query, $topK, $filters);
    $output = array_map(
        static fn (RetrievedChunk $match): array => [
            'similarity' => round($match->similarity, 6),
            'source_id' => $match->sourceId,
            'source_version_id' => $match->sourceVersionId,
            'chunk_id' => $match->chunkId,
            'chunk_number' => $match->chunkNumber,
            'source_name' => $match->sourceName,
            'source_type' => $match->sourceType,
            'source_url' => $match->sourceUrl,
            'page' => $match->page(),
            'heading' => $match->heading(),
            'content' => $match->content,
        ],
        $matches,
    );
    fwrite(STDOUT, json_encode([
        'matches' => $output,
        'usage' => ['retrieved_chunks' => count($output)],
    ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL);
} catch (Throwable $exception) {
    fwrite(STDERR, "Retrieval failed. Review the provider configuration and application log.\n");
    exit(1);
}
