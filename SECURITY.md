# Security and production review

This document records the Milestone 9 security baseline. It is an operational checklist, not a guarantee that a deployment is secure under every hosting topology.

## Deployment boundary

- Serve only `public/`; deny directory indexes and never expose the repository root.
- Use HTTPS, `APP_ENV=production`, `APP_DEBUG=false`, and `SESSION_SECURE_COOKIE=always`.
- Keep `.env`, database backups, source files, logs, and cache outside the public web root with least-privilege ownership and modes.
- Run the web process and ingestion worker as unprivileged accounts. Do not run Composer, PHP-FPM, or the worker as root.
- Restrict MySQL to localhost or a private network and grant only the schema permissions required by deployment and runtime policy.
- Allow outbound HTTPS only where practical. URL ingestion intentionally supports arbitrary public HTTP(S) sites; OpenAI providers require access to their configured API origin.

## Application controls reviewed

- Administrator passwords use PHP password hashing and verification; plaintext passwords are never stored.
- Administrator state changes require authenticated sessions and CSRF tokens. Login attempts are throttled by normalized username and client IP identifiers.
- API keys are generated from secure random bytes, shown once, and stored only as a visible prefix plus SHA-256 hash. Authentication comparisons use constant-time equality. API Access list queries select display metadata only and do not load secret hashes.
- API requests are size/type/schema validated and rate-limited by API key and HMAC-derived IP identifier.
- SQL uses prepared PDO statements. Templates escape untrusted output, including extracted text and provider/job errors.
- Uploaded files are stored under randomized server names outside `public/` and validated by size, extension, detected MIME type, and signature or UTF-8 content rules.
- URL ingestion permits only HTTP(S), rejects local/private/reserved destinations, validates DNS results before connection, pins the validated address, disables proxy inheritance, and repeats validation after redirects.
- Provider secrets and bearer tokens are redacted from application logs. API request records omit full questions, answers, raw IP addresses, and credentials.
- Source activation, chunks, and embeddings commit together. A failed or older out-of-order version cannot displace the current active version.
- Source history list queries omit extracted document text and metadata; full extracted content is loaded only on an authenticated revision-specific page and remains HTML-escaped.
- Permanent source deletion requires prior soft deletion, exact-name confirmation, CSRF protection, and no pending/processing jobs.
- Manual API Activity deletion requires administrator authentication, CSRF validation, a server-side short-lived scope snapshot, count review, and an exact confirmation phrase. Newer records beyond the reviewed maximum ID are excluded.
- HTTPS responses use HSTS. Administrator responses disable caching. CSP blocks framing and limits scripts, styles, images, objects, forms, and base URLs.

## API Activity privacy contract

API Activity is diagnostic metadata, not a transcript or content store.

| Stored | Purpose |
| --- | --- |
| Request ID | Correlate a client-visible failure with operational logs. |
| Numeric API-key ID | Identify the connection, including after its key record is deleted. |
| HMAC-derived IP identifier | Rate-limit/audit correlation without retaining the raw address. |
| HTTP method and path-only endpoint | Identify the operation without storing query parameters. |
| Status, duration, and error category | Reliability and latency diagnostics. |
| Numeric provider/token usage | Capacity and cost diagnostics. |
| UTC creation time | Ordering, filtering, and retention. |

It does not store Authorization headers, bearer tokens, provider/API secrets, raw IP addresses, URL query strings, request bodies, questions/prompts, retrieved chunks or documents, citations, or generated answers. The administrator list projection also omits the IP hash. This release intentionally provides no prompt-logging switch. Automated privacy tests submit unique sentinels in credentials, query strings, bodies, retrieved text, and answers and assert that none cross the audit-record boundary.

Visible API-key prefixes and connection names may be joined into administrator list responses; only the numeric key ID is retained in the activity row itself. Permanently deleting an API key leaves its numeric activity correlation intact without preserving the key hash or plaintext secret.

## Data lifecycle

- API Activity follows the configured 0/30/90/180/365-day live-database policy and can also be manually purged through confirmed, CSRF-protected scopes.
- Expired API rate-limit buckets are deleted in bounded batches during rate-limit activity.
- Application logs rotate daily and retain 14 files by default; host log shipping requires its own retention policy.
- Immutable source versions, chunks, embeddings, and ingestion jobs remain until permanent source deletion. Soft deletion is not a privacy erasure.
- Database/filesystem backups have an independent lifecycle and may retain data already removed from the live application.

## Query-performance review

Dashboard queries filter before pagination, use deterministic ID tie-breakers, and use allowlisted sort expressions. API Access and source-history list projections exclude secret hashes and large extracted document fields respectively. The final MySQL review confirmed available indexed paths for API Activity date/key/status/endpoint/duration filters, retention cutoff deletion, Processing status, API Access ordering, and source revision history.

Because the reviewed development tables are small, MySQL may prefer a table scan or filesort over an available ordering index. Use production-sized data, current table statistics, and `EXPLAIN ANALYZE` before adding or forcing indexes. Per-source job history can require a temporary sort across the source's revisions; consider denormalizing `source_id` onto jobs only after measured evidence justifies the consistency cost. Offset pagination and exact counts should be revisited for multi-million-row API Activity or Processing datasets.

## Operations checklist

- Preserve `APP_SECRET` across releases and keep API/provider keys in a secrets manager or protected environment file.
- Rotate provider and application API keys on a documented schedule and immediately after suspected disclosure.
- Back up MySQL and `FILESYSTEM_PATH` together; test restoration away from production.
- Monitor health, worker status, failed jobs, provider errors/rate limits, disk capacity, and application logs.
- Keep `JOB_ABANDONED_TIMEOUT_MINUTES` above the worker's validated worst-case OCR/embedding window and schedule `bin/recover-jobs.php` as a fallback. The worker refuses unsafe timeout combinations.
- Keep `INGESTION_MAXIMUM_EXTRACTED_CHARACTERS`, `RAG_MAXIMUM_CHUNKS_PER_DOCUMENT`, and `PDF_MAXIMUM_PAGES` at measured, finite values. Oversized documents fail permanently before embedding or activation.
- Install the hardened `deploy/systemd/ragserver-worker.service` unit and keep its PHP/cgroup memory ceilings and `ReadWritePaths` aligned with the deployment.
- Choose an explicit API Activity retention period and schedule `bin/prune-api-requests.php`; align database-backup retention with the same privacy requirements.
- Periodically record API Activity and Processing row counts, index sizes, slow-query samples, and deep-page latency; reassess retention or pagination before growth becomes operational pressure.
- Apply OS, PHP, web server, MySQL, OCRmyPDF/Tesseract, and Composer security updates through a tested release process.
- Run `composer audit`, the test suite, PHP syntax checks, and `composer validate --strict` before production releases.
- Review trusted-proxy handling before deploying behind a TLS-terminating proxy. The application intentionally does not trust forwarded headers by default.

## Incident response

If compromise is suspected, isolate the host, preserve relevant logs, revoke application API keys, rotate provider/database credentials and `APP_SECRET` as appropriate, and restore from a verified clean backup. Rotating `APP_SECRET` invalidates correlation with historical HMAC-derived rate-limit and IP identifiers. Review source files as untrusted input even though they are stored outside the web root.
