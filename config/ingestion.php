<?php

declare(strict_types=1);

use App\Support\Env;

return [
    'chunk_size_tokens' => Env::int('RAG_CHUNK_SIZE_TOKENS', 500),
    'chunk_overlap_tokens' => Env::int('RAG_CHUNK_OVERLAP_TOKENS', 75),
    'minimum_chunk_tokens' => Env::int('RAG_MINIMUM_CHUNK_TOKENS', 20),
    'url_connect_timeout_seconds' => Env::int('URL_CONNECT_TIMEOUT_SECONDS', 5),
    'url_request_timeout_seconds' => Env::int('URL_REQUEST_TIMEOUT_SECONDS', 20),
    'url_maximum_redirects' => Env::int('URL_MAXIMUM_REDIRECTS', 5),
    'url_maximum_response_bytes' => Env::int('URL_MAXIMUM_RESPONSE_BYTES', 5 * 1024 * 1024),
    'url_user_agent' => Env::string('URL_USER_AGENT', 'RAGServer/1.0'),
    'pdf_ocr_enabled' => Env::bool('PDF_OCR_ENABLED', true),
    'pdf_ocr_binary' => Env::string('PDF_OCR_BINARY', 'ocrmypdf'),
    'pdf_ocr_languages' => array_values(array_filter(array_map(
        'trim',
        explode('+', Env::string('PDF_OCR_LANGUAGES', 'eng') ?? 'eng'),
    ))),
    'pdf_ocr_process_timeout_seconds' => Env::int('PDF_OCR_PROCESS_TIMEOUT_SECONDS', 900),
    'pdf_ocr_page_timeout_seconds' => Env::int('PDF_OCR_PAGE_TIMEOUT_SECONDS', 120),
    'pdf_ocr_jobs' => Env::int('PDF_OCR_JOBS', 1),
    'pdf_ocr_maximum_output_bytes' => Env::int('PDF_OCR_MAX_OUTPUT_SIZE_MB', 100) * 1024 * 1024,
    'pdf_ocr_rotate_pages' => Env::bool('PDF_OCR_ROTATE_PAGES', true),
    'pdf_ocr_deskew' => Env::bool('PDF_OCR_DESKEW', true),
];
