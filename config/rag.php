<?php

declare(strict_types=1);

use App\Support\Env;

return [
    'embedding_batch_size' => Env::int('RAG_EMBEDDING_BATCH_SIZE', 64),
    'retrieval_default_top_k' => Env::int('RAG_RETRIEVAL_DEFAULT_TOP_K', 8),
    'retrieval_maximum_top_k' => Env::int('RAG_RETRIEVAL_MAXIMUM_TOP_K', 20),
    'retrieval_minimum_similarity' => Env::float('RAG_RETRIEVAL_MINIMUM_SIMILARITY', 0.20),
    'retrieval_maximum_query_characters' => Env::int('RAG_RETRIEVAL_MAXIMUM_QUERY_CHARACTERS', 4000),
    'chat_default_top_k' => Env::int('RAG_CHAT_TOP_K', 5),
    'chat_maximum_top_k' => Env::int('RAG_CHAT_MAXIMUM_TOP_K', 8),
    'chat_context_maximum_tokens' => Env::int('RAG_CHAT_CONTEXT_MAX_TOKENS', 4000),
    'chat_history_maximum_tokens' => Env::int('RAG_CHAT_HISTORY_MAX_TOKENS', 1000),
    'chat_maximum_question_characters' => Env::int('RAG_CHAT_MAXIMUM_QUESTION_CHARACTERS', 4000),
    'api_maximum_body_bytes' => Env::int('API_MAXIMUM_BODY_BYTES', 65536),
];
