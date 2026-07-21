# Glossary

| Term | Definition and why it exists | Where used |
| --- | --- | --- |
| Active version | The one ready immutable revision currently used for retrieval. Keeps failed/new processing from disrupting live knowledge. | `sources.active_version_id`, activation policy, vector-store queries. |
| General API Keys | Admin area for `rag_live_…` credentials used by external applications calling the general retrieval/chat API. | `/admin/api-keys`, `api_keys`. |
| Chatbot API Keys | Chatbot-scoped `chatint_live_…` credentials used by server integrations. They create chatbot sessions and do not replace browser session tokens. | `/admin/integration-credentials`, `chatbot_integration_credentials`. |
| API Activity | Privacy-minimized diagnostic records for API requests, attributed by access method rather than treated as a conversation transcript. | `/admin/api-requests`, `api_request_logs`, retention/purge services. |
| AI usage | Embedding plus model input/output tokens consumed by quota-managed API/chatbot request operations. It is not an authentication key, API-request count, session token, monetary amount, or ingestion-embedding total. | `/admin/ai-usage`, `ai_usage_records`, provider quota services. |
| APP_SECRET | Stable high-entropy secret used for HMAC identifiers. Rotation breaks correlation with prior hashes. | Login/API IP hashing and safety identifiers. |
| Ask Asio | Product name of this standalone single-user RAG server. Legacy internal names may still say `ragserver`. | UI/docs. |
| Citation | Structured reference to a retrieved chunk and inline `[S#]` marker supporting an answer claim. | Prompt builder, answer generator, chat response. |
| Chunk | Ordered, bounded excerpt of a source version with estimated token count and metadata. Unit of embedding and retrieval. | `source_chunks`, `SemanticChunker`. |
| Content hash | SHA-256 of normalized extracted readable content, distinct from the uploaded file hash. Detects unchanged refreshes. | `source_versions.content_hash`. |
| Context selection | Choosing the highest-ranked chunks that fit the estimated chat context limit. | `ContextSelector`. |
| Cosine similarity | Vector-angle score from -1 to 1 used to rank query/chunk semantic closeness. | `CosineSimilarity`, `PdoVectorStore`. |
| CSRF | Cross-Site Request Forgery protection using a random session-bound form token. | Admin POST routes, `CsrfTokenManager/Middleware`. |
| Embedding | Numeric vector representing text for semantic comparison. | OpenAI embedding provider, `source_chunks.embedding`. |
| Embedding backfill | Idempotent process to embed active chunks missing or incompatible with the configured model. | `bin/embed-chunks.php`, `EmbeddingBackfillService`. |
| Extracted document | Normalized readable text plus title/URL/file/page/heading/offset metadata before chunking. | Extractors and ingestion pipeline. |
| Extractor | Source-type adapter converting URL/Markdown/PDF into an extracted document. | `SourceExtractorInterface`, `ExtractorRegistry`. |
| File hash | SHA-256 of raw uploaded bytes. Establishes file identity without conflating normalized text. | `source_versions.file_hash`. |
| Fixed-window rate limit | Request counter for a discrete time window, applied by API key and IP. Controls request frequency, not provider spend. | `ApiRateLimiter`, `api_rate_limit_buckets`. |
| Grounded answer | Answer restricted to retrieved source excerpts, with citations and an explicit insufficient-information behavior. | `/api/v1/chat`, prompt/answer classes. |
| Inactive version | Successfully processed but non-active revision, including unchanged or superseded work. Excluded from retrieval. | `source_versions.processing_status`. |
| Ingestion | Background extraction, normalization, hashing, chunking, embedding, persistence, and activation. | Worker pipeline. |
| Ingestion job | Durable queue record for processing one immutable source version. | `ingestion_jobs`, Processing UI. |
| Knowledge Base | Admin-facing collection of managed sources. | `/admin/sources`. |
| Logical deletion / soft deletion | Setting `deleted_at` so content is excluded but records/files remain recoverable/auditable. | `sources.deleted_at`. |
| Maintenance lock | MySQL advisory lock preventing concurrent scheduled/manual purge runs. | `PdoAdvisoryLock`. |
| Metadata | Structured context retained across extraction/chunking/retrieval, such as page, heading, section, URL, offsets. | `metadata_json` and citations. |
| OCR | Optical Character Recognition applied to image-only PDFs using OCRmyPDF/Tesseract. | `OcrmyPdfEngine`, `PdfExtractor`. |
| Permanent deletion | Irreversible removal of a soft-deleted source, versions, chunks/embeddings, jobs, and private files after confirmation. | Source deletion service/admin action. |
| Processing reservation | Worker ownership/time recorded when an ingestion job is claimed. Enables concurrency and crash recovery. | `reserved_by`, `reserved_at`. |
| Provider quota bucket | UTC daily/monthly consumed/reserved token counter for global or API-key scope. | `provider_quota_buckets`. |
| Provider quota reservation | Transient worst-case token hold created before a paid API call and reconciled/deleted afterward. | `provider_quota_reservations`, quota service. |
| RAG | Retrieval-Augmented Generation: retrieve relevant source excerpts before asking the model to answer. | `app/RAG`, `/api/v1/chat`. |
| Request ID | UUIDv7 correlating response, API Activity, and operational logs without exposing internals. | `RequestIdMiddleware`, error responses. |
| Retrieval | Embedding a query and ranking eligible chunks by cosine similarity. | `/api/v1/retrieve`, `Retriever`. |
| Retrieval threshold | Minimum cosine score a chunk must meet. Low values admit weaker context. | `RAG_RETRIEVAL_MINIMUM_SIMILARITY`. |
| Source | Mutable named knowledge identity and availability wrapper around immutable versions. | `sources`, admin Knowledge Base. |
| Source version / revision | Immutable URL snapshot/upload/reprocess attempt with its own processing state and artifacts. | `source_versions`. |
| SSRF | Server-Side Request Forgery; risk that a URL source accesses private/local/metadata services. | URL validator, network guard, safe fetcher. |
| Vector store | Interface for embedding persistence/search. Current implementation uses MySQL JSON and PHP cosine. | `VectorStoreInterface`, `PdoVectorStore`. |
| Worker runtime policy | Startup validation ensuring configured operation timeouts fit inside the abandoned-job reservation. | `WorkerRuntimePolicy`, worker bootstrap. |
