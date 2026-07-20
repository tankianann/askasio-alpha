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
| API keys | Hash-only broad server keys | Decide whether scoped integration credentials extend or remain separate from `api_keys`. |
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

### 1 — Decision records and vertical-slice design

- Decide publication snapshot/version representation.
- Decide session bearer token/hash and browser storage.
- Decide conversation content-storage defaults and deletion semantics.
- Decide integration credential relationship to existing API keys.
- Decide initial chatbot settings schema and model selection policy.
- Add accepted material decisions to `docs/decisions.md`.

No migration until these decisions are approved.

### 2 — Core chatbot domain and persistence

- Add chatbots, draft/public lifecycle, public ID generation/rotation, repository/service validation, list projections, migrations, and tests.
- Use the installation provider/model; do not add provider credential storage.
- Document schema, publication behavior, migration, and forward repair.

### 3 — Source assignments and publication

- Add the chatbot/source relation, ready/compatible validation, dependency queries, transactional draft edits/publication, and source-scope tests.
- Preserve existing active-version semantics.

### 4 — Admin list and create/edit flow

- Add navigation, paginated list, filters/sort, bounded form sections, validation, lifecycle actions, and safe empty/error states.
- Keep unsupported settings out of the UI.

### 5 — Conversation sessions/messages

- Add session/message persistence, hashed authorization tokens, expiry/status/counts, test classification, idempotency reservation, concurrency protection, migrations, and retention indexes.
- Do not expose a public endpoint yet.

### 6 — Shared chat execution service

- Refactor/extend current chat orchestration behind one preview/public service.
- Enforce assigned sources, history budget, fallback, citation projection, usage, error mapping, persistence, and quota reconciliation.
- Keep existing authenticated `/api/v1/chat` compatible unless a separate announced change is approved.

### 7 — Admin preview

- Add draft-capable test sessions using the shared execution service.
- Show safe retrieval/usage diagnostics and separate test traffic.

### 8 — Public configuration and session API

- Add public v1 routes, DTOs, exact origin/CORS policy, session creation, rate limits, token handling, strict schemas, safe errors, and HTTP/security tests.

### 9 — Public message API

- Add non-streaming message submission, idempotency, session/chatbot/origin validation, execution, persistence, limits, failure recovery, and usage integration.

### 10 — Widget foundation

- Add versioned async loader and isolated accessible UI.
- Implement configuration/session/message calls, browser storage, restart, error states, responsive behavior, rendering safety, and compatibility tests.

### 11 — Conversation administration and retention

- Add bounded list/detail views, test/production distinction, filters/pagination, scheduled retention, confirmed manual purge, advisory locking, and audit behavior.

### 12 — Scoped server integration credentials

- Add separate or extended credential model per approved ADR, chatbot scope relation, one-time secret, hash verification, lifecycle UI, middleware, limits, usage, API examples, and tests.
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

1. Published snapshot tables versus a validated versioned JSON snapshot.
2. Exact chatbot statuses and archive/permanent-delete semantics.
3. Session token format/hash, transport header, expiry, and browser storage.
4. Default transcript storage and retention; public close versus erasure.
5. Oldest-turn truncation limits and whether summaries remain deferred.
6. Per-chatbot chat model override allowlist versus installation model only.
7. Which settings require normalized columns versus validated JSON.
8. Separate integration credential type/prefix versus scoped extension of `api_keys`.
9. Iframe versus Shadow DOM widget isolation.
10. Public citation URL rules for uploaded versus URL sources.
11. Whether trusted-proxy hardening must precede any internet deployment.

## First implementation recommendation

After review of this documentation baseline, begin with milestone 1 only: settle and record the publication, session-token, privacy, and credential decisions. Those choices determine the schema and public security model; starting migrations or UI first would make later correction expensive.

