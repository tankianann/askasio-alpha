# Engineering knowledge base

This document captures implementation discoveries and pitfalls that are easy to rediscover incorrectly. For normative architecture use [Architecture](architecture.md); for open work use [Roadmap](roadmap.md).

## Framework and web-server behavior

### The project has no framework fallback machinery

The custom router handles registered HTTP methods exactly. Web servers must route non-file paths to `public/index.php`; existing assets must bypass the front controller. A `HEAD` request is not automatically treated as `GET`, so use a real GET when testing routes unless HEAD support is added deliberately.

### The public root is a security invariant

Serving the repository root is not a harmless local shortcut. It risks exposing `.env`, logs, code, migrations, and uploaded data. Both web and worker bootstraps also reject `FILESYSTEM_PATH` when it resolves inside `public/`.

### There is no CSS/JS compilation

UI changes under `public/assets/` take effect immediately. “CSS is off” usually means stale browser/proxy cache, missing deployed CSS files, or an incorrect document root—not a missing build command.

## Configuration behavior

### Config is loaded once per process

`.env` is loaded before every composition root creates typed configuration. Long-running workers need a restart after environment changes. PHP-FPM may need reload depending on deployment/process behavior.

### `APP_SECRET` is operational identity, not just encryption material

It HMACs login/IP identifiers and chat safety identifiers. Changing it does not reveal old data, but it breaks correlation across the rotation and changes throttle identity. Preserve it across routine releases.

### `APP_DEBUG` is currently misleading

It is parsed but the error handler intentionally never renders stack traces. Keep it false in production, but do not expect true to expose web errors. Use request IDs and logs.

### Anthropic settings do not imply support

Only OpenAI providers exist. `LLM_PROVIDER=anthropic` or another value fails configuration safely. Remove the placeholder settings or implement the full typed adapter before advertising support.

## MySQL and migration lessons

### MySQL is the tested database

CI uses MySQL 8.4. Although the original requirement allowed MariaDB, important behavior includes JSON, checks, `FOR UPDATE SKIP LOCKED`, advisory locks, `LAST_INSERT_ID` upsert counters, and MySQL DDL semantics. Add MariaDB CI before making compatibility promises for a specific release.

### DDL transactions are not dependable rollback

MySQL may implicitly commit schema changes. The migration runner attempts a transaction but an applied migration must be treated as immutable and repaired through a new forward migration. Always test clean-schema and second-pass idempotence.

### Small-table EXPLAIN can prefer scans

An index not being selected on a tiny development dataset is not proof that the index is wrong. Use current statistics and production-shaped data with `EXPLAIN ANALYZE` before changing indexes.

### Large fields must stay out of list queries

`source_versions.extracted_text` is `LONGTEXT`, embeddings are JSON arrays, and chunk content can be large. Source histories use metadata projections and open one revision to load extracted text. API key lists omit hashes; API Activity lists omit IP hashes.

## Authentication and proxy lessons

### Direct request identity is intentional today

`Request::clientIp()` and secure-scheme detection do not trust `X-Forwarded-*`. This avoids trivial spoofing but is wrong behind a TLS-terminating proxy unless the network topology preserves the original connection details. Never “fix” it by trusting forwarded headers globally; require an explicit trusted proxy list and chain parsing.

### One-time API keys are unrecoverable by design

Only the hash/prefix persists. If a client loses the key, create another and rotate it. Do not add plaintext recovery.

### Password and API-key hashes serve different purposes

Human passwords need adaptive password hashing (`password_hash`/Argon2id). High-entropy random API keys can be looked up by deterministic SHA-256 hash and compared in constant time.

## API, privacy, and observability

### API Activity is not a transcript

The request logger inspects only numeric values in a successful JSON `usage` object. Questions, bodies, retrieved chunks, citations, answers, raw IPs, and authorization data are intentionally unavailable later. Reproduction is required for content-level debugging.

### Middleware order matters

API Activity logging wraps authentication and rate limiting so it can record 401/429 outcomes. Authentication sets `ApiRequestContext` for numeric key correlation. Moving middleware without HTTP/privacy tests can silently change auditing.

### Retrieve and chat error mapping differ

Chat maps known embedding/chat configuration/auth/rate/timeout/malformed failures to stable 5xx codes. Retrieve reconciles quota on failure but currently lets provider exceptions reach generic `500 internal_error`. Normalize this in a focused API compatibility change.

### Retrieve top-level validation is less strict

Retrieve rejects unknown filter keys but does not reject unknown top-level properties; chat does. New endpoints should use strict allowlists, and retrieve should be aligned with a documented change.

## Source, extraction, and OCR lessons

### Original file hash and content hash are different

`file_hash` hashes uploaded bytes. `content_hash` hashes normalized extracted readable text. A PDF rewrite can change the file hash without changing readable content; URL refresh uses the content hash to avoid pointless activation.

### URL validation has two layers

Initial syntax/host policy catches obvious invalid/private URLs. The worker resolves every destination immediately before connection, rejects any private/reserved answer, pins cURL to the validated IP, disables inherited proxies, and repeats all checks after redirects. Validation only at form submission is insufficient because DNS can change.

### OCR is an external host dependency

There is no credible standard-PHP OCR replacement. OCRmyPDF/Tesseract must be installed, discoverable in the worker environment, and permitted by `proc_open`. Use an absolute binary path under service managers. The generated searchable PDF is temporary; the original remains immutable.

### OCR's trigger is conservative but incomplete

It runs only when native extraction produces no text. Hybrid scans or pages with tiny/noisy text may not OCR. A future text-density/page-aware trigger needs representative tests to avoid OCRing every digital PDF.

### Limits must be checked while accumulating

Checking only after full extraction does not prevent memory exhaustion. HTML/PDF accumulation and chunk production enforce bounds during work and again at pipeline boundaries. Oversized work must fail before paid embedding and must not activate.

## Worker and queue lessons

### Expected and infrastructure failures are intentionally different

Safe ingestion/provider exceptions update/retry/fail the job. Unexpected queue/PDO/process failures cause a critical log and process exit so systemd starts a fresh process. Catching everything inside the loop would hide a broken infrastructure state.

### Abandoned timeout is a safety equation

The timeout must exceed worst-case URL/OCR work plus all possible embedding batches/retries and margin. Reducing batch size or increasing document/timeout/retry limits can make the old reservation unsafe. Worker bootstrap validates this and refuses to start.

### Recovery understands commit-before-complete crashes

A worker can commit a source version as `ready`/`inactive` and crash before marking its job completed. Recovery detects terminal version state and completes the job rather than reprocessing/embedding it.

### Permanent failures should be deterministic

Malformed file, no usable extracted text after successful OCR, excessive document size, bad provider configuration/credentials, or malformed provider response should not consume every retry. Transient provider/network/OCR failures may retry.

## RAG and provider lessons

### Questions are embedded as one input

Only source documents are chunked. A query/question is embedded once, compared with source chunks locally, and then the selected chunks plus complete question are sent to chat.

### Model compatibility is stored per chunk

Retrieval requires configured embedding model and vector dimensions to match. Changing a model without rebuilding can make the corpus appear empty. Run `bin/embed-chunks.php --rebuild` when model/dimensions change.

### Current vector search is deliberately small-scale

MySQL returns every eligible JSON embedding; PHP decodes, scores, sorts, and slices it. This is simple and accurate but linear in corpus size and memory. `VectorStoreInterface` exists specifically to replace this implementation.

### The token estimator is not a tokenizer

Chunk/context estimation approximates characters divided by four. It is adequate for initial boundaries but varies by model and language. Context selection can under/overestimate, and quota reservation compensates with conservative byte/context multipliers.

### Low similarity is not always useful context

The default `0.20` is permissive. Do not raise/lower it based on one query; maintain a representative evaluation set and track recall, precision, unsupported refusals, answer citations, latency, and cost.

### Prompt injection defenses are layered, not absolute

The prompt separates fixed instructions from JSON data and tells the model source content is untrusted. Citation syntax is checked. A malicious document can still influence a model, and citation range does not prove that a claim is faithful. Evaluation and possibly post-generation verification remain necessary.

### Chat retry defaults avoid duplicate ambiguous billing

Automatic chat retries are zero. A timeout may have billed/processed upstream, so retrying silently can duplicate cost. Clients can choose an explicit bounded retry policy.

## Provider quota lessons

### Reserve before calling, reconcile afterward

Reading prior request logs before a call is race-prone: concurrent requests can all see the same remaining balance. The quota repository locks and increments global/key daily/monthly buckets in one transaction, then creates a transient reservation.

### Reservations are deliberately worst-case

Chat reserves question bytes, maximum context with a safety multiplier, output ceiling, and prompt overhead. This may reject a small-looking request when little quota remains, but it prevents concurrent overspend. Success charges actual usage; ambiguous failure/expiry charges the reservation.

### Reservation rows must not become another activity table

Finalization updates compact period buckets and deletes the reservation atomically. Only active/crashed rows remain. The migration still contains unused fields from an earlier retained-ledger design; remove only with a forward migration.

### Application quotas are not the final financial ceiling

They cover customer retrieve/chat calls. Ingestion embeddings are administrator-triggered and currently outside the ledger. Keep OpenAI project hard budgets and monitor project usage.

## Dashboard and retention lessons

### Browser-side pagination is forbidden for growing datasets

All list filtering/sorting/counting/pagination happens in SQL. URL query state is validated and canonicalized. Invalid pages after filtering/deletion redirect to the last valid page.

### Destructive filter scopes cannot trust the browser

Manual Activity purge stores a server-side short-lived intent with validated criteria, authoritative count, confirmation phrase, and maximum reviewed ID. The client never sends raw SQL or an authoritative delete count.

### Offset pagination has a known end point

Exact totals and numbered pages suit one administrator. At millions of records, count and deep-offset cost become material; switch high-growth views to keyset pagination rather than adding arbitrary caps.

## Package notes

| Package/tool | Use and caution |
| --- | --- |
| `vlucas/phpdotenv` | Loads `.env`; keep secrets out of Git and HTTP/log output. |
| Monolog | Rotating application logs; redaction is defense in depth. Host/journal shipping has separate retention. |
| Ramsey UUID | UUIDv7 request IDs. |
| `league/commonmark` | Markdown conversion with raw HTML disabled/stripped before readable extraction. |
| `smalot/pdfparser` | Native PDF text extraction; may return no text for scanned files and works in memory. |
| OCRmyPDF/Tesseract | Host executable for scanned PDFs; CPU/memory/time/language availability matter. |
| PHPUnit | Unit plus opt-in real-DB integration; never configure tests with a non-`_test` database. |
| PHPStan | Level 5, one process, cache under `storage/cache/phpstan`; no broad baseline. |

## Repository hygiene findings

- No TODO/FIXME/HACK markers were present at the reviewed baseline.
- `settings` is unused, Anthropic variables are placeholders, and `APP_DEBUG` has no behavioral effect.
- Legacy `ragserver` naming remains in Composer package name, database/session defaults, service/path examples, and URL user agent after the Ask Asio rebrand.
- The older `docs/reviews/askasio-codebase-review.md` is historically useful but several critical findings have since been completed. Use [Roadmap](roadmap.md), not that review's old priority ordering, for new work.

