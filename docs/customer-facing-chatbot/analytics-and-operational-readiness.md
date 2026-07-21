# Analytics and operational readiness

Milestone 13 adds an administrator-only, content-free analytics view and closes the chatbot-specific operating documentation. It does not add a metrics exporter, monetary-cost estimator, provider-account integration, or trusted-proxy implementation.

## Bounded analytics contract

`GET /admin/conversations/analytics` requires the normal administrator session. The reporting window:

- defaults to the most recent 30 inclusive calendar days in `APP_TIMEZONE`;
- accepts between 1 and 90 inclusive days;
- defaults to production traffic and can select test or combined traffic;
- can select one chatbot; and
- attributes a session to the window and UTC daily bucket containing its `last_activity_at` value.

The view reports session count, active sessions, user-turn count, failed assistant outcomes, AI usage, production/test mix, up to 90 UTC daily rows, and up to 100 chatbot rows. “AI usage” is the administrator-facing name for the stored embedding/input/output provider-token totals; it is distinct from API-key and session-token credentials. Installations with more chatbots must select one chatbot rather than request an unbounded grouping.

Queries read session/message status and numeric aggregates only. They do not select transcript content, retrieval JSON, citations, tokens, origins, browser identifiers, integration secrets, or external-user references. No estimated monetary cost is shown because versioned model pricing is not implemented. The response uses `Cache-Control: no-store`, escapes rendered values, displays the current request ID, and directs the operator to correlate that ID with safe logs.

Migration `20260721000020_add_chatbot_analytics_index.php` adds `(last_activity_at, is_test, chatbot_id, id)` for bounded activity-window scans. The migration is additive and stores no new data.

## Chatbot configuration inventory

`.env.example` and typed `config/*.php` remain authoritative. This is the complete chatbot-facing subset; the full installation inventory is in [Deployment](../deployment.md#environment-variables).

### Installation, provider, and grounding

| Variables | Purpose and enforced behavior |
| --- | --- |
| `APP_ENV`, `APP_URL`, `APP_SECRET`, `APP_TIMEZONE` | Environment, canonical URL, HMAC secret, and administrator reporting timezone. `APP_SECRET` must contain at least 32 characters. |
| `SESSION_SECURE_COOKIE` | Use `always` in production. `auto` sees only the direct connection scheme. |
| `LLM_PROVIDER`, `EMBEDDING_PROVIDER` | Both support only `openai`. Chatbot publications copy the effective installation provider/model metadata. |
| `OPENAI_API_KEY`, `OPENAI_BASE_URL` | Installation credential and HTTPS provider endpoint; chatbot records never store the secret. |
| `OPENAI_CHAT_MODEL`, `OPENAI_CHAT_REASONING_EFFORT` | Installation-wide chat model and optional allowlisted reasoning effort. Existing publications become configuration-stale after a model change until reviewed and republished. |
| `OPENAI_CHAT_MAX_OUTPUT_TOKENS` | Default `600`; application hard range `64..32768`. The selected model must also support the configured value. |
| `OPENAI_CHAT_MAXIMUM_RETRIES` | Default `0`; hard range `0..10`. Zero avoids automatic regeneration after ambiguous billable failures. |
| `OPENAI_CONNECT_TIMEOUT_SECONDS`, `OPENAI_REQUEST_TIMEOUT_SECONDS` | Positive provider connection/request timeouts; defaults `10`/`60`. |
| `OPENAI_EMBEDDING_MODEL`, `OPENAI_EMBEDDING_DIMENSIONS` | Installation embedding compatibility. A change requires re-embedding and chatbot publication review. |
| `OPENAI_MAXIMUM_RETRIES` | Embedding-client retries; default `3`, hard range `0..10`. |
| `RAG_CHAT_TOP_K`, `RAG_CHAT_MAXIMUM_TOP_K` | Default/max admitted retrieval count; max must be at least the default and no greater than `100`. Published chatbot top-K remains bounded by this installation maximum. |
| `RAG_RETRIEVAL_MINIMUM_SIMILARITY` | Installation default; runtime hard range `-1..1`, while chatbot drafts use the stricter approved `0..1` range. |
| `RAG_CHAT_CONTEXT_MAX_TOKENS` | Estimated source-context budget; minimum `256`. |
| `RAG_CHAT_HISTORY_MAX_TOKENS` | Estimated completed-turn history budget; hard range `0..8000`. |
| `RAG_CHAT_MAXIMUM_QUESTION_CHARACTERS` | Server ceiling over the copied per-publication message-character limit. |
| `API_MAXIMUM_BODY_BYTES` | Strict JSON body ceiling, default `65536`. |

### Public traffic, retention, and quotas

| Variables | Purpose and enforced behavior |
| --- | --- |
| `CHATBOT_PUBLIC_RATE_LIMIT_WINDOW_SECONDS` | Shared fixed window; hard range `1..3600`. |
| `CHATBOT_PUBLIC_CONFIG_RATE_LIMIT_PER_IP`, `CHATBOT_PUBLIC_CONFIG_RATE_LIMIT_PER_CHATBOT` | Configuration endpoint counters. Values must be positive. |
| `CHATBOT_PUBLIC_SESSION_RATE_LIMIT_PER_IP`, `CHATBOT_PUBLIC_SESSION_RATE_LIMIT_PER_CHATBOT` | Session-creation counters. Values must be positive. |
| `CHATBOT_PUBLIC_MESSAGE_RATE_LIMIT_PER_IP`, `CHATBOT_PUBLIC_MESSAGE_RATE_LIMIT_PER_CHATBOT`, `CHATBOT_PUBLIC_MESSAGE_RATE_LIMIT_PER_SESSION` | Message endpoint counters. Values must be positive. Fixed windows permit boundary bursts; these are abuse controls, not billing quotas. |
| `CHATBOT_INTEGRATION_RATE_LIMIT_PER_CREDENTIAL`, `CHATBOT_INTEGRATION_RATE_LIMIT_PER_IP` | Server-integration counters using the same public window. Values must be positive. |
| `CHATBOT_PUBLIC_PENDING_TIMEOUT_SECONDS` | Stale pending-message recovery, default `120`, hard range `30..3600`. |
| `CHATBOT_CONVERSATION_PURGE_BATCH_SIZE` | Scheduled/manual expiry and purge batch, default `500`, hard range `1..10000`. |
| `PROVIDER_GLOBAL_DAILY_TOKEN_LIMIT`, `PROVIDER_GLOBAL_MONTHLY_TOKEN_LIMIT` | Installation-wide application token gates used by chatbot execution. Zero means unlimited; production must use finite values. |
| `PROVIDER_QUOTA_RESERVATION_TTL_SECONDS` | Crash/ambiguous-call reservation expiry, default `900`, hard range `60..3600`. |

The per-API-key quota variables continue to govern existing `/api/v1/chat` and `/api/v1/retrieve`; chatbot execution uses installation-global buckets and does not create an API-key bucket. Administrator-triggered ingestion embeddings remain outside this ledger.

Chatbot names, assigned sources/origins, message/session/expiry limits, `0|7|30|90` retention, instructions, fallback, citations, and appearance are validated database-backed draft/publication settings—not environment variables.

## Provider hard-limit validation

There are two independent control layers:

1. The application rejects unsupported provider names, non-HTTPS base URLs, blank models/credentials, invalid reasoning effort, output values outside `64..32768`, retry values outside `0..10`, invalid context/history/top-K values, and invalid quota/rate/timeout values before normal execution.
2. The provider project owns model-specific context/output/rate limits and the actual monetary hard budget. Ask Asio cannot inspect or prove those external controls. Before production, the operator must verify that the configured model supports the combined instruction, history, context, and output envelope; set a provider-project hard spend ceiling and alerts; and make application daily/monthly quotas lower than the acceptable provider exposure.

Application quotas are not a provider billing guarantee. A timed-out request may already be billable, so ambiguous failures are charged conservatively. Ingestion embedding calls are bounded by document/batch limits but are not included in the application token ledger; the provider-project ceiling is therefore mandatory for whole-installation spend control.

## Trusted-proxy deployment gate

`Request::fromGlobals()` obtains client IP only from `REMOTE_ADDR` and secure scheme only from direct `HTTPS`/port state. It intentionally ignores `Forwarded` and every `X-Forwarded-*` header.

This gives two supported conclusions:

- A direct HTTPS connection to the application, with the real visitor address in `REMOTE_ADDR`, is supported.
- A proxy may be used only when the reviewed web-server/network path supplies the real client IP and HTTPS state directly to PHP without trusting caller-controlled headers.

A conventional TLS-terminating reverse proxy is otherwise a release blocker until the roadmap's allowlisted trusted-proxy policy is implemented. Without it, many visitors may share the proxy IP bucket, login/public rate controls become inaccurate, same-origin reconstruction can be wrong, and `auto` secure-cookie/HSTS behavior can be incorrect. Never fix this by globally trusting forwarded headers. `SESSION_SECURE_COOKIE=always` protects the administrator cookie but does not repair client identity, public rate limits, same-origin reconstruction, or safety identifiers.

## Release procedure

1. Record the release commit and current `schema_migrations` rows. Confirm the selected topology passes the trusted-proxy gate.
2. Verify the complete environment inventory, finite global token quotas, provider-project hard budget/alerts, supported model limits, exact chatbot origins, `SESSION_SECURE_COOKIE=always`, and `public/` document root.
3. Stop the ingestion worker and prevent administrator mutations. Take a coordinated database, private source filesystem, and securely stored environment backup.
4. Run the full quality gate against the release artifact. Deploy code and committed widget assets; there is no asset compilation.
5. Run `php bin/migrate.php` once. Confirm migration `20260721000020_add_chatbot_analytics_index.php` is in the ledger and the index exists.
6. If embedding model/dimensions changed, rebuild embeddings, wait for readiness, review affected chatbot publications, and republish deliberately.
7. Restart the worker and reload PHP-FPM so code and environment are consistent.
8. Smoke-test health, administrator login, chatbot analytics, a draft preview, public configuration/session/message from an allowed origin, a denied origin, and a scoped integration request. Use test classification where available and confirm request IDs in logs.
9. Run `php bin/prune-chatbot-conversations.php` once, verify its safe summary, then re-enable normal traffic and mutations.

## Monitoring inventory

No metrics exporter, percentile calculator, or widget telemetry endpoint exists. Monitor only what is implemented and label derived database/log queries as operator-owned:

| Signal | Implemented source | Initial action condition |
| --- | --- | --- |
| HTTP/database availability | `/api/v1/health` | Non-200 or degraded database. |
| Public/API failures and request IDs | API Activity and Monolog | Sustained 5xx; unexpected 401/403; 429 increase by endpoint. |
| Chatbot sessions/messages/tokens | `/admin/conversations/analytics` | Unexpected traffic/token change; failed assistant outcomes above baseline. |
| Detailed safe conversation state | bounded conversation list/detail | Stale pending outcomes, repeated safe failure categories, expiry/purge anomalies. |
| Provider quota | provider quota buckets/dashboard surfaces | Daily/monthly remaining below the operator threshold; expired reservations repeatedly reconciled. |
| Provider account spend/rate | provider project dashboard/alerts | Hard budget or rate-limit alerts. This is external and mandatory. |
| Ingestion/source readiness | Processing, source details, worker journal | Old pending/processing jobs, repeated failures, model-incompatible active versions. |
| Retention | cron exit/stderr plus Monolog completion record | Missed/non-zero run, repeated lock skip, purge backlog growth. |
| Infrastructure | MySQL, PHP-FPM/web, systemd, disk and backup monitoring | Restarts, memory kills, slow queries, connection/disk pressure, failed/stale backup. |

Latency percentiles, fallback rate, retrieval-failure rate, widget initialization errors, monetary cost, and configuration-stale publication counts are desired future metrics; they are not currently emitted as complete dashboards.

## Troubleshooting by request ID

| Symptom | Checks and safe action |
| --- | --- |
| Widget/config returns 403 | Correlate request ID; compare the browser `Origin` to the exact active-publication origin; check host CSP/connect-src; republish only after reviewing the draft. |
| Chatbot appears missing | Check status, active publication, public-ID rotation, and publication/model staleness. Public APIs intentionally conceal unavailable resources as 404. |
| Session/token failure | Check per-tab `sessionStorage`, restart the widget session, then inspect expiry/status by public session ID. Never log or recover the bearer token. |
| Repeated 429 across unrelated visitors | Compare direct `REMOTE_ADDR` topology. A shared proxy address is a deployment blocker, not a reason to weaken limits. |
| Safe fallback | Check assigned enabled sources, ready active versions, embedding compatibility, threshold, top-K, and context budget. No-evidence fallback correctly makes no chat call. |
| Provider auth/rate/timeout/configuration failure | Use request ID and safe category; verify secret/model/base URL/timeouts and provider project state. Do not expose raw provider bodies or blindly retry ambiguous calls. |
| Analytics empty/unexpected day | Confirm `APP_TIMEZONE`, inclusive input dates, production/test filter, chatbot filter, and `last_activity_at` attribution. Daily rows are UTC. |
| Retention did not run | Check cron, process exit, Monolog, advisory-lock skip, database access, copied retention, terminal status, and `purge_eligible_at`. Do not delete broad tables manually. |
| Migration stopped | Compare migration ledger and `information_schema`; preserve evidence and deploy a reviewed forward-fix migration. Do not edit the applied file or mark it applied blindly. |

## Backup and restore

A recoverable chatbot installation needs one coordinated point-in-time set: MySQL, `FILESYSTEM_PATH`, and the matching environment/secrets stored separately. The database contains chatbot drafts/publications, assignments/origins, sessions/messages, hash-only session/integration credentials, limits, usage, rate/quota state, and the migration ledger. Widget assets are in the release artifact; source originals are not.

Stop the worker and prevent writes for a strict snapshot, encrypt backups, restrict access, test restoration to isolated paths, and apply an explicit backup retention policy. Live conversation purge cannot remove transcript content from an older backup; privacy/deletion commitments must disclose and enforce backup expiry.

After restore:

1. verify file ownership, `.env`, code release, database timezone, and migration ledger;
2. run migrations only after comparing the restored schema to the restored ledger;
3. verify source file/version consistency and rebuild embeddings only if missing or incompatible;
4. verify health, administrator auth, source processing, analytics, preview, allowed/denied public origin behavior, session/message execution, retention, and one scoped integration;
5. remember that one-time plaintext API, integration, and session secrets cannot be derived from restored hashes—replace/revoke credentials when clients no longer hold their originals; and
6. rotate provider/database/application credentials if the restore follows suspected disclosure.

## Migration rollback and forward repair

Migration `20260721000020` only adds a secondary index. A code rollback may safely leave it in place. Do not run `down()` automatically in production: MySQL DDL implicitly commits and dropping the index can make analytics/activity queries expensive while older code remains compatible with the extra index.

If the migration fails, inspect both `schema_migrations` and `information_schema.statistics`:

- neither ledger row nor complete index: correct the cause and rerun after review;
- complete index but missing ledger row, or a partial/mismatched index: stop deployment and add a reviewed forward-repair migration or restore the pre-migration snapshot;
- ledger row but missing/mismatched index: treat this as schema drift and repair with a new migration.

Never edit an applied migration, manually claim success without comparing the exact index definition, or use a broad destructive rollback. Public API/widget compatibility should be restored by rolling application code/assets back together while leaving additive schema in place; incompatible schema changes require expand/contract releases and forward fixes.

## Remaining release gates

Milestone 14's implementation gate is complete and its conditional decision is recorded in [Security, accessibility, and end-to-end release gate](security-accessibility-release-gate.md). Real-browser/accessibility, production-equivalent restore, provider-project/model, and deployment-topology checks remain environment-owned conditions before production approval. Trusted-proxy support must precede a conventional TLS-terminating proxy deployment.
