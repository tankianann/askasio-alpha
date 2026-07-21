# Security and privacy

## Security boundary

This is a single-tenant installation, so there is no cross-tenant authorization problem. The public feature still introduces untrusted anonymous callers and must enforce these boundaries server-side:

- administrator versus public visitor;
- draft/disabled versus published chatbot;
- one chatbot versus another chatbot's public configuration;
- one session versus every other session;
- assigned versus unassigned knowledge sources;
- browser-origin access versus authenticated server integration;
- one integration credential's chatbot scopes versus other chatbots;
- client-safe presentation data versus server-only configuration/secrets.

Frontend filtering is never an authorization control. Numeric database IDs must not be accepted where an opaque public identifier or authenticated server lookup is required.

## Required authorization invariants

Tests must prove that a caller cannot:

- resolve or use a draft, disabled, archived, or deleted chatbot;
- use a session created for another chatbot;
- read, update, or delete another session by changing path identifiers;
- cause retrieval from an unassigned source through payload metadata or prompt text;
- use an integration credential outside its assigned chatbot scopes;
- assert trusted external-user identity from a browser request;
- access admin preview/diagnostics without the administrator session and CSRF where applicable;
- keep using a rotated public ID, revoked credential, expired session, or newly disallowed origin.

These replace the original brief's cross-tenant tests with controls that match Ask Asio's actual architecture.

## Secret handling

- The OpenAI key remains server-side environment configuration and never appears in HTML, JSON, JavaScript, URLs, logs, exceptions, database chatbot settings, or browser storage.
- Integration and application bearer secrets are shown once and stored as safe prefix plus hash; authorization headers are redacted.
- Public IDs are high-entropy but are not secrets. They are safe identifiers paired with abuse controls and rotation.
- System instructions and safety configuration are server-only, not returned by public config or conversation APIs.
- Logs and stored diagnostic JSON must pass the existing redaction policy and avoid raw provider requests/responses.
- Exceptions must map to stable safe errors without SQL, stack trace, prompt, provider header, or credential material.

Adding database-stored provider connections is deferred until an encryption/key-management threat model is approved. Hash-only storage is not possible for provider keys because the backend must recover and use them.

## Prompt injection and content safety

User messages, approved metadata, prior messages, and retrieved source text are untrusted data. The shared execution path must preserve the current instruction/data separation and:

- tell the model to treat retrieved content as evidence, never instructions;
- refuse requests to reveal prompts, credentials, internal configuration, or alter permissions;
- restrict retrieval by server-derived source assignment;
- use no tools unless a separately authorized tool architecture exists;
- enforce input, history, context, and output bounds outside the prompt;
- validate citation references/range and render content safely;
- apply an approved provider/product safety policy without exposing detailed internal rules.

Citation syntax validation does not prove factual faithfulness. Release evaluation must include malicious documents, prompt-exfiltration attempts, fabricated citations, and unsupported questions.

## Public identifier and session-token design

Chatbot public IDs must be random, non-sequential, unique, and rotatable. Rotation is immediate and auditable. Do not derive them from internal IDs, names, domains, or timestamps alone.

Session authorization uses a separate 256-bit bearer token whose stored representation is a SHA-256 hash plus safe prefix. Token comparison is constant-time after indexed hash lookup. Bind the server record to one chatbot, immutable publication, origin/integration context, idle/absolute expiry, and status. The public session ID is non-secret and never authorizes a request by itself.

URLs can leak through history, logs, and referrers. Prefer session secrets in an authorization header or body/header design rather than a query string. The final design must account for browser CORS and widget isolation.

## Rate limiting, quotas, and abuse

Limits should be layered, bounded, and independently observable:

- source network/IP signal using the application's HMAC handling;
- session;
- chatbot;
- application API key or integration credential;
- installation-wide provider daily/monthly budget;
- per-credential budget where the current quota architecture supports it.

Limits include session creation/messages per window, messages per session, input characters, output ceiling, concurrent/in-progress messages, daily requests, and provider token reservation. Return safe 429 errors with `Retry-After` where meaningful.

The current fixed-window design allows boundary bursts. Add chatbot/session dimensions without using in-memory counters. CAPTCHA/challenge or signed visitor tokens may be added later after measuring abuse. Never silently switch credentials/models to bypass a limit.

Reliable client IP behind a reverse proxy remains an open repository-wide security dependency. Origin checks do not repair collapsed/spoofable IP identity.

## CORS and origin validation

Milestone 8 implements this policy for public configuration and session creation. It resolves only the immutable active publication, returns CORS headers only after exact normalized origin authorization, and keeps public message authorization deferred.

- Require an exact allowlist for browser access; no permissive substring or suffix matching.
- Normalize scheme, ASCII hostname, default/non-default port, case, and trailing dot through one tested policy.
- Wildcard subdomains are initially unsupported. If added, define label-boundary matching explicitly.
- Never use unrestricted `*` for responses involving session credentials.
- Return CORS headers only after policy evaluation; include `Vary: Origin` when content is origin-dependent.
- Admin preview is same-origin and does not loosen public CORS.
- A non-browser server can forge `Origin`; treat CORS as browser policy, not caller authentication.

## Input and rendering safety

- Enforce content type, global body bytes, object shape, exact field allowlists, scalar types, Unicode validity, array counts, string lengths, and metadata schemas.
- Reject arbitrary HTML/CSS/JavaScript in chatbot settings.
- Render visitor and assistant messages as escaped text or through a tightly configured sanitizer for deliberately supported Markdown.
- Sanitize citation URLs and allow only approved `http`/`https` destinations; never emit private file paths or long-lived signed storage links.
- Test stored/reflected XSS in name, welcome text, starters, messages, citations, metadata, and provider output.
- Use prepared SQL and allowlisted sort/filter expressions for all admin searches.

## Data minimization

Conversation storage is separate from API Activity. The existing Activity privacy contract must remain unchanged unless deliberately revised: it does not become a transcript store.

Collect only what the approved behavior needs:

- no raw IP by default; use the existing HMAC-derived signal where sufficient;
- bounded origin and optional integration-supplied external reference;
- allowlisted metadata only;
- message content only when the chatbot's published privacy/storage setting permits it;
- numeric usage and safe error categories;
- bounded citation/retrieval identifiers/excerpts needed for diagnostics.

Do not duplicate full messages, prompts, chunks, or answers across conversation, Activity, and logs.

## Retention and deletion

- Every publication has a retention choice of `0`, `7`, `30`, or `90` days, default `30`; each session copies that policy so later edits do not silently change it.
- Message content is persisted for active multi-turn sessions. Zero-day retention means purge immediately after completion/expiry, not no temporary storage.
- Expired data is deleted in bounded, restartable batches under an advisory lock.
- Manual purge requires authenticated administrator, CSRF, reviewed scope/count, explicit confirmation, and audit logging.
- Public restart completes the old session without erasure. Authenticated public delete and administrator purge hard-delete live sessions/messages; both are distinct from backup expiry.
- Define cascade behavior for chatbot deletion, sessions, messages, associations, integration scopes, and cached public config before migrations.
- Backups have independent retention and may contain data already removed from live tables.
- Installation removal is an operational database/filesystem/backup action, not a tenant-deletion feature.

## Observability privacy

Operational logs may include request ID, internal chatbot/session/credential numeric IDs, provider/model, status, latency, numeric usage, and safe error code. Avoid full message content and origin/query/body dumps by default. Public/admin DTOs must be projection-specific so secret hashes, IP hashes, system prompts, and large diagnostic JSON cannot leak through generic serialization.

## Release-blocking security tests

- IDOR across chatbots and sessions;
- unassigned-source retrieval attempts;
- public-ID entropy/rotation and enumeration response behavior;
- exact CORS and preflight matrix;
- revoked/expired/inappropriately scoped integration credentials;
- session fixation, expiry, replay, cross-origin use, and concurrent message-limit bypass;
- rate/quota rejection before any provider call;
- prompt injection and system-prompt/secret exfiltration attempts;
- oversized/malformed JSON, metadata, and streaming input if streaming exists;
- SQL/filter injection and stored/reflected XSS;
- unsafe Markdown/link/citation rendering;
- redaction and error-boundary leakage.
