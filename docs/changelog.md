# Changelog

Ask Asio has not adopted semantic release numbers. This history is organized by implementation milestone and Git commit. Dates reflect the July 2026 development sequence; commit IDs are the definitive source history.

## Linked Markdown canonical citations — 2026-08-31

- Retained a validated public HTTP(S) `canonical_url` or `canonical` value from Markdown YAML frontmatter in chunk metadata while continuing to exclude frontmatter from indexed content.
- Linked public Markdown citations to that canonical URL, kept ordinary URL-source citations linked to their source URL, and continued to suppress unsafe, private, uploaded-PDF, and arbitrary metadata URLs.

## Chatbot inline/fullscreen presentation — 2026-08-31

- Added a validated chatbot publication setting for the existing floating launcher or a full-width inline search prompt.
- Expanded inline submissions into a viewport-covering modal with an 800-pixel conversation column, background scroll locking, focus containment, Escape close, and search-focus restoration.
- Preserved floating behavior and backward compatibility by projecting publications without the new field as `floating`.
- Added container-targeted embed support, desktop/mobile rendered verification, configuration/widget contracts, and living documentation.
- Added copy-ready installation code to the chatbot editor, switching automatically between floating and inline snippets and using the configured `APP_URL` and chatbot public ID.
- Reduced the inline entry state to its full-width question field and submit button, and portal the open conversation to a maximum-priority body layer so site headers cannot cover it.
- Reused configured suggested questions as a rotating, reduced-motion-aware “Try asking” prompt that fills the inline field for review without automatically submitting.

## Administrator terminology alignment — 2026-07-21

- Renamed **API Access** to **General API Keys** and moved installation-wide consumption into a dedicated **AI Usage** report, leaving per-key usage with each key.
- Renamed **Chatbot Integrations** to **Chatbot API Keys** throughout the administrator interface while retaining the existing routes, `chatint_live_` format, and internal integration-credential contracts.
- Renamed user-facing provider-token and token-usage labels to **AI usage**, distinguishing model consumption from authentication keys and session tokens.
- Attributed provider operations and API Activity to General API keys, Chatbot API keys, browser chatbots, and administrator previews without storing bearer material or conversation content.
- Added per-Chatbot-API-key today/month/message usage and preserved the pre-existing lifetime request/token counters as historical totals.

## Chatbot editor workflow — 2026-07-21

- Split chatbot administration into URL-backed Settings, Knowledge, Access, and Lifecycle tabs while keeping preview, publication state, and publish controls in a shared header.
- Added publication-readiness links to missing source/origin requirements.
- Replaced one-source-at-a-time assignment with checkbox-based batch add/remove actions that preserve source filters and advance the optimistic draft revision once per batch.
- Added assigned/ready/attention summaries, responsive sticky selection actions, progressive-enhancement behavior, and controller/rendering regression coverage.

## Bulk Markdown knowledge import — 2026-07-21

- Added an administrator bulk Markdown page with multi-file selection, sequential per-file uploads, live progress, and independent success/error results.
- Required valid YAML frontmatter with a text `title` for bulk items and used it as each source name while excluding frontmatter from indexed content.
- Reused upload validation, private randomized storage, immutable source versions, transactional ingestion queueing, and failed-file cleanup without a schema change.
- Added Symfony YAML parsing, frontmatter/controller coverage, responsive UI styling, and upload/deployment documentation.

## Customer-facing chatbot security/accessibility release gate — 2026-07-21

- Ran the complete local and real-MySQL quality, HTTP, security, CORS, abuse, failure, migration, and restore gates without paid provider calls.
- Added a representative real-vector RAG evaluation covering expected source selection, unsupported fallback, assigned-source exclusion, prompt/data separation, and citation enforcement.
- Prevented API Activity from retaining dynamic chatbot/session route identifiers and expanded log redaction for integration/session bearer formats.
- Hardened widget accessible naming, grouping, busy state, speaker labels, and mobile dynamic-viewport behavior.
- Rehearsed logical database restoration and release-artifact composition; documented accepted limitations and withheld unrestricted production approval pending real-browser, production restore, provider-account/model, and deployment-topology checks.

## Customer-facing chatbot analytics and operational readiness — 2026-07-21

- Added administrator-only, content-free 1–90 day chatbot/session summaries with production/test filters, numeric usage/failure aggregates, and request-ID correlation.
- Added migration `20260721000020` for the bounded session-activity analytics scan and real-MySQL aggregation coverage.
- Finalized the chatbot configuration and enforced-limit inventory, deployment and monitoring procedure, request-ID troubleshooting, coordinated backup/restore, and migration rollback/forward-fix guidance.
- Confirmed that provider-project hard spend/rate limits remain an external operator gate and that conventional TLS-terminating proxy deployment remains blocked until allowlisted trusted-proxy support exists.

## Customer-facing chatbot conversation operations and integrations — 2026-07-21

- Added bounded conversation list/detail views with explicit production/test classification, filtered pagination, safe transcript rendering, and content-free diagnostics.
- Added scheduled expiry/retention, confirmed eligible manual purge snapshots, bounded cascades, one shared advisory lock, and content-free audit logging.
- Added separate one-time `chatint_live_` server credentials with hash-only persistence, exact chatbot scopes, expiry/revocation/deletion UI, dedicated authentication and request limits.
- Added integration session/message routes with a second session credential layer, shared grounded execution, usage accounting, migration `20260721000019`, examples, and tests. The WordPress plugin remains deferred.

## Customer-facing chatbot public messages and widget — 2026-07-21

- Added bearer-authenticated non-streaming message and completion routes with exact chatbot/session/origin binding, strict DTOs, source-scoped shared execution, persistence, public-safe citations, and usage/quota reconciliation.
- Added atomic idempotency replay/conflict/in-progress handling, stale-pending recovery, copied message limits, safe persisted provider failures, and independent HMAC IP/chatbot/session rate limits.
- Added migration `20260721000018` for the session counter scope plus message-rate and pending-timeout configuration.
- Added the async versioned Shadow DOM widget with per-tab `sessionStorage`, lazy sessions, completion-backed restart, safe DOM rendering, accessible interactions, responsive/high-contrast/reduced-motion behavior, and compatibility contracts.
- Added HTTP/security, failure-recovery, rendering-safety, and browser-facing compatibility coverage without paid provider calls.

## Customer-facing chatbot public configuration/session API — 2026-07-21

- Added versioned public configuration/session routes and strict allowlisted DTOs without exposing internal IDs, source scope, prompts, models, credentials, or diagnostics.
- Added exact normalized publication-origin authorization and origin-aware CORS/preflight behavior with no wildcard or cookies.
- Added transactional production session creation with one-time 256-bit bearer delivery and existing hash-only persistence.
- Added independent HMAC IP/chatbot fixed-window limits, new environment controls, privacy-minimized Activity, and migration `20260721000017` for chatbot counter scope.
- Added HTTP/security coverage for schemas, lifecycle concealment, origin confusion, preflight, safe errors, token storage, provider staleness, and limits; public messaging/widget behavior remains deferred.

## Customer-facing chatbot administrator preview — 2026-07-20

- Added authenticated, CSRF-protected draft preview and restart/message routes over the shared chat execution service.
- Added migration `20260720000016` and ADR-033 for immutable draft revision/configuration/source/provider snapshots on exclusively classified `admin_preview` test sessions.
- Kept one-time hash-only conversation credentials in the server-side administrator session and separated preview traffic from production through immutable channel/test fields.
- Added escaped transcripts and bounded administrator retrieval/usage diagnostics without exposing credentials, system prompts, raw provider failures, or arbitrary metadata.
- Added unit/controller/shared-execution and opt-in real-MySQL persistence coverage; no public endpoint, CORS, widget, or integration credential was added.

## Customer-facing chatbot shared execution — 2026-07-20

- Added one internal execution service for future administrator preview and public messaging over atomically reserved conversation turns.
- Enforced session-bound immutable publication/provider snapshots, assigned-source retrieval, published top-K/similarity/instructions/fallback, and immediate chatbot availability.
- Added `recent_completed_turns_v1` with a configurable 1,000-token default, complete-pair selection, deterministic oldest-turn removal, and failed/partial exclusion.
- Added public-safe citation allowlists, separate bounded administrator diagnostics, configured no-evidence fallback without chat generation, actual usage/latency persistence, and session aggregates.
- Reused global provider quota reservations/reconciliation for internal chatbot execution, including conservative ambiguous-failure charging and no API-key bucket pollution.
- Centralized safe provider error mapping and citation projection while preserving the authenticated `/api/v1/chat` request/response/error contract.
- Added unit and opt-in MySQL coverage; no preview/public route, CORS behavior, widget, streaming, or integration credential was added.

## Customer-facing chatbot conversation persistence — 2026-07-20

- Added publication-bound session/message domain records and migration `20260720000015` without tenant/account/provider-credential fields.
- Added independent 256-bit public session IDs and bearer tokens with only a safe prefix and SHA-256 token hash persisted.
- Copied message/idle/absolute/`0|7|30|90` retention policy into each session with immutable production/test classification, status, counts, usage, and retention indexes.
- Added transactional session locks, unique idempotency/request/reply constraints, deterministic in-progress/replay/conflict behavior, atomic user-turn counts, and assistant outcome/usage accounting.
- Added bounded expiry/purge primitives, terminal retention calculation, cascading deletion, unit tests, opt-in real-MySQL tests, and deployment/forward-repair documentation.
- Added no route, public endpoint, CORS/browser storage, execution/provider call, preview UI, widget, scheduler, or integration credential.

## Customer-facing chatbot administrator flow — 2026-07-20

- Added authenticated/CSRF-protected chatbot navigation, paginated list, private draft creation, and bounded edit sections.
- Added server-side status/publication/model/source filters, allowlisted sorting, canonical pages, and safe filtered/unfiltered states.
- Added a paginated source picker with active-version readiness, exact-origin editing, publication, enable/disable, public-ID rotation, archive, and confirmed deletion controls.
- Added safe provider-unconfigured behavior that preserves draft access while blocking publication and hiding credentials.
- Added controller/query/form/lifecycle tests and living-spec documentation; no migration or public chatbot behavior was added.

## Customer-facing chatbot source assignments/publication — 2026-07-20

- Added mutable draft source/origin relations sharing the draft's optimistic revision and immutable publication source/origin snapshots.
- Added strict origin canonicalization, assignment-inclusive configuration hashes, and atomic snapshot creation/activation.
- Preserved source active-version semantics while validating enabled, non-deleted, active-ready, embedding-model/dimension-compatible sources.
- Added draft/active/history dependency queries and blocked permanent source deletion with administrator-safe chatbot details.
- Added migration `20260720000014`, unit coverage, opt-in real-MySQL publication/scope/migration coverage, and deployment/forward-repair documentation.
- Kept chatbot routes, UI, sessions, public APIs, widgets, and provider credential storage out of scope.

## Customer-facing chatbot core domain/persistence — 2026-07-20

- Added chatbot identity, mutable schema-versioned drafts with optimistic revision, immutable numbered core publications, and active-publication lifecycle.
- Added strict runtime/privacy/presentation/appearance validation, canonical configuration hashes, installation provider/model snapshots, and no provider credential storage.
- Added 256-bit `cb_` public ID generation/rotation, enable/disable/archive/permanent-delete guards, and unchanged-publication rejection.
- Added PDO repository transactions, bounded/parameterized list projections, migration `20260720000013`, unit tests, and opt-in real-MySQL repository/migration coverage.
- Kept all chatbot routes, UI, bootstrap wiring, source/origin relations, sessions, and public behavior out of scope; documented migration deployment and forward repair.

## Customer-facing chatbot documentation baseline — 2026-07-20

- Converted the supplied monolithic chatbot brief into a linked living specification covering product scope, admin experience, architecture/data, public API/widget/integrations, security/privacy, quality/operations, and delivery.
- Reconciled multi-tenant assumptions with Ask Asio's actual single-administrator, single-tenant model: no placeholder tenant ownership columns or cross-tenant architecture.
- Reframed isolation around real public boundaries: chatbot publication, assigned sources, chatbot-bound sessions, exact origins, and scoped integration credentials.
- Aligned the target with the existing environment-configured OpenAI connection, grounded RAG pipeline, API/error conventions, rate/quota controls, repositories, admin UI, migrations, privacy contract, and test strategy.
- Recorded unresolved architectural decisions and kept all feature implementation explicitly unstarted.
- Added ADRs 027–031 and an end-to-end vertical-slice design: immutable publication snapshots, 256-bit hash-only per-tab session tokens, active-session transcript storage with 30-day default bounded retention, hard-delete semantics, separate scoped integration credentials, normalized critical settings, and installation-wide model selection.

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
