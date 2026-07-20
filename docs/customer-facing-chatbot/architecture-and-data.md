# Architecture and data

## Existing foundations to reuse

The feature extends these implemented boundaries:

- custom router and middleware with UUIDv7 request IDs, safe errors, JSON parsing, body limits, auth, rate limiting, quota reservation, and API Activity logging;
- server-rendered admin controllers/views protected by session authentication and CSRF;
- service/repository/PDO separation with explicit composition in `bootstrap/app.php`;
- immutable source revisions and retrieval eligibility rules;
- `Retriever`, `ContextSelector`, `PromptBuilder`, `AnswerGenerator`, embedding/chat provider interfaces, and citation validation;
- hash-only `rag_live_` API keys, fixed-window database counters, and global/per-key provider-token quotas;
- migration, pagination, advisory-lock purge, logging/redaction, and test conventions.

The feature must not create a parallel retriever, prompt builder, provider client, rate limiter, error envelope, logger, queue, or admin component system.

## Target component boundaries

| Component | Responsibility |
| --- | --- |
| Chatbot admin controller | Bind HTTP input, call services, render/redirect. |
| Chatbot configuration service | Validate drafts, assignments, publication, public-ID rotation, and lifecycle transitions. |
| Chatbot repository | Bounded projections and transactional persistence. |
| Conversation service/repositories | Create/validate sessions, persist messages/usage, enforce expiry/count/retention. |
| Chat execution service | Shared preview/public orchestration: validate config, retrieve assigned sources, ground/generate/fallback, persist outcome, reconcile quota. |
| Public chatbot controllers | Client-safe configuration, session creation, message submission, deletion/restart if approved. |
| Origin middleware/policy | Normalize and match exact configured origins; add CORS response behavior. |
| Integration credential middleware | Authenticate hash-only secrets and enforce chatbot scopes for server calls. |
| Widget assets | Versioned isolated client consuming only public schemas. |

## Public message flow

```mermaid
sequenceDiagram
    participant V as Visitor/widget
    participant P as Public API
    participant S as Session service
    participant Q as Rate/quota services
    participant X as Chat execution service
    participant R as Existing RAG pipeline
    participant O as OpenAI

    V->>P: Message + session token + request ID/idempotency key
    P->>P: Origin, JSON, size, chatbot publication checks
    P->>S: Validate session belongs to chatbot and is active
    P->>Q: Enforce IP/session/chatbot limits and reserve provider budget
    P->>X: Execute published configuration
    X->>R: Retrieve with assigned source IDs only
    R-->>X: Thresholded chunks
    alt no admitted context
        X-->>P: Configured grounded fallback
    else context available
        X->>O: Existing grounded prompt/chat adapters
        O-->>X: Answer and usage
        X->>X: Validate citations and prepare client-safe sources
    end
    X->>S: Persist bounded messages, usage, status, request ID
    P->>Q: Reconcile provider reservation
    P-->>V: Structured response, no-store, request ID
```

Quota handling must preserve the current conservative rule: ambiguous post-provider failure is charged at the reserved estimate. Persistence failures after a provider response need an explicit failure state and must not trigger a blind provider retry.

## Source scoping invariant

The execution service obtains assigned source IDs from the server-side published configuration. It never accepts `source_ids` from a public client. Those IDs are passed to the existing retriever/vector store, which still enforces enabled, non-deleted, active-ready, embedding-model-compatible chunks.

The invariant must be tested at repository, service, and HTTP levels: a session for chatbot A cannot cause retrieval from sources assigned only to chatbot B.

## Proposed lifecycle

### Chatbot

- `draft`: not publicly resolvable; preview allowed to the administrator.
- `published`: has a validated active public configuration.
- `disabled`: public config/session/message calls fail safely; admin preview remains possible.
- `archived` or soft-deleted: excluded from normal lists and public use; exact behavior requires approval.

### Session

- `active`: may accept messages until idle/absolute expiry and message limit.
- `completed`: visitor restart/close or server completion; no more messages.
- `expired`: server detects expiry; no more messages.
- `blocked`: abuse/policy decision; no more messages.
- `purged`: normally represented by hard deletion rather than a durable status unless audit requirements justify a tombstone.

### Message

- `pending`: accepted and reserved but no final outcome yet.
- `completed`: persisted response/fallback with usage.
- `failed`: safe failure category recorded; partial assistant text is not treated as complete.
- `cancelled`: reserved for explicitly supported cancellation/stream interruption.

## Proposed schema

This schema is intentionally single-tenant. It contains no `tenant_id`, `account_id`, `workspace_id`, `created_by`, or `updated_by` because there is one administrator and no ownership boundary those columns could enforce.

### `chatbots`

| Column | Purpose |
| --- | --- |
| `id` | Internal bigint primary key. |
| `public_id` | Unique high-entropy opaque identifier, rotatable. |
| `name`, `description` | Internal administration fields. |
| `status` | Draft/published/disabled/archive decision. |
| `chat_model` | Nullable/allowlisted override; otherwise installation model. |
| `system_instructions` | Server-only bounded instructions. |
| `fallback_message` | Bounded grounded fallback. |
| `configuration_json` | Validated flexible settings not used for critical filtering. |
| `configuration_version` | Monotonic version for publication/cache invalidation. |
| `published_at`, `created_at`, `updated_at`, `deleted_at` | UTC lifecycle timestamps. |

Frequently filtered, constrained, or security-critical settings should be normalized rather than buried in JSON. Publication state may require separate draft/published snapshots.

### `chatbot_knowledge_sources`

- `chatbot_id` foreign key;
- `source_id` foreign key;
- `created_at`;
- unique `(chatbot_id, source_id)`;
- indexes supporting source dependency checks and chatbot execution lookups.

No duplicated source content or embeddings are stored. Deletion behavior must not silently delete a source when an association is removed.

### `chatbot_sessions`

- internal ID and unique high-entropy public session ID/token hash;
- `chatbot_id`;
- origin, bounded visitor/external reference, validated metadata JSON;
- `is_test`, status, message count, input/output/provider token totals;
- started, last activity, absolute/idle expiry, completed, and deletion timestamps;
- indexes for chatbot/date/status/test pagination and retention batches.

**Decision required:** opaque bearer session token stored as a hash is preferred to exposing a durable identifier alone. Specify token rotation/recovery and browser storage before implementation.

### `chatbot_messages`

- internal ID and `session_id`;
- role, content, status;
- provider/model, latency, input/output/embedding/provider totals;
- bounded retrieval/citation JSON, safe error code, request ID;
- optional unique idempotency key scoped to session;
- `created_at` plus indexes for chronological session reads and retention.

When message-content storage is disabled, retain only the minimum operational record approved by the privacy design; do not store content in a second audit table.

### `chatbot_integration_credentials` (deferred milestone)

- internal ID, name, safe prefix, unique secret hash;
- status, last-used, expiry, created/revoked timestamps.

A relation such as `chatbot_integration_credential_scopes(credential_id, chatbot_id)` is preferred over JSON chatbot IDs for enforceable referential integrity. These credentials are distinct from both the environment provider key and existing general `rag_live_` API keys unless a deliberate extension of `api_keys` is approved.

## Transaction boundaries

- Create/update draft plus source assignments must either fully commit or leave the prior draft unchanged.
- Publish validation plus activation of a configuration version must be atomic.
- Session message-count reservation/idempotency and user-message acceptance must prevent concurrent limit bypass.
- Assistant outcome, usage, citations, and session totals should commit together.
- Retention/manual purge uses advisory locks and bounded transactions.
- Public-ID rotation must atomically invalidate the prior mapping.

## Migration policy

Use ordered forward migrations and never edit an applied migration. Test clean migration and a second idempotent pass against disposable MySQL. Because MySQL DDL may auto-commit, every milestone with schema changes needs backup, deployment order, and forward-repair notes; reversible `down()` methods are not a substitute for that plan.

