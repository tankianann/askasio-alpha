# AskAsio Codebase Review

**Reviewer role:** Senior architect / application security / performance / peer developer
**Date:** 2026-07-19
**Commit reviewed:** `d8b1375` (branch `master`)
**Method:** Static inspection of the entire repository (app code, config, migrations, tests, docs, deployment files). No code was modified. No runtime/production system was observed — see §18 for what could not be verified.

---

## 1. Executive Summary

AskAsio is a **framework-free PHP 8.3 application** implementing a retrieval-augmented generation (RAG) server. It is **single-administrator / single-tenant by design** — `composer.json` describes it as *"A standalone, single-user retrieval-augmented generation application."* One admin authenticates by session cookie to a server-rendered dashboard, ingests knowledge sources (URL / Markdown / PDF), and issues API keys that authenticate two public JSON endpoints: `POST /api/v1/retrieve` and `POST /api/v1/chat`.

> **Important framing correction.** The review brief repeatedly asks about *multi-tenant data isolation* (documents, users, tenants per account). **This architecture is not multi-tenant.** There is exactly one administrator (enforced by `bin/create-admin.php`), no per-account/per-tenant boundary, and integer IDs are used throughout because there is no cross-tenant boundary to cross. The "tenant isolation" phase is therefore assessed against the *actual* single-tenant design (see §10). If multi-tenancy is a future goal, that is a ground-up data-model change, not a hardening task.

**Overall state.** This is an unusually disciplined and mature hand-rolled codebase. SQL is uniformly parameterized; passwords use Argon2id with anti-enumeration; API keys are 256-bit and constant-time compared; SSRF defenses include DNS-rebinding protection with IP pinning; the ingestion queue uses atomic `FOR UPDATE SKIP LOCKED` claiming with idempotent, transactional stores and version-reconciling crash recovery; request logs deliberately store no prompt/response content and HMAC the client IP. Documentation (README 846 lines, SECURITY.md) and the test suite (157 test methods) are far above what a project of this size usually carries.

**Strongest aspects.**
- Security fundamentals (auth, CSRF, API keys, SSRF, secret redaction, output escaping).
- Background-processing correctness (atomic claim, no-orphan/idempotent ingestion, crash recovery).
- Privacy posture of the API activity log.
- Documentation and configuration discipline.

**Largest risks.**
1. **No cost/spend control** in front of paid OpenAI calls — the single biggest financial exposure (§5, C-1).
2. **Brute-force in-PHP vector search** that loads the whole corpus per query — the scaling ceiling of the whole system (§5, C-2 / §11).
3. **Worker-loop fragility and large-file OOM** — ingestion can silently stop or enter a crash-retry loop (§6).
4. **Reverse-proxy operational landmines** — `Secure`/HSTS and per-IP rate/login throttling degrade behind a TLS-terminating proxy (§10, §15).
5. **Nothing tests the wiring or the database** — all coverage is component-level against fakes; no CI enforces any of it (§14).

**Production-readiness assessment.** The engineering quality is high, but the application is **not yet production-ready for a paid, internet-facing service**. I classify it today as **Development-stage bordering Staging-ready**. It can reach **Production-ready with conditions** after the Phase A blockers in §16 are addressed (cost controls, trusted-proxy handling, and a minimal HTTP/DB test + CI gate). For a *private, internal, small-corpus, trusted-caller* deployment behind a properly configured proxy, it is close to staging-ready today.

---

## 2. Architecture Overview

### Technology summary
| Concern | Choice |
|---|---|
| Language / runtime | PHP 8.3+, `declare(strict_types=1)` throughout |
| Framework | **None** — hand-rolled front controller, router, DI in `bootstrap/app.php` |
| Web root | `public/index.php` only |
| Database | MySQL/MariaDB via PDO (native prepared statements, `ATTR_EMULATE_PREPARES=false`) |
| Queue | MySQL-backed `ingestion_jobs` table, `FOR UPDATE SKIP LOCKED` claim |
| Cache | **None** (only `Cache-Control` headers and a `flock` scratch dir) |
| Vector store | **SQL column** `source_chunks.embedding JSON`; cosine similarity computed **in PHP** (`app/RAG/PdoVectorStore.php`, `CosineSimilarity.php`) |
| AI providers | **OpenAI only** (embeddings + chat via Responses API); provider factories reject anything else |
| Front-end | Server-rendered PHP views (`resources/views/*`), static CSS/JS in `public/assets` (no build step, no npm) |
| Auth | Native PHP sessions (admin) + hashed API keys (public API) |
| Testing | PHPUnit 11 (unit tests against in-memory fakes) |
| Deploy | Documented bare-metal Apache/PHP-FPM + systemd/cron; `docker-compose.yml` provisions **only MySQL** |
| Dependencies | 6 runtime libs + 1 dev lib; minimal, current, well-pinned |

### Major modules (`app/`)
- `Http/` — Request/Response, Router, middleware (session, admin auth, CSRF, API-key auth, rate limit, request logging, security headers, request ID), `ErrorHandler`, `JsonRequestParser`.
- `Auth/` — `AuthenticationService`, `PasswordHasher` (Argon2id), `LoginRateLimiter`, `NativeSessionStore`.
- `Security/` — `CsrfTokenManager`, `UrlSourceValidator`, `SecretRedactionProcessor`.
- `Networking/` — `SafeUrlFetcher`, `UrlNetworkGuard` (SSRF defenses).
- `Ingestion/` — `IngestionWorker`, `DocumentIngestionProcessor`, `Extractors/` (HTML/Markdown/PDF), `Ocr/` (OCRmyPDF subprocess), `Chunking/` (`SemanticChunker`, `HeuristicTokenEstimator`).
- `RAG/` — `Retriever`, `PdoVectorStore`, `CosineSimilarity`, `ContextSelector`, `PromptBuilder`, `AnswerGenerator`, `EmbeddingBackfillService`.
- `Providers/` — `OpenAI/` HTTP client, `Embeddings/`, `Chat/` (typed exceptions, factories).
- `Repositories/` — 21 PDO repositories + interfaces; query builders with whitelisted sort columns.
- `Services/`, `Domain/` — application services and value objects (Sources, ApiKeys, Api, Ingestion, RAG, Admin).
- `Maintenance/` — retention/purge for API request logs.
- `Support/` — `Env`, `Config`, `Pagination`.

### Main request flows
- **Admin UI:** `public/index.php` → `Router::dispatch` → `[SessionStart, AdminAuth, Csrf]` → `Controllers/Admin/*` → `ViewRenderer` (escaped output).
- **Public API:** `/api/v1/*` → `[ApiRequestLogging, ApiKeyAuthentication, ApiRateLimit]` → retrieve/chat handler closures (`bootstrap/app.php:261-311`).

### RAG pipeline
`Source create/refresh` → enqueue `ingestion_jobs` row (same tx as version) → worker `claim()` → `DocumentIngestionProcessor`: extract (HTML/MD/PDF + OCR fallback) → normalize/hash → `SemanticChunker` → embed via OpenAI (batched) → `storeAndActivate` (single tx: delete old chunks, insert chunks+vectors, activate version). **Query time:** embed query → `PdoVectorStore::search` loads all active/enabled/ready chunks, computes cosine in PHP, sorts, top-K → `ContextSelector` token-budgets/truncates → `PromptBuilder` (instructions + JSON data) → OpenAI chat → `AnswerGenerator` validates `[S#]` citations → JSON response with citations + token usage.

### Authentication model
Single admin (Argon2id, session cookie, CSRF on all state-changing forms) for the dashboard; opaque API keys (`rag_live_…`, SHA-256 at rest, `hash_equals`) for the public API. No OAuth, no MFA, no roles (none needed for one admin).

### Multi-tenant model
None — single tenant. See §10.

### Background-processing model
One (or more) long-running worker processes polling a MySQL queue; retention/recovery via CLI intended to run under systemd + cron. No external broker (Redis/SQS).

### External services
OpenAI (embeddings + chat), optional `ocrmypdf`/Tesseract binary for scanned PDFs, outbound HTTP for URL ingestion (SSRF-guarded).

---

## 3. Repository Map

| Path | Purpose |
|---|---|
| `public/index.php` | Sole web entry point; disables `display_errors`, sets request ID, funnels errors to `ErrorHandler`. |
| `bootstrap/app.php` | Composition root — builds config, DI graph, router, handler closures. Also `worker.php`, `queue.php`, `maintenance.php` bootstraps. |
| `config/*.php` | Typed, env-backed config (app, auth, api, database, ingestion, logging, providers, queue, rag, routes). |
| `app/` | All application code (see §2 modules). |
| `database/migrations/*.php` | 11 ordered migrations with a `schema_migrations` ledger. |
| `bin/*.php` | CLI: `create-admin`, `worker`, `process-jobs`, `recover-jobs`, `prune-api-requests`, `embed-chunks`, `retrieve`, `migrate`. |
| `resources/views/*` | Server-rendered PHP templates + partials. |
| `tests/Unit`, `tests/Integration`, `tests/Fakes` | 157 test methods, 14 in-memory fakes. |
| `storage/` | Logs, source files, OCR/lock scratch (outside web root). |
| `README.md`, `SECURITY.md` | Extensive setup, deploy, ops, and security-contract docs. |
| `docker-compose.yml` | MySQL 8.4 only (no app/worker container). |

---

## 4. Confirmed Strengths

These are implemented well and should be preserved.

- **SQL safety:** every repository uses bound parameters; `ORDER BY` resolved via `match()` whitelists; `LIMIT/OFFSET` from validated integer `PageRequest` (page sizes clamped to 25/50/100). No injection surface found.
- **Password & login:** Argon2id (`PASSWORD_ARGON2ID`) with `needsRehash` upgrade; unknown-user path verifies against a constant dummy hash for uniform timing (no enumeration).
- **Sessions:** `use_strict_mode`, `use_only_cookies`, `HttpOnly`, `SameSite=Lax`, idle timeout, periodic ID regeneration, regenerate-on-login, full invalidation on logout (fixation addressed).
- **CSRF:** 256-bit session-bound tokens, `hash_equals`, rotation on login, enforced on all state-changing methods including login POST.
- **API keys:** 256-bit random secret, SHA-256 at rest (appropriate for full-entropy keys), only a visible prefix stored, lookup-by-hash + `hash_equals` re-check, `isUsable()` (active/not-revoked/not-expired), plaintext shown once and never logged (`app/Services/ApiKeys/ApiKeyService.php`).
- **SSRF:** `UrlSourceValidator` + `UrlNetworkGuard` + `SafeUrlFetcher` — protocol allowlist, credential/localhost/metadata/private-IP blocking, DNS re-resolution + per-address revalidation, IP pinning via `CURLOPT_RESOLVE`, manual redirect revalidation, proxy disabled, byte caps. Closes DNS-rebinding TOCTOU.
- **Prompt-injection resistance:** `PromptBuilder` cleanly separates instructions from data — question and sources are serialized as a JSON `input` distinct from the instruction string, with explicit "treat as untrusted data, never as instructions" framing, no-evidence wording, and citation rules (`app/RAG/PromptBuilder.php:48-61`).
- **No-evidence & citation validation:** zero-context short-circuits without calling the LLM; answers must contain in-range `[S#]` citations or are rejected as malformed (`app/RAG/AnswerGenerator.php`).
- **Cross-model vector isolation:** retrieval filters on both `embedding_model` and `embedding_dimensions`, so vectors from a changed model are excluded rather than mixed (`app/RAG/PdoVectorStore.php:106-107`).
- **Queue correctness:** atomic `FOR UPDATE SKIP LOCKED` claim with status-guarded update; embeddings computed fully before any write, then a single transaction (`storeAndActivate`) gives no-orphan + idempotent-retry guarantees; `recoverAbandoned` reconciles against version state (handles crash-after-commit-before-complete).
- **Secret redaction & PII hygiene:** `SecretRedactionProcessor` scrubs bearer/`sk-`/`rag_live_` tokens and URL creds from logs; job `last_error` redacted; request log HMACs the client IP and stores **no** prompt/response content.
- **FK/cascade design:** version→source, chunk→version, job→version all `CASCADE`; `active_version_id` `SET NULL` and cleared before delete; API-key→admin `RESTRICT`; API-log FK intentionally dropped to preserve audit history.
- **DB-backed rate limiting** (works across worker processes) and **DB-backed queue** (supports multiple workers) — no in-memory state that breaks horizontal scaling.
- **Config & secrets discipline:** typed `Env` helpers, `APP_SECRET ≥ 32` enforced at boot, `FILESYSTEM_PATH` forced outside `public/`, `.env` git-ignored, log rotation (14 files).
- **Documentation:** README + SECURITY.md are genuinely operational (systemd unit, cron, backups, retention, per-screen growth audit, privacy contract).

---

## 5. Critical Findings

### Finding C-1: No cost / token / spend budget is enforced before paid OpenAI calls

- **Severity:** Critical
- **Category:** Cost / Security
- **Production blocker:** Yes (for a paid, key-exposed, internet-facing service)
- **Confidence:** High

**Evidence**
- `app/Domain/ApiKeys/ApiKey.php` — an API key has `status`, `expiresAt`, `revokedAt` but **no per-key quota/budget field**.
- `config/api.php`, `config/rag.php` — only request-rate and size limits; no spend caps. A grep for `quota|budget|spend|monthly|daily_limit|cost` across `app/`, `config/`, `bin/` returns nothing.
- `app/Http/Middleware/ApiRequestLoggingMiddleware.php:31-39,98-117` — token usage is extracted from the response **after** `$next($request)` completes, i.e. after OpenAI has already been billed.
- `app/RAG/AnswerGenerator.php:26,41-42` — unconditionally calls the embeddings API then the chat API.

**Observation**
The only throttle on paid usage is request-count rate limiting, and usage is *recorded after the fact*, never *enforced before* the provider call. There is no dollar/token ceiling per key, per day, or globally, and no pre-flight gate where one could be applied.

**Risk**
A leaked or abusive API key can spend real money continuously up to the rate-limit ceiling indefinitely, with no automatic cutoff. Because both endpoints call OpenAI (retrieve embeds every query — see C-3), and chat at the default 10/key/min ≈ 14,400 calls/key/day, sustained "in-bounds" traffic produces an unbounded bill. For a product that pays the OpenAI bill directly, this is the primary financial exposure.

**Recommendation**
Add per-key and global rolling token/cost budgets **enforced before** the embedding/chat call. Concretely: add `daily_token_budget` / `monthly_token_budget` columns to `api_keys` plus a global cap in config; before each call, estimate worst-case tokens (query length + `chat_context_maximum_tokens` + `max_output_tokens`) and reject with `429/402 quota_exceeded` when the rolling sum from `api_request_logs.usage_json` would exceed budget. Add a token→cost mapping so spend is visible on the dashboard.

**Validation**
Unit tests: budget arithmetic and the pre-call gate reject once cumulative usage exceeds the cap. Integration test: a key at its cap receives `429 quota_exceeded` and **no** provider call is made (assert on a provider fake).

---

### Finding C-2: Vector search is an in-PHP brute-force full-corpus scan

- **Severity:** Critical (scaling) / High (small corpus)
- **Category:** Performance / Architecture
- **Production blocker:** Yes if a large or growing corpus is expected; No for a small fixed corpus
- **Confidence:** High

**Evidence**
- `app/RAG/PdoVectorStore.php:120-170` (`search`) — the SQL has **no `LIMIT`** and no distance math. It selects `sc.embedding` (JSON) and `sc.content` (LONGTEXT) for *every* enabled/ready/active chunk, `fetchAll()`s them, `json_decode`s each vector, computes cosine in a PHP loop (`app/RAG/CosineSimilarity.php:15-52`), `usort`s the whole set, then `array_slice`s to top-K.
- Only index available for the scan is `idx_source_chunks_embedding_model` (filters by model; does not accelerate similarity). No ANN/vector index exists.

**Observation**
Every `/retrieve` and `/chat` request pulls the entire active corpus (vectors + full chunk text) into PHP memory and scores it linearly.

**Risk**
Latency and memory grow O(N) with corpus size. At ~50k chunks × ~1536-dim JSON (~20–30 KB each) this is >1 GB of row data decoded and scored per request — multi-second CPU and probable memory exhaustion well before 100k chunks. This is the dominant scaling bottleneck of the system.

**Recommendation**
Move similarity into the datastore. In rough order of effort/impact: (a) stop selecting `content` during the scan — fetch content only for the final top-K by id; store vectors as packed binary `float32` (`VARBINARY`) instead of JSON text; pre-store vector norms to skip re-computing magnitude; (b) adopt a real vector index — MySQL 9 `VECTOR`/`DISTANCE()`, pgvector, sqlite-vss, or a dedicated vector DB; (c) add a hard cap on scanned chunks with a clear error when exceeded. Document the safe corpus ceiling in the interim.

**Validation**
Benchmark query latency/memory at 1k / 10k / 50k chunks before and after. Assert identical top-K ordering against the current implementation on a fixture corpus.

---

## 6. High-Priority Findings

### Finding H-1: Worker loop has no in-loop exception handling — transient errors silently kill ingestion
- **Severity:** High · **Category:** Stability · **Production blocker:** Yes · **Confidence:** High
- **Evidence:** `app/Ingestion/IngestionWorker.php:91-102` (`run`) calls `runOnce()` with no try/catch; only `processor->process()` exceptions are caught (`:75`). Exceptions from `claim()`, `recoverAbandoned()`, `complete()`, `retryOrFail()` propagate to `bin/worker.php`, which prints a message and `exit(1)`. No self-restart.
- **Risk:** A momentary DB disconnect, lock-wait timeout, or `assertUpdated` mismatch terminates the whole worker. On an unsupervised host, **all** future ingestion silently stops until someone notices.
- **Recommendation:** Wrap `runOnce()` in a try/catch inside the `while` loop; log, back off, continue (rely on `recoverAbandoned` to reclaim the in-flight job). Require and document a process supervisor (systemd `Restart=always`).
- **Validation:** Test that a thrown `claim()` error is caught, logged, and the loop continues; systemd unit restarts on exit.

### Finding H-2: Large-file extraction/embedding is fully in-memory — OOM poison-pill loop
- **Severity:** High · **Category:** Stability · **Production blocker:** No (mitigate before large uploads) · **Confidence:** Medium-High
- **Evidence:** `MarkdownExtractor.php:43` (`file_get_contents` whole file), `PdfExtractor.php:89` (`Smalot\PdfParser::parseFile` parses entire PDF), `EmbeddingService::embedChunks` holds all embedded chunks, `storeAndActivate` holds all chunks + `extracted_text`. Upload cap 20 MB (`config/app.php:14`); OCR output cap 100 MB (`config/ingestion.php:25`). A PHP OOM fatal is not a `Throwable` and is **not** caught by the worker.
- **Risk:** A large PDF OOMs the worker mid-`process()`; the job stays `processing`, `recoverAbandoned` resets it after 30 min, and it OOMs again — only "dead-lettering" after ~`max_attempts × 30 min` of repeated crashes.
- **Recommendation:** Set an explicit worker `memory_limit`; cap page count / extracted size before embedding; lower `MAX_UPLOAD_SIZE_MB` / OCR output cap to what the host can safely parse; consider streaming chunk inserts.
- **Validation:** Ingest a deliberately large PDF under a constrained `memory_limit`; assert it fails cleanly as a permanent job failure rather than crash-looping.

### Finding H-3: `ingestion_jobs` grows forever (terminal jobs never purged)
- **Severity:** High · **Category:** Performance / Stability · **Production blocker:** No · **Confidence:** High
- **Evidence:** No `DELETE FROM ingestion_jobs` anywhere. `PdoIngestionJobRepository::counts()` runs `GROUP BY status` over the whole table on every dashboard load. README's own data audit flags this table "High" growth.
- **Risk:** Every re-ingest/refresh/retry inserts a permanent row; the dashboard aggregate slows continuously; the table dwarfs the live working set over time.
- **Recommendation:** Add a batched retention purge for terminal-state jobs older than N days, mirroring the API-log purge (the `(status, created_at, id)` index already supports it).
- **Validation:** Seed terminal jobs beyond the cutoff; assert batched deletion and that non-terminal jobs are untouched.

### Finding H-4: Paid-endpoint rate limits are generous, and `retrieve` is itself a paid endpoint
- **Severity:** High · **Category:** Cost · **Production blocker:** No (but compounds C-1) · **Confidence:** High
- **Evidence:** `config/api.php:9-13` — retrieve 60/key + 120/IP per minute; chat 10/key + 20/IP. `app/RAG/Retriever.php:47` — retrieve calls the paid embeddings API on every request.
- **Risk:** Retrieve is often assumed free but always bills OpenAI embeddings. At 120/IP/min that is ~172,800 embedding calls/IP/day; chat ~28,800/IP/day. With no budget (C-1), even in-bounds traffic is costly.
- **Recommendation:** Lower chat defaults; pair rate limits with C-1 token budgets; treat retrieve as billable in any cost model.
- **Validation:** Confirm new defaults; load-test spend projection.

### Finding H-5: Reverse-proxy handling — `Secure`/HSTS and per-IP throttling degrade behind a proxy
- **Severity:** High · **Category:** Security / Deployment · **Production blocker:** Yes (for proxied prod) · **Confidence:** High
- **Evidence:** `app/Http/Request.php:59-61` derives `clientIp` from `REMOTE_ADDR` only and `isSecure()` from `$_SERVER['HTTPS']`/port 443 — `X-Forwarded-For`/`X-Forwarded-Proto` are never consulted (intentionally, per SECURITY.md). That IP feeds API rate limiting, login throttling, log HMAC, and chat identifier; the scheme feeds `SESSION_SECURE_COOKIE=auto` and HSTS emission (`SecurityHeadersMiddleware.php:26-28`).
- **Risk:** Behind a TLS-terminating proxy (a standard prod topology): all clients collapse to the proxy IP (per-IP rate limits and login throttle become global/ineffective), and in `auto` mode the session cookie is issued **without `Secure`** and **HSTS is never sent** — session-hijacking exposure on downgrade.
- **Recommendation:** Ship an opt-in `TRUSTED_PROXIES` config that conditionally trusts `X-Forwarded-For`/`X-Forwarded-Proto`; keep default off. Until then, **require** `SESSION_SECURE_COOKIE=always` in production and always emit HSTS in production (mechanisms already exist).
- **Validation:** Simulate proxied requests with forwarded headers; assert correct client IP, `Secure` cookie, and HSTS.

### Finding H-6: No HTTP/end-to-end tests and no test touches a real database
- **Severity:** High · **Category:** Testing · **Production blocker:** No (but strongly recommended before prod) · **Confidence:** High
- **Evidence:** Controllers are instantiated directly (`ChatControllerTest.php:118`, `RetrieveControllerTest.php:78`); `tests/Integration/HealthControllerTest.php` is a mislabeled unit test; `bootstrap/app.php` (the real DI/route wiring) is never exercised. Every repository is faked; the 11 migrations, PDO repositories, `Migrator`, advisory lock, and `FOR UPDATE SKIP LOCKED` semantics have no coverage. SQL builders are tested as strings, never executed.
- **Risk:** Route mis-wiring, middleware-order regressions, SQL/schema drift, broken migrations, and lock semantics ship green — exactly the defects unit-against-fakes cannot catch.
- **Recommendation:** Add (a) a small HTTP harness booting `bootstrap/app.php` against a test DB for critical flows (login, API-key 401, rate-limit 429, CSRF reject), and (b) a MySQL-backed repository/migration group (docker service exists) gated behind an env flag so `composer test` stays offline by default.
- **Validation:** New tests fail on a deliberately mis-wired route / broken migration.

---

## 7. Medium-Priority Findings

### Finding M-1: Dashboard filter dropdowns run unbounded full-table aggregates
- **Severity:** Medium · **Category:** Performance · **Confidence:** High
- **Evidence:** `PdoApiRequestLogRepository::connectionOptions()` `GROUP BY api_key_id` over the whole log table on every API-activity page; `PdoIngestionJobRepository::sourceOptions()` `SELECT DISTINCT` across the full jobs→versions→sources join on every jobs page. No `LIMIT`; no index satisfies the group.
- **Risk:** Full scan + temp-table group on every page view at scale (compounds H-3).
- **Recommendation:** Derive the connection dropdown from the small `api_keys` table (or cache it); query `sources` directly (`EXISTS` a job) instead of `DISTINCT` over the jobs join.

### Finding M-2: `minimum_similarity` default of 0.20 is very permissive
- **Severity:** Medium · **Category:** RAG quality · **Confidence:** High
- **Evidence:** `config/rag.php:12`; enforced `PdoVectorStore.php:144`. Cosine 0.20 with text-embedding-3-* admits weakly related chunks; combined with mandatory citations, the model is pushed to answer from marginal context.
- **Recommendation:** Raise to ~0.35–0.5 and tune empirically; consider a relative cutoff below the top score.

### Finding M-3: Crude `chars/4` token estimator drives chunking and context budgeting
- **Severity:** Medium · **Category:** RAG / Cost · **Confidence:** High
- **Evidence:** `app/Ingestion/Chunking/HeuristicTokenEstimator.php:9-14`, used by `SemanticChunker` and `ContextSelector`. Since chat sets `truncation=disabled` (`OpenAiChatProvider.php:50`), an over-budget prompt is **rejected by OpenAI** (400 → `provider_configuration_error`) rather than trimmed.
- **Recommendation:** Use a real tokenizer (or per-model heuristic) with a safety margin; consider `truncation=auto` as a fallback.

### Finding M-4: No cross-source deduplication (content re-embedded and re-served)
- **Severity:** Medium · **Category:** RAG / Cost · **Confidence:** High
- **Evidence:** `file_hash`/`content_hash` are stored but dedup is only *intra-source* (`PdoSourceIngestionRepository::storeUnchangedIfActiveMatch`). Uploading the same doc as two sources doubles embedding cost; near-duplicate chunks (incl. overlap regions) can crowd the 5–8 context slots. No post-retrieval dedup.
- **Recommendation:** Optional content/file-hash dedup on ingest (warn/reject); a post-retrieval near-duplicate filter (drop >0.97 mutual similarity or overlapping char offsets).

### Finding M-5: Chunker has no table/code/list structure awareness
- **Severity:** Medium · **Category:** RAG quality · **Confidence:** High
- **Evidence:** `SemanticChunker` splits on paragraph→sentence→word only; `HtmlDocumentParser` flattens `li`/`td`/`pre` to plain text before chunking, so table and code structure is lost; oversized code/tables fall to naive word-splitting.
- **Recommendation:** Preserve table cells as `key: value` rows, keep `pre`/code atomic, avoid sentence-splitting code.

### Finding M-6: Chat requests are not retried on transient failures by default
- **Severity:** Medium · **Category:** Reliability · **Confidence:** High
- **Evidence:** `OPENAI_CHAT_MAXIMUM_RETRIES=0` (`config/providers.php:19`) while embeddings use 3. The backoff logic exists and is good but chat opts out; a single transient 429/5xx/timeout fails the user request.
- **Recommendation:** Default chat retries to 1–2 (idempotent since `store=false`), or confirm the opt-out is intentional.

### Finding M-7: Rate-limit prune runs a `DELETE` on every authenticated API request
- **Severity:** Medium · **Category:** Performance · **Confidence:** High
- **Evidence:** `ApiRateLimiter.php:42` calls `pruneExpired()` (`DELETE … LIMIT 500`) inside `consume()`, adding a write + lock acquisition on the hot path (3 writes/request).
- **Recommendation:** Move pruning to a scheduled task or probabilistic sample (~1% of requests).

### Finding M-8: Username-scoped login lockout enables remote admin DoS
- **Severity:** Medium · **Category:** Security · **Confidence:** High
- **Evidence:** `PdoLoginAttemptRepository::countRecent` counts `username_hash = :u OR ip_hash = :ip`. An anonymous attacker can submit `LOGIN_MAX_ATTEMPTS` bad logins for the admin username from throwaway IPs and lock the real admin out for the window.
- **Recommendation:** For a single-admin app, prefer IP-based lockout + a global throttle/CAPTCHA or exponential backoff, rather than username-based lockout an anonymous party can trigger.

### Finding M-9: Multi-worker abandoned-timeout race can double-dispatch a long job
- **Severity:** Medium · **Category:** Stability · **Confidence:** Medium
- **Evidence:** `recoverAbandoned` resets `processing` jobs older than `JOB_ABANDONED_TIMEOUT_MINUTES=30`. A large PDF+OCR (`PDF_OCR_PROCESS_TIMEOUT_SECONDS=900`) plus retried embeddings can exceed 30 min; a second worker reclaims it. Data stays consistent (source-row `FOR UPDATE` + idempotent delete/insert) but work is duplicated and the original worker's `complete()` then matches 0 rows → crash (H-1).
- **Recommendation:** Make the abandoned timeout comfortably larger than worst-case runtime, add a `reserved_at` heartbeat for long jobs, or explicitly support/document single-worker operation.

### Finding M-10: Long-lived PDO connection with no reconnect/keep-alive
- **Severity:** Medium · **Category:** Stability · **Confidence:** Medium
- **Evidence:** `app/Database/Connection.php` caches one PDO for the worker's lifetime; `ping()` exists but is never used in the loop. MySQL `wait_timeout` drops idle connections → next query throws (and with H-1 kills the worker).
- **Recommendation:** Detect "gone away" and reconnect, or `ping()` before `claim()`; the resilient loop from H-1 makes reconnect-on-next-iteration automatic.

### Finding M-11: Migrations are not atomic (DDL auto-commits) and have no rollback
- **Severity:** Medium · **Category:** Stability / Deployment · **Confidence:** High
- **Evidence:** `Migrator.php:40-61` wraps each migration in a transaction, but MySQL implicitly commits on DDL, so a mid-migration failure (e.g. `…000003` creates `sources`, `source_versions`, then adds an FK) leaves partial schema that `rollBack()` cannot undo; re-running hits "table exists". No `down()`.
- **Recommendation:** One idempotent object per migration file, `CREATE TABLE IF NOT EXISTS`, document that DDL is non-transactional, and keep the backup-before-migrate expectation explicit.

### Finding M-12: Dead Anthropic configuration
- **Severity:** Medium · **Category:** DX · **Confidence:** High
- **Evidence:** `.env.example:81-82` ships `ANTHROPIC_API_KEY`/`ANTHROPIC_MODEL`, but no Anthropic provider exists and the factories throw for any non-`openai` value. Setting `LLM_PROVIDER=anthropic` yields an unexplained 503.
- **Recommendation:** Implement the provider or remove the keys and state OpenAI-only support.

### Finding M-13: No CI/CD
- **Severity:** Medium · **Category:** Deployment · **Confidence:** High
- **Evidence:** No `.github/`, GitLab, CircleCI, etc. README/SECURITY.md recommend `composer test`/`php -l`/`composer validate`/`composer audit` "in CI" but nothing enforces them.
- **Recommendation:** Add a minimal GitHub Actions workflow (PHP 8.3, install, test, lint sweep, `validate --strict`, `audit`).

### Finding M-14: Security-critical middleware is untested
- **Severity:** Medium · **Category:** Testing · **Confidence:** High
- **Evidence:** No tests for `CsrfMiddleware`, `AdminAuthenticationMiddleware`, `SessionStartMiddleware`/`NativeSessionStore`, `AuthController` HTTP flow, `SafeUrlFetcher`, or the real `OpenAiHttpClient` retry/backoff.
- **Recommendation:** Prioritize `CsrfMiddleware`, `AdminAuthenticationMiddleware`, `NativeSessionStore` (idle/regenerate/secure-cookie), and `OpenAiHttpClient` (429/timeout/retry).

---

## 8. Low-Priority Findings

- **L-1 — Query embeddings are not cached** (`app/RAG/Retriever.php:47`): repeated/polling queries re-embed (and re-generate) each time; add a hash→embedding cache with short TTL. *(Cost, Medium-Low.)*
- **L-2 — Retrieve does not reject unknown JSON fields** while chat does (`RetrieveController` vs `ChatController.php:109-118`): apply the same allowlist for consistency. *(API.)*
- **L-3 — Fixed-window rate limiting allows ~2× boundary burst** (`ApiRateLimiter.php:38`): acceptable; use sliding-window/token-bucket if precise throttling matters. *(API/Cost.)*
- **L-4 — OFFSET pagination degrades on very deep pages** and `LOCATE(...)>0` name search is non-sargable (`PageRequest.php`, `*SqlQueryBuilder`): fine for a single-admin tool; consider keyset pagination and prefix/FULLTEXT search at scale. *(Performance.)*
- **L-5 — Soft-deleted sources retain all chunks/embeddings/text/files indefinitely** (`PdoSourceRepository::softDelete`): add an optional retention job to purge sources soft-deleted beyond N days. Note extracted text is effectively stored twice (version `extracted_text` + chunk `content`). *(Architecture/Storage.)*
- **L-6 — Per-request instantiation** of provider + vector store in handler closures (`bootstrap/app.php:261-311`): construct once and reuse; negligible next to network cost. *(Performance.)*
- **L-7 — No streaming; low default output cap** (`OPENAI_CHAT_MAX_OUTPUT_TOKENS=600`): long grounded answers silently truncate; consider raising and adding optional streaming. *(RAG/UX.)*
- **L-8 — Provider rate-limit/timeout messages are discarded** in persisted `last_error` (`IngestionQueue::safeError`): surface known provider exception messages (already redacted) for admin diagnosis. *(Observability.)*
- **L-9 — Duplicate enqueue is possible** (unconditional `INSERT` in `PdoIngestionJobRepository::enqueue`): a double-clicked refresh queues two jobs for one version (no corruption, wasted spend); add a pending-job guard on `(source_version_id, status='pending')`. *(Stability/Cost.)*
- **L-10 — Unauthenticated `/api/v1/health`** leaks DB availability and is not rate-limited/logged: acceptable for load balancers; consider a generic anonymous 200. *(Security, informational.)*
- **L-11 — `APP_DEBUG` is dead config** (parsed, never used; `display_errors` forced off): harmless but misleading — remove or wire it. *(DX.)*
- **L-12 — No static analysis / coverage tooling** (no PHPStan/Psalm/cs-fixer; `phpunit.xml` sets no coverage threshold): add PHPStan (~level 8, the code would score well) and a coverage gate. *(DX.)*
- **L-13 — PDF OCR only triggers on zero extracted text**, and PDF `normalizeText` uses a no-op `mb_convert_encoding('UTF-8','UTF-8')` that does not strip invalid bytes (unlike MD/URL extractors): add a min-text-density OCR trigger and validate UTF-8. *(RAG quality.)*

---

## 9. Feature Gaps and Product Improvements

**Required functional gaps (should exist before a paid public launch):**
- Per-key/global usage budgets + spend visibility (C-1).
- Admin password reset/change path — none exists; recovery today is DB surgery (`bin/create-admin.php` refuses when an admin exists).
- Job retention (H-3) and an in-app failed-job alert (the count is exposed but nothing alerts).
- Re-embedding UX: changing `OPENAI_EMBEDDING_MODEL` silently makes the corpus unsearchable until `EmbeddingBackfillService` is run manually — surface a health/admin warning when active chunks lack embeddings for the configured model.

**Optional product enhancements:**
- Hybrid keyword + dense retrieval (BM25/FULLTEXT fused with vectors) and/or reranking, for exact-term/acronym/ID queries dense retrieval misses.
- Conversation history (`conversation_id` is currently rejected as unsupported).
- Streaming chat responses; higher output cap.
- Data export; near-duplicate suppression at retrieval.
- Retrieval debugging view (scores per chunk) for the admin.

---

## 10. Security Assessment

- **Authentication:** Strong. Argon2id, anti-enumeration, hardened sessions, CSRF everywhere, API keys 256-bit + hashed + constant-time. No MFA (acceptable for one admin; MFA-readiness would be a nice-to-have).
- **Authorisation:** Simple and sound. All admin routes behind `AdminAuthenticationMiddleware` + CSRF; API routes behind key auth + rate limit. Route IDs validated (`ctype_digit`) + existence-checked. No roles because there is one admin.
- **Tenant isolation:** **Not applicable — single tenant.** There is no per-account boundary, so there is no cross-tenant leakage surface. Retrieval is correctly scoped to enabled/non-deleted/active/ready chunks for the configured embedding model, so superseded or deleted content cannot leak into answers. **If multi-tenancy is ever required, it is a schema-level redesign** (add `account_id` to every table, global query scoping, per-account keys/quotas/vector partitioning) — not covered by the current design.
- **API security:** Good. Versioned, strict validation, consistent error envelope, body-size caps, DB-backed cross-process rate limiting, hashed identifiers, no content logged. Gaps: cost budgets (C-1), retrieve field strictness (L-2), fixed-window burst (L-3).
- **File & URL ingestion:** Strong. Extension+MIME+magic-byte+UTF-8 upload validation, random filenames outside web root, path-traversal rejection; best-in-class SSRF defenses with DNS-rebinding protection; hardened OCR subprocess (`proc_open` `bypass_shell`, size/time caps, private temp dir).
- **Secret management:** Strong. `APP_SECRET` enforced at boot, no hardcoded secrets, OpenAI key only in a curl header, log redaction, `.env` git-ignored, `APP_DEBUG` cannot leak stack traces.
- **RAG-specific threats:** Prompt injection is well-mitigated by instruction/data separation and untrusted-data framing; retrieved content is JSON-encoded, never concatenated into the instruction block; no-evidence behavior and citation validation are enforced. Residual (accepted) risk: injection resistance is instruction-based; a crafted document could still attempt override. Citation validation checks well-formedness/range, not faithfulness.
- **Data-leakage risks:** Low. No prompt/response content in logs; client IP HMAC'd; caches absent (nothing to leak). Main operational risk is the reverse-proxy `Secure`/HSTS gap (H-5).

---

## 11. Performance and Scalability Assessment

**Dominant bottleneck:** in-PHP full-corpus vector scan (C-2) — the ceiling for the whole system; safe only for small corpora today.

**Secondary bottlenecks:** unbounded `ingestion_jobs` growth (H-3) and dashboard aggregate scans (M-1); per-request rate-limit `DELETE` (M-7); OFFSET deep-page pagination and non-sargable name search (L-4); per-version `chunk_count` correlated subquery (bounded by page size — acceptable).

**Strengths:** list views are paginated and indexed (page sizes clamped 25/50/100); heavy columns (`extracted_text`, chunk `content`) are deferred out of list projections; DB-backed rate limiting and queue scale across processes.

**Recommended thresholds/limits:**
- Corpus ceiling before migrating off in-PHP cosine: document ~10k chunks; hard-cap scanned rows with a clear error.
- `ingestion_jobs` retention: purge terminal jobs > 30–90 days.
- API-log retention: keep the default 30-day purge scheduled (see §15).
- Chat rate limit: reduce defaults and gate with token budgets (C-1/H-4).

---

## 12. RAG Quality Assessment

- **Ingestion:** URL/Markdown/PDF (+OCR fallback) with UTF-8 normalization, SHA-256 content/file hashes, transactional versioned creation, and correct within-source update/duplicate detection. Solid.
- **Chunking:** Paragraph/sentence/word-aware with correct word-aligned overlap, small-tail merge, ordering, and rich metadata. **Weak on tables/code/lists** (M-5).
- **Embeddings:** Batched, model+dimension isolation (strength), retries on the HTTP client. **Re-embedding is manual with no query-time warning** (§9). Crude tokenizer feeds sizing (M-3).
- **Retrieval:** Correct scoping and cross-model safety, but **permissive 0.20 threshold** (M-2), **dense-only** (no hybrid/rerank), **no dedup** (M-4), and the **brute-force scan** (C-2).
- **Prompt construction:** Strong instruction/data separation, injection framing, no-evidence wording, citation rules (strength).
- **Answer generation:** OpenAI Responses API, `store=false`, output cap, citation validation. Gaps: no streaming, low output cap, no faithfulness check, no token→cost mapping (L-7).

**Net:** the *safety and grounding* of the RAG pipeline is excellent; the *retrieval precision/recall and structured-content handling* are the main quality levers to improve.

---

## 13. Stability and Failure-Recovery Assessment

**What is safe today:** ingestion is transactional and idempotent — embeddings computed before any write, then a single `storeAndActivate` transaction gives **no orphaned chunks/partial documents** and **duplicate-free retries**. Job claim is atomic (`SKIP LOCKED` + status guard). `recoverAbandoned` reconciles against version state, correctly handling the crash-after-commit-before-complete window. State transitions are ownership-guarded. Permanent deletion is blocked while jobs are in flight and cascades cleanly (no orphaned rows/files/vectors). Secret redaction covers job errors and CLI output.

**What is fragile:** the worker loop dies on any non-`process()` exception (H-1); large files can OOM into a crash-retry loop (H-2); the abandoned-timeout can race a legitimately long job under multiple workers (M-9); the long-lived PDO has no reconnect (M-10); soft delete does not re-check in-flight ingestion (low, guarded in practice); provider rate-limit/timeout messages are lost in `last_error` (L-8).

**States:** `pending / processing / completed / failed` with retry modeled as `pending` + future `available_at`. No explicit `cancelled` state and no dead-letter table (adequate for one admin). **Failed operations are safely resumable** thanks to idempotent re-processing — the risk is the worker *staying alive* to resume them.

---

## 14. Testing Assessment

**Current coverage (corrected):** 57 unit files, 157 test methods, 14 fakes, 1 (mislabeled) integration test. Quality is high where it exists — meaningful assertions on prompt-injection grounding, citation-forgery rejection, secret redaction, provider-failure mapping, rate-limit windows, CSRF constant-time/rotation, SSRF guard, pagination clamping, and privacy sentinels.

**Structural gaps:** nothing exercises the **wiring** (`bootstrap/app.php`, router+middleware chain, middleware ordering) or the **database** (migrations, PDO repositories, `SKIP LOCKED`, advisory lock) — all persistence is faked (H-6). Security-critical middleware is untested (M-14). Duplicate-enqueue is untested (L-9).

**Most important missing tests (prioritized):**
1. HTTP e2e through router+middleware (login, 401 key reject, 429 rate-limit, CSRF reject).
2. Real-DB repository/migration/advisory-lock/`SKIP LOCKED` group (env-gated).
3. `CsrfMiddleware` + `AdminAuthenticationMiddleware` enforcement.
4. `OpenAiHttpClient` retry/timeout/backoff.
5. `NativeSessionStore` idle-expiry & regeneration.
6. `SafeUrlFetcher` end-to-end SSRF (redirect revalidation, IP pinning).
7. Cost-budget pre-call gate (once C-1 exists).

Focus is correctly on business/security-critical behavior, not a coverage percentage.

---

## 15. Deployment-Readiness Assessment

**Required before production:**
- Cost controls (C-1) and tightened paid-endpoint limits (H-4).
- Trusted-proxy handling or forced `SESSION_SECURE_COOKIE=always` + always-on HSTS (H-5).
- Worker resilience + supervision (H-1) and memory bounds (H-2).
- A CI gate (M-13) and at least the minimal HTTP/DB test harness (H-6).
- **Scheduled** API-log retention and `recover-jobs` — these are documented but must actually be installed as cron/systemd; if unscheduled, logs grow unbounded and abandoned jobs are only recovered on worker poll.
- Job retention (H-3).

**In place / strengths:** health check (200/503, no infra leak), forced `display_errors=0`, request IDs, `APP_SECRET` boot check, log rotation, DB-backed queue + rate limiting (horizontally scalable), documented systemd/cron/backups, minimal current dependencies.

**Gaps to note:** `docker-compose.yml` provisions only MySQL (no app/worker container, no Dockerfile) — deployment is manual (M-13/DP2); native file-based sessions assume a single web node for the admin (fine for one admin); forward-only migrations with no rollback (M-11); supervision templates are documented but not shipped (add `deploy/` templates).

---

## 16. Recommended Improvement Roadmap

### Phase A — Immediate Production Blockers
| Item | Goal | Reason | Main files | Complexity | Risk | Validation |
|---|---|---|---|---|---|---|
| A1. Cost budgets (C-1) | Enforce per-key + global token/spend caps before provider calls | Prevent unbounded OpenAI spend from a leaked/abusive key | `app/Domain/ApiKeys/*`, new migration on `api_keys`, `AnswerGenerator`, retrieve/chat handlers, `config/api.php` | Large | Medium | Key at cap → 429, no provider call |
| A2. Trusted proxy + secure cookie (H-5) | Correct client IP + forced Secure/HSTS behind proxy | Session security + effective per-IP throttling | `app/Http/Request.php`, `SecurityHeadersMiddleware`, `config/auth.php`, new `TRUSTED_PROXIES` | Medium | Medium | Proxied request asserts IP/Secure/HSTS |
| A3. Worker resilience (H-1, M-10) | Loop survives transient errors; PDO reconnects | Prevent silent ingestion outage | `IngestionWorker.php`, `bin/worker.php`, `Connection.php` | Small | Low | Injected error → loop continues |
| A4. Minimal CI + HTTP/DB smoke tests (H-6, M-13) | Gate regressions; test wiring + DB | Nothing currently enforces the suite or tests wiring | `.github/workflows/*`, `tests/Http/*`, env-gated `tests/Database/*` | Medium | Low | Mis-wired route / broken migration fails CI |

### Phase B — Reliability & Operational Safety
| Item | Goal | Files | Complexity | Risk |
|---|---|---|---|---|
| B1. Large-file memory bounds (H-2) | Avoid OOM crash-loop | `IngestionWorker`, `PdfExtractor`, `config` | Medium | Low |
| B2. `ingestion_jobs` retention (H-3) | Bound growth | new migration/command mirroring API-log purge | Small | Low |
| B3. Failed-job alert + provider error messages (L-8) | Operator visibility | `IngestionQueue::safeError`, dashboard | Small | Low |
| B4. Duplicate-enqueue guard (L-9) | Prevent wasted jobs | `PdoIngestionJobRepository`, migration (partial unique) | Small | Low |
| B5. Ship supervision templates | Reproducible ops | `deploy/systemd`, `deploy/cron` | Small | Low |

### Phase C — Performance & Scalability
| Item | Goal | Files | Complexity | Risk |
|---|---|---|---|---|
| C1. Vector search overhaul (C-2) | Datastore-side / binary vectors / ANN | `PdoVectorStore`, `CosineSimilarity`, migration | Large | Medium-High |
| C2. Dashboard aggregate fixes (M-1) | Remove full-table scans | `PdoApiRequestLogRepository`, `PdoIngestionJobRepository` | Small | Low |
| C3. Move rate-limit prune off hot path (M-7) | Reduce write amplification | `ApiRateLimiter`, new cron | Small | Low |
| C4. Query-embedding cache (L-1) | Cut repeat spend/latency | `Retriever`, new cache | Medium | Low |

### Phase D — RAG Quality
| Item | Goal | Files | Complexity | Risk |
|---|---|---|---|---|
| D1. Tune similarity threshold (M-2) | Reduce noise | `config/rag.php` + eval | Small | Low |
| D2. Real tokenizer (M-3) | Accurate budgeting | `HeuristicTokenEstimator` callers | Medium | Low |
| D3. Structured-content chunking (M-5) | Better table/code answers | `SemanticChunker`, `HtmlDocumentParser` | Medium | Medium |
| D4. Dedup + hybrid/rerank (M-4, L-2 retrieval) | Precision/recall | `Retriever`, `PdoVectorStore` | Large | Medium |
| D5. Chat retries + streaming + output cap (M-6, L-7) | Reliability/UX | `config/providers.php`, chat provider/controller | Medium | Low |

### Phase E — Maintainability & DX
| Item | Goal | Files | Complexity | Risk |
|---|---|---|---|---|
| E1. Remove dead Anthropic config (M-12) / `APP_DEBUG` (L-11) | Reduce confusion | `.env.example`, `config` | Small | Low |
| E2. PHPStan + coverage gate (L-12) | Objective quality | `composer.json`, `phpunit.xml` | Small | Low |
| E3. Admin password reset (§9) | Recoverability | new `bin/reset-admin-password.php` | Small | Low |
| E4. Security middleware tests (M-14) | Lock in controls | `tests/Unit/*Middleware*` | Medium | Low |
| E5. Migration atomicity/rollback docs (M-11) | Safe deploys | migrations, README | Small | Low |

Each roadmap row's "one commit vs. split" guidance is expanded in §17.

---

## 17. Suggested Micro-Milestones

Each ≈ one reviewable commit; one objective; independently testable; no mixing refactor with behavior change.

1. **MM-1 — Worker resilience loop (H-1).** Wrap `runOnce()` in try/catch-with-backoff; log and continue. *Review before next:* confirm an injected `claim()` error is logged and the loop survives; systemd `Restart=always` documented.
2. **MM-2 — PDO reconnect (M-10).** Detect "gone away" and reconnect (or `ping()` before claim). *Review:* simulated dropped connection recovers on next iteration.
3. **MM-3 — CI workflow (M-13).** GitHub Actions: install, `composer test`, `php -l` sweep, `validate --strict`, `audit`. *Review:* pipeline green on a clean checkout; fails on a deliberate lint error.
4. **MM-4 — HTTP smoke harness (H-6a).** Boot `bootstrap/app.php` against a test DB; assert login, API-key 401, 429, CSRF reject. *Review:* a mis-wired route fails the harness.
5. **MM-5 — Real-DB test group (H-6b), env-gated.** Migrations + claim/retry/recover + retention purge round-trip. *Review:* group runs in CI service, skipped offline.
6. **MM-6 — `api_keys` budget columns + migration (C-1a).** Schema + value objects only, no enforcement yet. *Review:* migration is idempotent; existing tests pass.
7. **MM-7 — Pre-call budget gate (C-1b).** Enforce per-key/global caps before embedding/chat; return `429 quota_exceeded`. *Review:* provider fake asserts no call once over cap.
8. **MM-8 — Trusted-proxy config (H-5a).** Opt-in `TRUSTED_PROXIES` for `X-Forwarded-For`/`-Proto`; default off. *Review:* proxied vs direct requests resolve IP/scheme correctly.
9. **MM-9 — Forced Secure/HSTS in prod (H-5b).** Honor `SESSION_SECURE_COOKIE=always`; always emit HSTS in production. *Review:* headers/cookie flags asserted.
10. **MM-10 — `ingestion_jobs` retention (H-3).** Batched purge command + cron template. *Review:* terminal jobs beyond cutoff removed; live jobs untouched.
11. **MM-11 — Dashboard aggregate fix (M-1).** Source dropdown from `api_keys`/`sources` directly. *Review:* `EXPLAIN` shows no full-table group; same UI output.
12. **MM-12 — Similarity threshold + eval note (M-2).** Raise default; add a small retrieval eval fixture. *Review:* eval precision improves without recall collapse.

(Vector-search overhaul C-2 and structured chunking D3/D4 are **multi-commit** efforts, not single milestones — spec them separately with a benchmark harness first.)

---

## 18. Open Questions and Unverified Areas

Static inspection cannot confirm:
- **Production topology** — whether a reverse proxy is used (determines H-5 severity) and whether `SESSION_SECURE_COOKIE` is set to `always`.
- **Worker supervision** — whether `bin/worker.php` actually runs under systemd with restart, and whether `recover-jobs`/`prune-api-requests` cron is installed (retention/recovery depend on it).
- **Real vector-store scale** — actual chunk counts and per-query latency/memory in the deployed corpus (governs when C-2 bites).
- **OpenAI account limits** — configured spend/rate caps on the OpenAI side (the only current backstop against C-1).
- **OCR runtime** — whether `ocrmypdf`/Tesseract is installed and its real memory/time profile.
- **Backups/restore** — whether the documented DB + `storage/` backup procedure is actually scheduled and tested.
- **MySQL settings** — `wait_timeout`, `max_allowed_packet` (large `extracted_text`/vectors), and InnoDB buffer sizing.
- **Runtime PHP limits** — worker `memory_limit` (governs H-2) and FPM timeouts.
- **TLS termination** and certificate management.

All of these should be confirmed operationally, ideally via a staging environment with a production-sized corpus.

---

## 19. Top 10 Recommended Next Actions

1. **Add enforced per-key + global cost/token budgets before provider calls (C-1).** The single largest financial risk.
2. **Harden the worker loop and add PDO reconnect (H-1, M-10);** require a restart-always supervisor. Prevents silent ingestion outages.
3. **Add trusted-proxy handling and force `Secure`/HSTS in production (H-5).** Closes the main deployment-security gap.
4. **Stand up CI + a minimal HTTP/DB smoke-test harness (M-13, H-6).** Gives the strong suite something to protect and finally tests the wiring and SQL.
5. **Bound worker memory for large PDFs/OCR (H-2)** to avoid the OOM crash-retry loop.
6. **Add `ingestion_jobs` retention and ensure API-log retention/recover-jobs cron is actually scheduled (H-3, §15).**
7. **Tighten paid-endpoint rate limits and add query-embedding caching (H-4, L-1).**
8. **Plan and benchmark the vector-search overhaul (C-2)** — binary vectors + datastore-side scoring or a real ANN index; document the safe corpus ceiling meanwhile.
9. **Raise the similarity threshold, adopt a real tokenizer, and add cross-source/near-duplicate dedup (M-2, M-3, M-4)** to lift answer precision.
10. **Close DX/recoverability gaps:** remove dead Anthropic/`APP_DEBUG` config, add an admin password-reset command, add PHPStan + a coverage gate, and add tests for CSRF/admin-auth/session middleware (M-12, L-11, §9, L-12, M-14).

---

*End of review. No application code was modified. Awaiting instruction before implementing any changes.*
