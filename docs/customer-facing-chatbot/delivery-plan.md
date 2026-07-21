# Delivery plan

## Working method

Each micro-milestone should be one focused, reviewable change. Before code in a milestone, inspect the current implementation and update this living specification if assumptions changed. At each review gate provide behavior/files changed, design decisions, migrations/config/API/security effects, exact tests, documentation updates, a suggested commit message, and stop for review.

No feature code begins until the documentation baseline and open decisions for the next milestone are approved.

## Dependencies and conflicts discovered

| Topic | Current application | Feature consequence |
| --- | --- | --- |
| Ownership | One admin, one tenant, no tenant table | No tenant columns or cross-tenant abstractions; enforce chatbot/session/source/credential scopes. |
| Provider credentials | One OpenAI key/model in environment | Initial chatbots reuse installation provider; named credential storage is deferred. |
| RAG | Stateless grounded `/api/v1/chat`; `conversation_id` rejected | Extract/shared execution carefully; add session behavior without duplicating RAG. |
| Public browser access | Existing API keys and no CORS | Add a separate public namespace, origin policy, session tokens, and abuse controls. |
| API keys | Hash-only broad server keys | Scoped chatbot integration credentials are a separate hash-only resource; existing keys remain compatible. |
| Quotas | Global/per-API-key daily/monthly provider tokens | Add chatbot/session/integration dimensions without weakening atomic reservation. |
| Conversation data | Not stored; Activity explicitly excludes content | Create an explicit transcript privacy/retention model; do not change Activity silently. |
| Streaming | Not implemented; Apache/PHP-FPM baseline | Non-streaming first; streaming requires a later infrastructure decision. |
| Proxy identity | Forwarded headers intentionally untrusted | Trusted-proxy milestone is a security dependency for production IP limits. |
| Vector search | Full compatible corpus scanned in PHP | Source assignment narrows candidates, but scale limits remain and must be benchmarked. |

## Micro-milestones

### 0 — Documentation baseline (current)

- Split and reconcile the source brief with current repository docs/code.
- Establish single-administrator/single-tenant language and schema.
- Record target product, admin, architecture, API, security, operations, tests, and plan.
- Add the feature directory to the docs index and roadmap.

Deliverable: documentation only.

### 1 — Decision records and vertical-slice design (complete)

- Decide publication snapshot/version representation.
- Decide session bearer token/hash and browser storage.
- Decide conversation content-storage defaults and deletion semantics.
- Decide integration credential relationship to existing API keys.
- Decide initial chatbot settings schema and model selection policy.
- Add accepted material decisions to `docs/decisions.md`.

Outcome: ADRs 027–031 and [Vertical-slice design](vertical-slice-design.md) establish immutable publication snapshots, hash-only per-tab session tokens, bounded transcript retention/hard deletion, separate integration credentials, normalized settings, and installation-wide model selection. No migration was created.

### 2 — Core chatbot domain and persistence (complete)

- Add chatbot identity and mutable draft persistence, public ID generation/rotation, optimistic draft revisions, repository/service validation, list projections, migrations, and tests.
- Use the installation provider/model; do not add provider credential storage.
- Document schema, publication behavior, migration, and forward repair.

Outcome: migration `20260720000013`, chatbot domain records/enums, mutable validated drafts with optimistic revision, immutable core publications, installation provider/model snapshots, `cb_` public ID rotation, PDO/in-memory repositories, lifecycle service, bounded list projections, and unit/real-MySQL tests. No route, UI, bootstrap wiring, source/origin relation, session, or provider credential storage was added. See [Core domain and persistence](core-domain-persistence.md).

### 3 — Source assignments and publication (complete)

- Add mutable draft source/origin relations, immutable publication/source/origin snapshots, active-publication pointer, ready/compatible validation, dependency queries, transactional publication, and source-scope tests.
- Preserve existing active-version semantics.

Outcome: migration `20260720000014`, mutable normalized draft source/origin relations on the shared optimistic revision, immutable publication relation snapshots, assignment-inclusive hashes, active-version ready/model/dimension validation, atomic publication activation, source dependency/deletion protection, and unit/opt-in real-MySQL scope tests. No chatbot route, UI, session, public API, widget, or provider credential storage was added. See [Source assignments and publication](source-assignments-and-publication.md).

### 4 — Admin list and create/edit flow (complete)

- Add navigation, paginated list, filters/sort, bounded form sections, validation, lifecycle actions, and safe empty/error states.
- Keep unsupported settings out of the UI.

Outcome: authenticated/CSRF-protected chatbot navigation and routes, bounded SQL pagination/search/status/publication/model/source filters, allowlisted sorting, private draft creation, validated configuration sections, paginated source picker, origin editing, publication and lifecycle controls, provider-degraded behavior, safe empty/error states, and controller/query tests. No migration, public chatbot endpoint, session, preview, widget, conversation UI, analytics, or credential storage was added. See [Administrator list and edit flow](admin-list-and-edit.md).

### 5 — Conversation sessions/messages (complete)

- Add session/message persistence, 256-bit hash-only authorization tokens, publication binding, copied `0|7|30|90` retention, expiry/status/counts, test classification, idempotency reservation, concurrency protection, migrations, and retention indexes.
- Do not expose a public endpoint yet.

Outcome: migration `20260720000015`, publication-bound session/message records, independent 256-bit public IDs and hash-only bearer tokens, copied expiry/limit/retention policy, immutable test classification, atomic idempotency/user-turn reservation, completion/failure usage accounting, bounded expiry/purge operations, retention indexes, and unit/opt-in real-MySQL tests. No public route, browser storage, execution/provider call, preview UI, scheduler, widget, or integration credential was added. See [Conversation session and message persistence](conversation-persistence.md).

### 6 — Shared chat execution service (complete)

- Refactor/extend current chat orchestration behind one preview/public service.
- Enforce assigned sources, history budget, fallback, citation projection, usage, error mapping, persistence, and quota reconciliation.
- Keep existing authenticated `/api/v1/chat` compatible unless a separate announced change is approved.

Outcome: one internal preview/public execution service over the existing grounded RAG components, with immutable publication/provider validation, assigned-source retrieval, `recent_completed_turns_v1` history budgeting, configured no-evidence fallback, public-safe versus administrator citation projections, stable error mapping, message/outcome/usage persistence, and installation-wide quota reservation/reconciliation. The existing authenticated `/api/v1/chat` request/response/error behavior remains compatible and stateless. No preview/public route, CORS, widget, streaming, or integration credential was added. See [Shared chat execution service](shared-chat-execution.md).

### 7 — Admin preview (complete)

- Add draft-capable test sessions using the shared execution service.
- Show safe retrieval/usage diagnostics and separate test traffic.

Outcome: authenticated/CSRF-protected draft preview, immutable revision/configuration/source/provider snapshots, server-session-held one-time bearer tokens, automatic revision/terminal-session rollover, transcript/restart controls, the shared source-scoped executor, bounded administrator-only retrieval and usage diagnostics, and immutable `admin_preview`/`is_test` traffic classification. Migration `20260720000016` adds the exclusive publication-or-draft execution binding. No public endpoint, CORS, widget, integration credential, or production conversation administration was added. See [Administrator preview](admin-preview.md).

### 8 — Public configuration and session API (complete)

- Add public v1 routes, DTOs, exact origin/CORS policy, session creation, rate limits, token handling, strict schemas, safe errors, and HTTP/security tests.

Outcome: versioned public configuration/session routes and preflights, immutable publication-only DTO projection, exact normalized publication-origin authorization, origin-aware safe JSON errors, strict empty-object session schema, transactional production session creation, one-time 256-bit bearer response with hash-only persistence, independent HMAC IP/chatbot fixed-window limits, privacy-minimized Activity, migration `20260721000017`, and HTTP/security tests. No public message/delete/restart endpoint, widget, browser storage implementation, streaming, or integration credential was added. See [Public configuration and session API](public-configuration-and-session-api.md).

### 9 — Public message API (complete)

- Add non-streaming message submission, idempotency, session/chatbot/origin validation, execution, persistence, limits, failure recovery, and usage integration.

Outcome: bearer-authenticated production message/completion routes, strict request DTOs, exact session/chatbot/origin/publication binding, shared grounded execution, idempotent replay/conflict/in-progress semantics, stale-pending recovery, durable safe failures and usage, per-IP/chatbot/session limits, migration `20260721000018`, and HTTP/security tests. See [Public message API](public-message-api.md).

### 10 — Widget foundation (complete)

- Add versioned async loader and isolated accessible UI.
- Implement configuration/session/message calls, browser storage, restart, error states, responsive behavior, rendering safety, and compatibility tests.

Outcome: async versioned `v1` loader and stylesheet, Shadow DOM isolation, accessible keyboard/focus/live-region behavior, public API integration, per-tab hash-token bearer storage, lazy sessions, completion-backed restart, bounded friendly errors, safe text/citation rendering, responsive/high-contrast/reduced-motion styles, and compatibility/browser checks. See [Widget foundation](widget-foundation.md).

### 11 — Conversation administration and retention

- Add bounded list/detail views, test/production distinction, filters/pagination, scheduled retention, confirmed manual purge, advisory locking, and audit behavior.

### 12 — Scoped server integration credentials

- Add the separate credential model from ADR-030, chatbot scope relation, one-time secret, hash verification, lifecycle UI, middleware, limits, usage, API examples, and tests.
- A packaged WordPress plugin remains deferred.

### 13 — Analytics and operational readiness

- Add bounded chatbot/session summaries using existing privacy and request-ID conventions.
- Finalize config inventory, deployment, monitoring, troubleshooting, backup/restore, migration and rollback/forward-fix docs.
- Validate provider hard limits and trusted-proxy deployment dependency.

### 14 — Security/accessibility/end-to-end release gate

- Run full quality gate, real-DB/HTTP/security tests, representative RAG evaluation, widget browser/mobile/accessibility checks, origin/CORS matrix, abuse/failure exercises, and restore/release rehearsal.
- Resolve release blockers and document accepted limitations.

### Optional later — Streaming

- Benchmark and approve transport/infrastructure.
- Specify event protocol, buffering/cancellation, persistence, quota reconciliation, and idempotent retry behavior before implementation.

## Open decisions

1. Exact numeric bounds/defaults for message/session expiry and retrieval settings (set during typed validation/config implementation without changing ADR-031).
2. Whether trusted-proxy hardening must precede any internet deployment.

## First implementation recommendation

After review of milestones 9 and 10, begin milestone 11 with bounded conversation administration and retention enforcement.
