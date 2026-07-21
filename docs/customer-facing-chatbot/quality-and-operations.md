# Quality and operations

## Grounding and answer behavior

The chatbot must use the existing retrieval pipeline with server-derived assigned source IDs. The secure default is grounded-only:

1. Embed the complete current question once.
2. Search only compatible chunks from assigned, enabled, non-deleted sources with ready active revisions.
3. Apply the configured/approved minimum similarity and top-K bounds.
4. Admit ranked chunks within the context token budget.
5. If no context qualifies, return the configured bounded fallback and do not call chat generation.
6. Otherwise build instructions separately from JSON-encoded untrusted question/history/context.
7. Generate with the configured provider/model and `store=false` behavior.
8. Validate `[S#]` citations and produce client-safe citation projections.

The feature may make fallback wording configurable, but it must not weaken the no-evidence short circuit. General-model answers outside retrieved evidence are deferred.

## Conversation context

The initial context policy must be deterministic and bounded. Preserve recent completed user/assistant turns up to an explicit history budget; never include another session, failed/partial assistant output as authoritative history, internal diagnostics, or unapproved metadata.

Message content is persisted while the session is active. Each session copies the publication's `0`, `7`, `30`, or `90` day retention choice (default `30`) so later edits do not silently alter its privacy contract.

The implemented v1 policy is `recent_completed_turns_v1`: select newest complete user/assistant pairs within `RAG_CHAT_HISTORY_MAX_TOKENS` (default `1000`), return them chronologically, and omit the oldest complete pairs when over budget. Failed/partial outcomes are excluded. Summarization is deferred because it adds another provider call, ungrounded content, cost, latency, persistence, and failure modes.

Record the policy/version used for an answer when needed for reproducibility. The existing heuristic token estimator remains a known limitation; quota/context safety margins must compensate until model-aware tokenization is introduced.

## Citations

Public citations include only reference, display title/label, approved heading/page, and approved public URL. They must not expose internal source/chunk/version IDs, private uploads, filesystem paths, object keys, or unrelated metadata.

Admin preview/details may show internal IDs, similarity, and bounded excerpts for diagnosis. Citation range validation is necessary but insufficient; evaluation must check that claims are actually supported by the cited excerpt.

## Reliability and failure behavior

The implementation must define and test:

- provider timeout, authentication, configuration, rate-limit, invalid-response, and generic failures;
- provider quota exhaustion before the call;
- retrieval/database unavailability and no ready assigned source;
- session expiry, limit exhaustion, revocation/disable, and origin-policy change;
- concurrent messages within one session;
- duplicate client retry/idempotency;
- persistence failure before and after provider completion;
- widget configuration/load/network failure;
- interrupted streaming only if streaming is later added.

Retries must be bounded and limited to operations safe to repeat. The current zero automatic chat retry protects against ambiguous duplicate billing and should remain the default. A provider timeout may already have incurred cost; reconcile conservatively and let explicit idempotency prevent blind regeneration.

User-facing failures are stable and safe; operational logs retain the request ID and safe category. Do not fall back to a different source scope, credential, chatbot, model, or general-knowledge answer silently.

## Idempotency and concurrency

A message submission should accept a bounded high-entropy idempotency key unique within a session. The same key and payload returns the recorded outcome; the same key with different input is rejected; an in-progress duplicate does not start another provider call.

Message-count enforcement, pending-message creation, and idempotency reservation require a database transaction/lock or equivalent atomic constraint. Define how stale pending records recover after process death. Public ID rotation, publishing, and session expiry must also behave deterministically under concurrent requests.

## Caching

There is currently no application response cache. The first release must not cache answers across visitors or sessions.

Safe future candidates are client-safe published configuration and non-sensitive readiness/model metadata. Any configuration cache key must include public ID, published configuration version, and origin policy. Invalidate on publication, disable, public-ID rotation, source-assignment/readiness changes that affect publication behavior, and deletion.

Future answer caching would need chatbot configuration version, active source versions, embedding/chat model, instructions, retrieval settings, history, language, permissions, and approved metadata. Its complexity and privacy risk make it explicitly deferred.

## Usage and analytics

Reuse the existing request ID, API Activity, numeric usage, rate-limit, and provider-quota architecture where compatible. Track bounded fields:

- chatbot and session internal IDs;
- provider/model;
- production/test classification and origin category;
- request time, status, latency, error code;
- embedding/input/output/provider tokens;
- retrieval/admitted citation counts;
- fallback/no-evidence outcome;
- integration credential ID where applicable.

Do not store tenant ID because no tenant record exists. Estimated monetary cost is shown only after reliable versioned model pricing exists. Analytics screens require date range, filters, pagination, bounded summaries, and retention; no unbounded chart queries or duplicate content payloads.

Milestone 13 implements administrator-only session/chatbot summaries over a 1–90 day `last_activity_at` window, with production/test and chatbot filters, at most 90 UTC daily buckets, and at most 100 grouped chatbot rows. The query reads numeric/status fields rather than transcript or retrieval content, returns `Cache-Control: no-store`, and displays the request ID. See [Analytics and operational readiness](analytics-and-operational-readiness.md).

## Logs and metrics

Structured logs should support request/chatbot/session correlation without content leakage. Useful operational measures are sessions/messages per chatbot, success and fallback rates, provider/retrieval failures, p50/p95 latency, token usage, rate/quota events, expired sessions, and widget initialization errors.

The current application has no metrics exporter. Initial observability may use Monolog, API Activity/conversation tables, provider quota buckets, dashboard summaries, health, and database/system monitoring. Deployment docs must distinguish implemented metrics from desired future metrics.

The implemented milestone 13 analytics view supplies session count, active status count, user turns, failed assistant outcomes, provider tokens, traffic mix, and bounded daily/chatbot groupings. It does not supply latency percentiles, fallback rates, monetary cost, provider-project state, or widget telemetry.

The initial provider/model is installation-wide and read-only per ADR-031. Publication stores the effective provider/chat/embedding metadata; monitoring must surface configuration-stale publications after an environment model change so the administrator can review and republish them.

## Test strategy

Automated tests must never make real paid provider calls.

### Unit

- chatbot configuration/publication validation and source assignment;
- public/session ID generation and token hashing;
- origin parsing/normalization/matching and CORS headers;
- session expiry, retention, message limits, metadata schema;
- idempotency/concurrency decisions;
- retrieval source filters, relevance/fallback/history behavior;
- DTO serialization and public-field allowlists;
- secret masking/redaction and safe citation projection;
- integration credential hashing/scope checks;
- widget state/reducer/render sanitization where practical.

### Integration with real MySQL

- clean/idempotent migrations, foreign keys, unique constraints, indexes, and deletion rules;
- create/edit/publish/disable/rotate/delete transactions;
- source-assignment and dependency queries;
- session/message atomic limits and idempotency races;
- retention/manual purge scopes and advisory locks;
- integration credential authentication/scopes;
- rate/quota reservation with no provider call on rejection;
- bounded conversation pagination/projections.

### HTTP/application

- administrator auth/CSRF on every mutation and preview;
- public config/session/message happy path through real router/middleware/bootstrap;
- strict JSON/body validation and error envelope;
- CORS/preflight allowed and denied cases;
- draft/disabled/rotated/expired/revoked behavior;
- safe provider/retrieval/database failure mapping;
- privacy projections and request IDs.

### Widget/end-to-end

1. Administrator creates, assigns sources, previews, and publishes a chatbot.
2. Allowed site loads the isolated accessible widget.
3. Visitor starts a session and receives a cited grounded answer.
4. Unsupported question returns fallback without a generation call.
5. Reload/restart behavior matches documented browser storage.
6. Disallowed origin and disabled chatbot fail safely.
7. Administrator finds production conversation and usage; test traffic remains distinct.
8. Purge removes the reviewed live data without sweeping newer records.

Test responsive layouts, host CSS/JS interference, keyboard/screen reader behavior, reduced motion, content/XSS rendering, and the documented browser matrix.

## Environment and deployment

Do not add environment variables until their implementation milestone. Expected categories include public API/widget base URL, session expiry/limits, public/chatbot rate limits, retention/purge batch size, and optionally widget asset/cache settings. `.env.example` and typed `config/*.php` become authoritative when added.

Deployment must account for:

- `public/` remaining the only web root and serving the versioned widget asset;
- explicit production HTTPS and correct trusted-proxy identity before relying on IP controls;
- CORS/origin configuration and CSP/resource headers for cross-site widget loading;
- forward migrations, backups, worker/maintenance scheduling if retention uses CLI;
- PHP-FPM/Apache timeouts and buffering for non-streaming now and streaming later;
- quota/provider hard budget and alerting;
- rollback/forward-fix behavior for public routes, widget versions, and schema.

## Operational troubleshooting topics

Before release, document how to diagnose by request ID:

- widget/config blocked by origin or CSP;
- session expired or token missing after browser storage changes;
- chatbot unpublished/disabled or public ID rotated;
- no assigned ready/model-compatible sources;
- fallback caused by threshold/context selection;
- quota versus fixed-window rate rejection;
- provider auth/rate/timeout failure;
- conversation persistence/retention job failure;
- stale widget/config caching;
- proxy identity collapsing many visitors into one IP signal.

## Definition of done

- Living docs match actual architecture, schema, API, environment, deployment, and behavior.
- All migrations have clean/idempotent tests plus backup and forward-repair notes.
- Public APIs are versioned, strictly validated, rate/quota limited, and use safe stable errors.
- Provider keys/system prompts never reach clients or unsafe storage/logs.
- Chatbot/session/source/credential scoping and CORS tests pass.
- Preview and public chat use the same execution service and assigned-source invariant.
- No-evidence fallback makes no chat-generation call.
- Conversation limits, idempotency, retention, and purge work under concurrency/failure.
- Widget is isolated, responsive, accessible, safely renders content, and has a compatibility contract.
- Growing lists and maintenance operations are bounded.
- Unit, static analysis, real-DB, HTTP, security, widget, and end-to-end gates pass without paid calls.
- Release, monitoring, troubleshooting, rollback/forward-fix, limitations, and deferred work are documented.
