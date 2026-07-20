# Roadmap

## Current state

Ask Asio's original nine milestones, dashboard data-lifecycle audit, CI/real-database quality gate, worker/document safety hardening, and authenticated provider quota enforcement are complete. A documentation-only baseline now exists for the customer-facing chatbot; no chatbot feature code has been implemented.

The application is suitable for a controlled single-administrator deployment after the operator completes the production checklist. The largest remaining architecture limit is full-corpus vector scoring in PHP. The most immediate deployment-security gap is trusted reverse-proxy handling.

## Completed

### Milestone 1 — Foundation

- Framework-free project structure, Composer/PSR-4, environment configuration, PDO connection, ordered migrations.
- Request/response/router/middleware primitives, safe errors, request IDs, logging, health endpoint, initial tests and setup documentation.

### Milestone 2 — Administrator authentication

- Single administrator table and interactive CLI creation.
- Secure password hashing/verification, strict sessions, login/logout, CSRF, login throttling, protected layout.

### Milestone 3 — Source management

- Sources and immutable versions for URL, Markdown, and PDF.
- Secure upload validation/storage, lists/details/history, enable/disable, and soft deletion.

### Milestone 4 — Ingestion jobs

- MySQL queue, atomic claims, attempts/retry, abandoned recovery, CLI worker/one-shot processing, processing UI and logs.

### Milestone 5 — Extraction and chunking

- URL/Markdown/PDF extractors, readable HTML parsing, OCR fallback, metadata-aware extracted documents, semantic chunking, hashes, and tests.

### Milestone 6 — Embeddings and retrieval

- OpenAI embedding adapter, JSON vector persistence, model/dimension compatibility, PHP cosine search, retriever, filters, CLI debugger, `/api/v1/retrieve`.

### Milestone 7 — API key management

- One-time API-key creation, hash-only persistence, expiry/revocation/deletion, bearer middleware, per-key/IP rate limits, privacy-minimized request logs.

### Milestone 8 — Grounded chat

- Grounded prompt builder, OpenAI Responses API adapter, context selection, citation validation, `/api/v1/chat`, provider error mapping, usage reporting.

### Milestone 9 — Source lifecycle and operational hardening

- Replacement/refresh/reprocess workflows, ordered atomic activation, permanent deletion, recovery, production/security documentation.

### Dashboard data-lifecycle audit

- Shared validated query state and server-side pagination.
- API Activity pagination, extensive filters/sorting, URL persistence, counts and empty states.
- Configurable scheduled retention and confirmed filter-aware manual purge.
- Paginated/filterable Knowledge Base, Processing, and API Access.
- Paginated source revision/job histories with large extracted text loaded only on demand.
- Supporting indexes, query-plan review, privacy regression coverage, and documentation.

### Production-blocker hardening

- GitHub Actions across PHP 8.3/8.4 with MySQL 8.4, lint, Composer validation/audit, PHPStan level 5, unit and real-DB HTTP/migration tests.
- Deliberate supervised-worker failure policy, hardened systemd unit, validated timeout relationships, document/PDF/chunk/OCR bounds, oversized-document tests.
- Atomic global/per-key daily/monthly provider token reservations, reconciliation, usage display/reporting, expiry recovery, and no-provider-call rejection tests.

## In progress

### Customer-facing chatbot specification review

The source brief has been reconciled with the current single-administrator, single-tenant architecture and split into a living specification under [Customer-facing chatbot](customer-facing-chatbot/README.md). ADRs 027–031 and the vertical-slice design now settle publication snapshots, session-token/browser-storage design, transcript privacy/retention, model settings, and integration-credential architecture. After review, the next feature step is chatbot identity and mutable draft persistence only. No schema, API, UI, or application code has been added yet.

Trusted reverse-proxy support remains a production security dependency for reliable client-IP rate limiting when deployed behind a TLS terminator.

## Planned by priority

### P0 — Trusted reverse-proxy and scheme/IP correctness

Add an opt-in `TRUSTED_PROXIES` policy that accepts forwarded client IP and scheme only from explicitly trusted proxy addresses. Ensure production can force secure cookies/HSTS and per-IP rate/login controls operate on real clients behind a TLS terminator. Add direct-versus-proxied unit and HTTP tests.

Dependencies: deployment topology decision, proxy CIDRs/IPs, expected forwarded-header convention.

### P1 — Vector-search scale plan and benchmark

Create a representative benchmark/evaluation harness, define corpus and latency/memory ceilings, then replace PHP JSON-vector scanning with binary/datastore-side scoring or a dedicated ANN vector store behind `VectorStoreInterface`.

Dependencies: representative corpus sizes, hosting constraints, chosen MySQL/MariaDB/vector technology.

### P1 — Ingestion-job retention

Add configurable retention for terminal `completed`/`failed` jobs using bounded deletion, advisory locking, a CLI command, cron documentation, and tests that never touch pending/processing work.

Dependencies: audit-retention requirements.

### P1 — Provider cost visibility and ingestion budget

Map model token usage to estimated monetary cost, expose it in the dashboard, allow optional per-key overrides, and decide how administrator-triggered ingestion embeddings participate in a global provider budget. Preserve provider-account hard limits.

Dependencies: model pricing configuration and acceptable approximation policy.

### P2 — RAG evaluation and quality

- Build an evaluation fixture of representative questions, expected sources, and unsupported questions.
- Tune the `0.20` similarity threshold empirically.
- Replace `chars/4` with a model-aware tokenizer or safer estimator.
- Add cross-source/near-duplicate suppression, hybrid search/reranking, and structure-aware table/code/list chunking.
- Improve OCR trigger from “zero text” to a measured text-density policy.

### P2 — Operational and product completeness

- Add an admin password change/reset CLI path.
- Add failed-job notifications and better safe provider diagnostics.
- Add duplicate-enqueue protection at persistence level.
- Add an active-embedding model/dimension health warning and re-embedding UX.
- Decide whether URL refresh should support scheduling.
- Consider optional streaming and conversation storage only with explicit privacy/retention design.

### P3 — Maintainability

- Remove or implement dead Anthropic settings.
- Remove or give clear semantics to `APP_DEBUG`.
- Remove unused provider-reservation columns through a forward migration after compatibility review.
- Increase PHPStan level gradually and add an agreed coverage threshold.
- Add tests for native session expiry/regeneration and remaining middleware integration paths.
- Align legacy `ragserver`, `rag_app`, and `rag_admin_session` identifiers with Ask Asio only where migration/operations impact is acceptable.

## Future Improvements

This repository review found no TODO/FIXME markers, but absence of markers does not mean absence of debt.

| Area | Finding | Risk / trigger | Recommended change |
| --- | --- | --- | --- |
| Proxy security | `Request` trusts only direct server IP/scheme. | TLS termination can collapse per-IP controls and omit secure-cookie/HSTS behavior. | Implement explicit trusted-proxy support first. |
| Retrieval | Every query embeds remotely and PHP scans all compatible vectors. | Cost, memory, and latency grow linearly with active chunks. | Query cache plus ANN/datastore vector search. |
| Job lifecycle | Terminal ingestion jobs have no age retention. | Indefinite operational-table growth. | Safe batched terminal-job purge. |
| Rate limits | Expired rate buckets are pruned on every authenticated API request. | Write amplification at higher request volume. | Probabilistic prune or maintenance command. |
| Rate semantics | Fixed windows allow a boundary burst approaching twice the nominal rate. | Bursty abuse around boundaries. | Sliding/token-bucket only if measurements justify complexity. |
| Login throttling | Username-scoped attempts can lock the only admin remotely. | Denial of service against a known username. | Revisit username/IP combination and backoff policy. |
| API validation | Chat rejects unknown top-level fields; retrieve currently does not. | Inconsistent client contract and unnoticed typos. | Add retrieve allowlist and regression tests. |
| Provider failures | Chat maps provider errors; retrieve can fall through to generic 500 after reconciliation. | Less useful client diagnosis. | Apply the same safe provider-error mapping to retrieve. |
| Provider budgets | All keys share one configured per-key ceiling; ingestion is outside the ledger. | Limited commercial flexibility and incomplete project-wide spend accounting. | Per-key overrides, ingestion/global accounting, cost mapping. |
| Token estimation | Heuristic is approximately characters/4. | Context and quota estimates vary by language/model. | Model-aware tokenizer and safety margins. |
| Similarity | Default threshold is `0.20`. | Weak context can produce confidently cited but marginal answers. | Tune with evaluation data. |
| Content quality | No table/code/list-aware chunking or cross-source dedup. | Reduced retrieval precision and repeated context. | Structure-aware chunking and dedup/rerank. |
| PDF OCR | OCR starts only when native extraction yields zero text. | Hybrid or low-density scanned pages may remain unreadable. | Text-density/page-level OCR policy. |
| Settings | `settings` table exists but is unused. | Confusing dual configuration model. | Remove later or introduce a deliberate typed settings service. |
| Configuration | Anthropic placeholders and `APP_DEBUG` do not represent working behavior. | Operator confusion. | Remove until implemented or document/implement semantics. |
| Naming | Internal `ragserver`/`rag_app` names remain after Ask Asio rebrand. | Minor operational inconsistency. | Change only through a documented compatibility migration. |
| Health | Public health exposes database availability. | Small information disclosure. | Decide whether load balancer needs detail or generic health. |
| MariaDB | MySQL is CI-tested; MariaDB is not. | Claimed compatibility may drift. | Add a MariaDB CI job or narrow the supported matrix. |
| Migration rollback | MySQL DDL auto-commit prevents dependable rollback. | Partial deploy schema on failure. | Preflight, backups, forward-fix discipline, expand migration tests. |

## Nice-to-have features

- Optional dedicated vector database adapter.
- Source-level tags/collections and API retrieval filters.
- RAG evaluation dashboard and feedback capture designed without storing sensitive prompts by default.
- Scheduled URL refresh with conditional requests and change reporting.
- Multiple administrators, MFA, and role-based permissions if product scope expands.
- Multi-tenancy only after a formal ownership/isolation architecture.
- OpenTelemetry or metrics exporter for latency, queue depth, quota, provider errors, and corpus size.

## Recommended next milestone

**Trusted proxy and production request identity hardening** should be next. It is bounded, security-critical, and independent of the larger vector-search redesign.

Definition of done:

1. `TRUSTED_PROXIES` is disabled by default and accepts explicit IP/CIDR entries.
2. Forwarded IP/proto are honored only when `REMOTE_ADDR` is trusted and header chains are validated.
3. `clientIp()`, secure-session cookies, HSTS, login throttling, API rate limiting, audit HMACs, and OpenAI safety identifiers use the resolved values consistently.
4. Direct requests cannot spoof forwarded headers.
5. Unit and real HTTP tests cover direct HTTP/HTTPS, trusted proxy, untrusted proxy, malformed chains, and multiple-hop behavior.
6. Apache/Nginx/load-balancer examples and rollback instructions are documented.
