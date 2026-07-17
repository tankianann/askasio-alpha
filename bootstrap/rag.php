<?php

declare(strict_types=1);

use App\Database\Connection;
use App\Providers\Embeddings\EmbeddingProviderFactory;
use App\RAG\CosineSimilarity;
use App\RAG\EmbeddingBackfillService;
use App\RAG\PdoVectorStore;
use App\RAG\Retriever;
use App\Support\Config;
use Dotenv\Dotenv;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
Dotenv::createImmutable($root)->safeLoad();
$config = Config::load($root . '/config');
$connection = new Connection($config);
$provider = (new EmbeddingProviderFactory($config))->create();
$vectors = new PdoVectorStore($connection, new CosineSimilarity());

return [
    'config' => $config,
    'connection' => $connection,
    'provider' => $provider,
    'vectors' => $vectors,
    'retriever' => new Retriever(
        $provider,
        $vectors,
        $config->requireInt('rag.retrieval_default_top_k'),
        $config->requireInt('rag.retrieval_maximum_top_k'),
        (float) $config->get('rag.retrieval_minimum_similarity'),
    ),
    'backfill' => new EmbeddingBackfillService(
        $provider,
        $vectors,
        $config->requireInt('rag.embedding_batch_size'),
    ),
];
