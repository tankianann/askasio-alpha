# Changelog

Ask Asio has not adopted semantic release numbers. This history is organized by implementation milestone and Git commit. Dates reflect the July 2026 development sequence; commit IDs are the definitive source history.

## Documentation consolidation — 2026-07-20

- Consolidated architecture, decisions, API, schema, deployment, security, coding standards, developer workflow, glossary, lessons, roadmap, and project history.
- Reconciled the older codebase review with completed CI, worker safety, and provider quota work.
- Established the repository documentation as the future single source of truth.

## Provider quota enforcement — `61e4ed4`

- Added atomic MySQL global/per-API-key daily and monthly token buckets.
- Added conservative pre-call reservations, actual usage reconciliation, expired-reservation recovery, and full estimated charging for ambiguous failures.
- Added OpenAI embedding usage accumulation and combined chat/retrieval usage reporting.
- Added quota visibility to API Access and numeric quota usage to API responses/Activity.
- Added no-provider-call quota tests and real-MySQL locking/reconciliation coverage.
- Made finalized reservations transient to avoid a second unbounded activity table.

## Worker and document safety — `ff71e44`

- Added deliberate separation between controlled job failures and infrastructure failures requiring supervised process restart.
- Added a hardened systemd unit with memory/cgroup/filesystem/process controls.
- Added extracted-character, PDF-page, chunk-count, and OCR output boundaries.
- Added worker timeout-policy validation tying abandoned reservation time to URL/OCR/embedding worst case.
- Added oversized-document, OCR, chunker, and worker failure tests.

## CI and integration quality gate — `28453e5`

- Added GitHub Actions for PHP 8.3/8.4 and MySQL 8.4.
- Added Composer validation/audit, PHP lint, PHPStan level 5, and PHPUnit gate.
- Added disposable real-database clean-migration/idempotence tests.
- Added complete application HTTP smoke tests through real middleware/bootstrap wiring.
- Corrected issues found by static analysis.

## Independent architecture/code review — `d70276a`

- Added a full repository review covering architecture, security, RAG, performance, tests, and production readiness.
- Identified provider spend, vector-search scaling, worker/OOM, proxy identity, CI, retention, and quality gaps.
- Subsequent commits resolved CI/DB testing, worker/document safety, and authenticated provider quota findings; remaining items are tracked in [Roadmap](roadmap.md).

## Dashboard audit completion — `d8b1375`

- Finalized data-screen audit, query/index review, privacy regression coverage, and scaling notes.
- Documented offset-pagination triggers and query-plan findings.

## Source-detail history hardening — `f5c9b64`

- Independently paginated revision and job histories.
- Stopped loading full extracted documents and metadata into history list responses.
- Added dedicated revision details and large-document regression coverage.

## Knowledge Base, Processing, and API Access lists — `7d27663`

- Replaced unbounded/silently capped queries with server-side pagination, filters, search, sorting, counts, and URL state.
- Added deterministic ordering and supporting dashboard indexes.
- Preserved secret-hash exclusion from API-key list projections.

## Manual API Activity purge — `c7249d1`

- Added purge preview/count and explicit confirmation.
- Added scopes for age/date/current filters/all records.
- Stored validated short-lived purge intent server-side with maximum reviewed ID.
- Added CSRF/auth checks, advisory locking, bounded deletion, logging, and tests.

## Scheduled API Activity retention — `8ed342f`

- Added configurable keep-forever/30/90/180/365-day policy.
- Added idempotent batched purge CLI with advisory lock, UTC cutoff, logs, cron documentation, and tests.

## API Activity read experience — `2b7d492`

- Added server-side pagination (25/50/100), total counts, page navigation, canonical invalid-page recovery.
- Added date, key, endpoint, method, status/group, duration, request ID, and authentication filters.
- Added allowlisted sorting, URL-persisted state, active-filter summary, and empty states.

## Shared pagination/query state — `3e6c532`

- Introduced reusable validated page request/result, sort direction, query-string, and admin list-input primitives.
- Established server-side list conventions used by later admin screens.

## Product rebrand — `8fa8241`, `7101545`

- Restyled and rebranded the administration interface from the earlier Ask Archie naming to Ask Asio.
- Introduced the current asset/token/component-based visual design.
- Some operational/internal `ragserver` names intentionally remain for compatibility.

## Milestone 9 — Source updates and operational hardening — `7b9e389`

- Added replacement versions, URL refresh, completed-version reprocessing, ordered atomic activation, and unchanged-content handling.
- Added permanent source deletion with filesystem cleanup and destructive confirmation.
- Hardened abandoned job recovery and production/security documentation.

## Milestone 8 — Grounded RAG chat API — `d7fe554`

- Added grounded prompt/context selection, OpenAI Responses API chat provider, and citation validation.
- Added `/api/v1/chat`, safe provider error mapping, usage reporting, and insufficient-information behavior.
- Disabled provider-side chat storage and automatic ambiguous chat retries by default.

## Milestone 7 — API key management — `72a3109`

- Added API key tables, one-time key creation, hash-only storage, expiry/revocation/deletion UI.
- Added bearer authentication, MySQL fixed-window key/IP rate limits, request logging, privacy controls, and API Activity screen.

## Milestone 6 — Embeddings and retrieval — `661873a`

- Added OpenAI embedding provider and typed provider failure categories.
- Added JSON embedding metadata/storage, PHP cosine retrieval, active/model-compatible filters, CLI debugger, backfill, and `/api/v1/retrieve`.

## Milestone 5 — Extraction and chunking — `ac6b5f2`

- Added URL, Markdown, and PDF extractors behind interfaces.
- Added SSRF-safe fetching, readable HTML parsing, metadata preservation, normalized content hashes, semantic chunker, and tests.
- Added OCRmyPDF/Tesseract fallback for image-only PDFs in follow-up work.

## Milestone 4 — Ingestion jobs — `f8e7de8`

- Added durable MySQL ingestion queue, atomic claims, ownership, attempts/retries, abandoned recovery, logs, worker and one-shot commands, and processing UI.

## Milestone 3 — Source management — `c8f0938`

- Added source/version schema and admin flows for URL, Markdown, and PDF.
- Added secure private uploads, source lists/details/history, enable/disable, and soft deletion.

## Milestone 2 — Administrator authentication — `c3f44a5`

- Added single-admin database/CLI creation, password hashing, login/logout, secure sessions, CSRF, login throttling, and protected admin layout.

## Milestone 1 — Foundation — `ac364ea`

- Created framework-free PHP 8.3 project layout and Composer configuration.
- Added typed environment/configuration, PDO, migrations, request/response/router, middleware/error boundaries, logging, health endpoint, tests, and setup README.

