# Architecture decisions

This is a compact ADR register. Status is **Accepted** unless otherwise noted. New decisions should be appended with context, alternatives, consequences, and a date; do not silently rewrite historical rationale.

## ADR-001 — Build the application without an MVC framework

**Context.** The project required a standalone PHP application and explicitly excluded Laravel, Symfony, Slim, and similar frameworks.

**Decision.** Build a small front controller, router, middleware chain, request/response objects, controllers, services, repositories, and view renderer from scratch. Use focused Composer packages only for specialized concerns.

**Alternatives.** Full framework; microframework; procedural scripts.

**Why.** It meets the deployment constraint, keeps dependencies focused, and makes the core architecture explicit without collapsing into unstructured procedural code.

**Trade-offs and implications.** The project owns routing, middleware order, validation, DI composition, error boundaries, and security maintenance. Changes to these primitives require especially strong tests.

## ADR-002 — Use a single explicit composition root

**Decision.** `bootstrap/app.php` constructs the web object graph with constructor injection; separate bootstraps exist for worker, maintenance, queue, and RAG CLI needs.

**Alternatives.** Static service locator, global container, reflection/autowiring.

**Why.** Dependencies remain visible and testable without framework/container complexity.

**Trade-offs.** Bootstrap files are long and must be covered by HTTP smoke tests. Per-request provider/RAG construction is slightly repetitive.

## ADR-003 — Serve only `public/`

**Decision.** The web server document root is `public/`; all code, configuration, logs, uploads, and CLI commands remain outside it.

**Why.** This is the primary defense against accidental `.env`, source, upload, or log disclosure.

**Implication.** Apache/Nginx/Herd must route missing public paths through `public/index.php`; serving the repository root is unsupported and unsafe.

## ADR-004 — Use environment-backed immutable configuration

**Decision.** Load `.env` through `phpdotenv`, convert it to typed arrays in `config/*.php`, and access through `Config` rather than environment calls scattered throughout the application.

**Alternatives.** Database settings; constants; runtime mutable configuration.

**Why.** Infrastructure and secrets stay out of source control while types/defaults remain reviewable.

**Trade-offs.** Most setting changes require a process restart. The `settings` table is reserved but currently unused.

## ADR-005 — PDO repositories own SQL

**Decision.** Controllers call services/repository interfaces; PDO implementations and allowlisted query builders own SQL. Use native prepared statements and transactions for multi-step changes.

**Alternatives.** ORM/query builder; SQL in controllers.

**Why.** Predictable SQL, small dependency surface, and testable integration boundaries.

**Trade-offs.** More manual mapping and SQL maintenance. MySQL/MariaDB compatibility must be tested deliberately.

## ADR-006 — Optimize for one administrator, not a hidden multi-user model

**Decision.** Store one administrator, create it via CLI, and omit registration, invitations, password reset, roles, and tenant ownership.

**Why.** It matches v1 scope and minimizes public attack surface.

**Implication.** Multi-tenancy is a schema and authorization redesign, not a simple `users` controller addition.

## ADR-007 — Secure native PHP sessions and CSRF-protect admin mutations

**Decision.** Use strict cookie-only sessions with idle expiry, periodic/login regeneration, `HttpOnly`, `SameSite=Lax`, configurable `Secure`, and a session-bound random CSRF token on every state-changing admin form.

**Alternatives.** Stateless admin tokens; third-party auth package.

**Why.** Native sessions are sufficient for one administrator when hardened and keep browser authentication separate from application API keys.

**Trade-off.** Correct scheme detection currently depends on the direct connection because forwarded headers are not trusted.

## ADR-008 — Store API keys only as a hash and show plaintext once

**Decision.** Generate `rag_live_` keys from 256 random bits, show the complete value only at creation, store a visible prefix plus SHA-256 hash, and compare using `hash_equals()`.

**Why.** Database disclosure does not reveal usable application credentials.

**Implication.** Lost keys cannot be recovered; create a new key and revoke the old one.

## ADR-009 — Separate sources from immutable versions

**Decision.** A source is mutable identity/availability; every upload, refresh, replacement, and reprocess creates an immutable version.

**Alternatives.** Overwrite the current document; store only latest content.

**Why.** It enables safe rollback/history, background processing, unchanged-content detection, and atomic activation without retrieval downtime.

**Trade-off.** Storage grows until permanent deletion; soft deletion is not erasure.

## ADR-010 — Activate only after extraction, chunking, and embedding succeed

**Decision.** Keep the prior active version until all artifacts for the new version commit transactionally. Prevent older out-of-order jobs from superseding newer versions.

**Why.** Retrieval must never observe partially processed content or regress because workers finish out of order.

**Implication.** A failed replacement remains visible in history while the previous active knowledge stays live.

## ADR-011 — Use a MySQL-backed ingestion queue

**Decision.** Persist jobs in MySQL, atomically claim with `FOR UPDATE SKIP LOCKED`, identify worker ownership, retry with exponential delay, and recover abandoned reservations.

**Alternatives.** Redis/RabbitMQ; synchronous ingestion in uploads; filesystem queue.

**Why.** MySQL is already required, durable, cron-compatible, and sufficient for initial volume.

**Trade-offs.** Queue and application data share database resources. Terminal-job retention and large multi-worker scale need further work.

## ADR-012 — Fail worker infrastructure errors and let supervision restart

**Decision.** Controlled document/provider failures update the job and continue; unexpected queue/database/infrastructure failures log critically and exit. The supplied systemd unit uses `Restart=on-failure`.

**Alternatives.** Catch all exceptions and keep looping; reconnect PDO inside the worker.

**Why.** Continuing with an uncertain long-lived PDO/process state risks silent corruption or an unhealthy loop. A clean supervised process is easier to reason about.

**Trade-off.** Production must actually install monitoring/supervision. Cron one-shot deployments naturally create a fresh process each run.

## ADR-013 — Bound document work before paid embeddings

**Decision.** Enforce finite upload, response, extracted-character, PDF-page, chunk-count, OCR-size, timeout, and batch boundaries. Validate that abandoned-job timeout exceeds the worst configured processing window.

**Why.** Prevent OOM poison jobs, runaway OCR, excessive provider cost, and premature duplicate recovery.

**Implication.** Raising limits is an operational capacity decision, not a presentation tweak.

## ADR-014 — Use replaceable extractor/chunker/provider/vector interfaces

**Decision.** Define interfaces at genuine integration boundaries: source extraction, chunking, embedding, chat, URL fetching, OCR, vector storage, and repositories.

**Why.** Tests use fakes and future providers/vector stores can replace adapters without rewriting controllers.

**Trade-off.** Avoid adding interfaces for purely internal classes; abstraction is reserved for unstable boundaries.

## ADR-015 — OCR with a host executable, only after native PDF extraction finds no text

**Decision.** Use Smalot PDF Parser first, then invoke OCRmyPDF/Tesseract as a shell-free subprocess when no text is extractable.

**Alternatives.** Pure-PHP OCR package; cloud OCR; OCR every PDF.

**Why.** Mature OCR quality is not realistically available as a standard PHP library; local tooling avoids sending documents to another service.

**Trade-offs.** OCR requires host installation and more CPU/memory. Hybrid PDFs with sparse text do not currently trigger OCR.

## ADR-016 — Store embeddings as JSON and score cosine similarity in PHP for v1

**Decision.** Keep vectors in MySQL JSON behind `VectorStoreInterface` and compute cosine similarity locally.

**Alternatives.** Dedicated vector database, MySQL vector extension, binary vectors, external search service.

**Why.** It avoids another service and was fastest to ship for a small single-user corpus.

**Trade-off.** Search loads and scores the compatible corpus in PHP and is the system's main scaling ceiling. Replace before large-scale use.

## ADR-017 — Use OpenAI first but keep provider boundaries

**Decision.** Implement OpenAI embeddings and Responses API chat with configured model names, typed provider failures, TLS/timeouts, and no provider-side chat storage.

**Alternatives.** Hardwire OpenAI throughout; implement multiple providers immediately.

**Why.** One complete provider is more valuable than incomplete abstraction, while interfaces preserve replacement options.

**Trade-off.** Anthropic configuration placeholders are currently dead and should be removed or implemented.

## ADR-018 — Build grounded prompts as instructions plus untrusted JSON

**Decision.** Keep fixed grounding rules in the instruction channel; serialize the question and source excerpts as JSON data with stable `[S#]` references. Validate returned citation syntax/range.

**Why.** It reduces prompt-injection ambiguity and gives clients structured citations.

**Trade-off.** Citation validation checks reference existence/range, not semantic faithfulness; evaluation remains necessary.

## ADR-019 — Do not call chat when evidence is absent

**Decision.** If no retrieved chunks meet threshold/context selection, return the fixed insufficient-information response without a chat-generation call.

**Why.** Enforces grounded behavior and avoids unnecessary cost.

## ADR-020 — Separate rate limits from provider token quotas

**Decision.** Use fixed-window per-key/IP request limits for traffic control and atomic global/per-key daily/monthly token budgets for spend control.

**Why.** Request count does not bound cost; concurrency-safe reservations are required before paid calls.

**Trade-offs.** Token limits are shared defaults rather than per-key overrides, use conservative estimates, and exclude administrator ingestion embeddings. Provider-account hard budgets remain necessary.

## ADR-021 — Minimize API Activity data

**Decision.** Store diagnostic metadata and numeric usage only, never authorization headers, plaintext keys, raw IPs, query strings, bodies, prompts, retrieved context, citations, or answers.

**Why.** Operational usefulness does not justify creating a sensitive conversation transcript.

**Implication.** Deep content-level debugging must be reproduced rather than recovered from activity logs.

## ADR-022 — Use server-side offset pagination with URL state

**Decision.** Dashboard lists filter/sort/count/paginate in SQL; default 25 with 50/100 options; query parameters preserve state; invalid deep pages redirect canonically.

**Alternatives.** Browser pagination over all rows; keyset pagination everywhere.

**Why.** One administrator benefits from exact totals and numbered pages, and retained data is currently moderate.

**Trade-off.** Deep offsets and exact counts become expensive at millions of rows; API Activity/Processing should then move to keyset pagination.

## ADR-023 — Make API Activity retention explicit and safe

**Decision.** Provide configurable retention, a batched advisory-locked CLI purge, and a CSRF-protected manual flow with authoritative count, exact phrase, short-lived server-side intent, and maximum reviewed ID.

**Why.** Activity grows fastest and deletion must neither lock huge tables nor delete new/unreviewed records.

## ADR-024 — Use UTC in persistence and explicit application timezone at input/display boundaries

**Decision.** Set the database connection timezone to UTC and store all operational timestamps in UTC. Convert administrator date filters using `APP_TIMEZONE`.

**Why.** Workers, cron, web processes, and deployments must agree across daylight-saving and host timezone differences.

## ADR-025 — Use forward-only migrations

**Decision.** Applied filenames are immutable; schema corrections use new migrations.

**Why.** MySQL DDL may auto-commit, so rollback cannot be assumed despite transaction attempts.

## ADR-026 — Keep the application framework-free and Redis-free until measured need

**Decision.** Do not add a general cache, Redis, or message broker in v1.

**Why.** MySQL and filesystem primitives cover present volume and simplify ordinary VPS deployment.

**Trade-off.** Repeated query embeddings are not cached, and queue/vector scaling will eventually require specialized infrastructure.

