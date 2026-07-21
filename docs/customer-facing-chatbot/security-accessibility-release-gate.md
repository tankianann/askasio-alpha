# Security, accessibility, and end-to-end release gate

Milestone 14 establishes a repeatable release gate and records the July 2026 rehearsal. The implementation gate is complete, but the release decision is **conditional—not approved for unrestricted internet production** until the environment-owned checks under [Outstanding release conditions](#outstanding-release-conditions) are completed.

## Gate result

| Area | Result | Evidence |
| --- | --- | --- |
| Composer metadata and PHP/JavaScript syntax | Pass | Strict Composer validation, all PHP lint, and `node --check` for the versioned widget. |
| Dependency advisories | Pass | Live `composer audit` returned no known advisories. |
| Static analysis | Pass | PHPStan completed with no errors. |
| Unit, HTTP, security, and real-MySQL suite | Pass | Complete PHPUnit suite with no skipped database tests against disposable MySQL. |
| Exact-origin/CORS matrix | Pass | Allowed canonical origin, denied missing/opaque/HTTP/subdomain/suffix/port/multiple/credential/path origins, same-origin exception, and allowed/denied preflights. |
| Abuse/failure exercises | Pass | IP/chatbot/session/integration limits, quota rejection, strict bodies, idempotency, stale pending recovery, session expiry/scope, provider failures, and safe errors. |
| Representative RAG evaluation | Pass with accepted scope | Real MySQL vector projection plus fake embedding/chat adapters; no paid provider call. |
| Widget code-level accessibility/security | Pass | Shadow DOM, safe DOM sinks, labels/groups/log/dialog/status, focus hooks, keyboard behavior, `aria-busy`, responsive dynamic viewport, reduced motion, forced colors, and session-only storage contracts. |
| Real browser desktop/mobile/accessibility matrix | Not run | No browser backend was available in the execution environment. This remains a release condition. |
| Database logical restore rehearsal | Pass | Complete schema, migration ledger, and data marker copied to and verified from a second isolated database. |
| Target-host database/files/environment restore | Not run | MySQL backup client and production-equivalent private filesystem were unavailable. This remains a deployment release condition. |
| Release artifact rehearsal | Pass | Excluded `.git`, `.env`, Composer vendor tree, logs, and cache; required code, lockfile, widget, migrations, and release evaluations were verified after extraction. |

## Security findings resolved

### API Activity route identifiers

API Activity previously received the literal request path. For dynamic public routes that meant a chatbot public ID and non-secret but privacy-relevant session ID could be retained in the endpoint field.

The router now attaches the matched route name/template after matching. API Activity stores the template, such as:

```text
/api/public/v1/chatbots/{chatbotPublicId}/sessions/{sessionId}/messages
```

Query strings and dynamic resource identifiers are excluded. Direct middleware use without a matched route safely falls back to the path. Regression coverage verifies both behaviors.

### Chatbot credential log redaction

Defense-in-depth log redaction now recognizes `chatint_live_…` integration credentials and `cst_v1_…` session bearers even when they appear outside a standard `Authorization: Bearer` string. Logging secrets remains prohibited; this is a final containment layer, not permission to include credentials in logs.

### Widget accessibility hardening

The widget conversation log now has an accessible name, suggested questions form a named group, each message is labelled by speaker, pending operations set dialog `aria-busy`, and mobile sizing uses dynamic viewport units with a `vh` fallback. Existing dialog, live-region, focus, Escape, Enter/Shift+Enter, reduced-motion, forced-color, and visible-focus behavior remains intact.

## Origin and CORS matrix

Browser authorization continues to use the immutable active-publication origin list.

| Request | Expected result |
| --- | --- |
| Exact HTTPS origin, including canonical default port normalization | Allowed; exact request origin reflected, `Vary: Origin`, no credentials header. |
| Verified direct same-origin request with `Sec-Fetch-Site: same-origin` | Allowed without CORS response headers. |
| Missing origin without verified same-origin signal | `403 origin_not_allowed`. |
| `null`, HTTP remote, subdomain, suffix-confusion host, wrong port | `403`; no allow-origin header. |
| Multiple origins, embedded credentials, or non-origin path | `403`; no allow-origin header. |
| Valid session preflight with `Content-Type` | `204`, bounded methods/headers, empty body. |
| Valid message preflight with `Authorization, Content-Type` | `204`, exact allowlist. |
| Unsupported method or header | `403 cors_preflight_rejected`. |
| Safe application error after origin authorization | Error remains inside the approved CORS boundary with request ID. |

No wildcard origins or credentialed browser CORS are supported. Forwarded scheme/host headers remain untrusted.

## Abuse and failure exercises

The release suite verifies:

- fixed-window counters independently enforce IP, chatbot, browser session, and integration credential scopes;
- a chatbot-wide counter aggregates different IPs and an IP counter aggregates different integration credentials;
- bodies reject wrong media type, malformed/list JSON, oversized data, unknown fields, control characters, and invalid idempotency keys;
- hash-only session authentication requires matching chatbot/session/path/origin/channel/classification;
- idempotent replay never repeats provider generation, changed content conflicts, live work returns in-progress, and stale work is failed without blind regeneration;
- copied message/session limits, expiry, completion, and retention remain atomic;
- disabled/unpublished/rotated/stale resources fail with safe concealment;
- no-evidence fallback incurs only query embedding and makes no chat-generation call;
- provider timeout/rate/auth/configuration/malformed/generic errors do not expose provider details;
- ambiguous provider failure is conservatively charged and persisted as a safe failed outcome;
- quota exhaustion prevents provider access; and
- Activity/log projections exclude bodies, answers, citations, raw IP, credentials, dynamic route identifiers, and arbitrary usage strings.

## Representative RAG evaluation

`ChatbotRagReleaseEvaluationTest` creates three ready sources with orthogonal embeddings in real MySQL and runs the production retriever, cosine scoring, context selection, prompt builder, and answer/citation validator. Embedding and generation adapters are deterministic fakes, so the evaluation never spends provider tokens.

| Scenario | Required result |
| --- | --- |
| Refund-window question | Select only the assigned refund source and require `[S1]`. |
| Delivery-time question | Select only the assigned shipping source and require `[S1]`. |
| Unsupported medical question | Return the configured fallback with no chat call. |
| Exact semantic match in an unassigned internal source | Exclude it and fall back with no chat call. |
| Source contains “ignore previous instructions” text | Keep it in JSON source data, never in code-managed instructions. |

This evaluates retrieval/scoping/prompt/citation contracts, not the semantic quality of a live model. A curated staging evaluation against the configured provider remains an operator check after setting the provider project hard budget.

## Widget browser and accessibility matrix

The code-level contracts pass, but a release operator must run the real versioned asset without paid provider calls in current Chrome, Edge, Firefox, and Safari. Use a published test chatbot whose configured fallback answers the test message without generation.

For desktop and a narrow mobile viewport (including an on-screen keyboard):

1. Load the async asset on an allowed origin and confirm no host CSS/JavaScript leakage.
2. Open with pointer and keyboard; verify focus enters the input and launcher `aria-expanded` changes.
3. Confirm the labelled non-modal dialog, conversation log, status updates, speaker-labelled messages, suggestions group, disclosure, privacy link, and control names in the accessibility tree.
4. Verify Enter submits, Shift+Enter inserts a line, Escape closes, close restores launcher focus, and no focus trap prevents reaching host content.
5. Exercise restart, same-tab reload, new-tab isolation, unavailable storage fallback, expired session, rate limit, message limit, network/config failure, and retry/idempotency behavior.
6. Check 200% zoom, 320 CSS-pixel width, dynamic viewport/keyboard fit, dark theme, forced colors, reduced motion, long unbroken text, long translated labels, and citations.
7. Run the browser accessibility scanner and manually check keyboard order, accessible names, contrast, live announcements, and touch targets.

Record browser versions, screenshots, scanner output, failures, and request IDs in the release ticket. Any critical/serious automated issue or keyboard/screen-reader blocker fails the release.

## Restore and release rehearsal

The automated logical restore test:

1. migrates a disposable source database;
2. writes a known marker;
3. recreates every table and row in a separately named disposable database with foreign-key handling;
4. opens a new connection to the restored database;
5. verifies table count, complete migration ledger, and marker data; and
6. drops the restored database in `finally`.

The artifact rehearsal builds an archive from the working tree while excluding `.git`, `.env`, `vendor/`, logs, and cache. It extracts the archive and verifies `composer.lock`, front controller, versioned widget assets, latest migration, and release evaluation before rechecking PHP/JavaScript syntax and lockfile integrity.

This does not replace the target-host procedure. Before production, rehearse the actual database backup tool plus coordinated `FILESYSTEM_PATH` and secured environment restoration, then execute the smoke matrix in [Analytics and operational readiness](analytics-and-operational-readiness.md#release-procedure).

Application rollback keeps additive migrations in place and rolls code/widget assets back together. Destructive `down()` migrations are not the rollback strategy; schema problems use a reviewed forward fix or the verified pre-release backup.

## Accepted limitations

The following are accepted only within the stated boundary:

- single administrator and single tenant; no roles, MFA, or tenant isolation model;
- non-streaming responses;
- heuristic token estimation;
- PHP full-corpus vector scoring, requiring benchmark/replacement as the active corpus approaches the documented trigger;
- fixed-window rate limits permit boundary bursts;
- deterministic fake-provider RAG evaluation does not score live-model faithfulness;
- no metrics exporter, widget telemetry, monetary cost model, or automatic provider-account budget verification;
- ingestion embedding usage remains outside the application token ledger, making the provider-project hard budget mandatory;
- current evergreen browsers only; Internet Explorer is unsupported; and
- direct HTTPS/original client identity is the supported deployment topology.

These are not accepted as silent risks: they must remain visible in deployment and roadmap documentation.

## Outstanding release conditions

Production approval is withheld until the release owner records all of the following:

- successful real Chrome/Edge/Firefox/Safari desktop/mobile/accessibility matrix;
- successful production-equivalent database + `FILESYSTEM_PATH` + environment backup/restore drill;
- configured provider model envelope, rate limits, monetary hard budget, and alerts;
- representative staging RAG review with the selected provider and approved non-sensitive fixtures;
- direct HTTPS with real client IP in `REMOTE_ADDR`, or completed allowlisted trusted-proxy support; and
- green CI for supported PHP versions plus the exact release commit/artifact checksum.

A conventional TLS-terminating proxy without allowlisted trusted-proxy handling remains unsupported. The absence of a browser backend or production backup client is a reason to defer approval, not to mark those checks as passed.
