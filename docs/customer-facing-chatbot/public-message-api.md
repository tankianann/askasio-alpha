# Public message API

## Implemented scope

Milestone 9 adds authenticated non-streaming production messages and the explicit session-completion primitive used by restart:

```text
POST    /api/public/v1/chatbots/{public_chatbot_id}/sessions/{session_id}/messages
OPTIONS /api/public/v1/chatbots/{public_chatbot_id}/sessions/{session_id}/messages
POST    /api/public/v1/chatbots/{public_chatbot_id}/sessions/{session_id}/complete
OPTIONS /api/public/v1/chatbots/{public_chatbot_id}/sessions/{session_id}/complete
```

The message body is exactly `{"message":"…","idempotency_key":"…"}`. Both values are required strings, unknown fields are rejected, and the copied publication character limit is enforced before a turn is reserved. Completion accepts exactly `{}` and returns `204`.

## Authorization and scope

Both routes require `Authorization: Bearer {session_token}`. Middleware hashes the presented token, authenticates it in constant time, and then verifies that the session public ID, chatbot ID, browser channel, production classification, and exact normalized origin all match the authorized publication context. Tokens are never accepted in query strings or cookies, and plaintext is not logged or persisted.

The session remains bound to the immutable publication selected at creation. Execution also rechecks the chatbot's immediate lifecycle and installation provider/model compatibility. Retrieval is restricted to the publication's immutable source-version assignment; mutable draft assignments cannot widen an existing session.

## Idempotency, concurrency, and recovery

The client supplies a fresh idempotency key per logical user submission. The service hashes it and atomically reserves the user turn while locking the session. Reusing a key with the same content replays the completed outcome without another provider call; changed content returns `409 idempotency_conflict`; a live pending request returns `409 message_in_progress`.

A pending user turn older than `CHATBOT_PUBLIC_PENDING_TIMEOUT_SECONDS` is atomically converted to a failed assistant outcome before the retry is evaluated. The retry returns safe `409 stale_message_recovered` and does not call the provider. The client should start a new logical submission with a new idempotency key if the user chooses to try again. This closes crashed reservations without silently issuing a second potentially billable provider request.

## Execution, persistence, and usage

Messages use the same shared execution service as administrator preview. It applies source scope, completed-turn history budgeting, configured no-evidence fallback, public citation projection, provider error mapping, provider quota reservation/reconciliation, and atomic message/session usage persistence. Test traffic remains separately classified and this endpoint cannot address preview sessions.

The response contains the session ID, request UUID as `message_id`, answer, public-safe citations, retrieved-chunk count, fallback/replay flags, and request ID. It deliberately excludes prompts, token-level diagnostics, provider/model details, internal IDs, similarity scores, excerpts, and quota internals.

Expected safe failures include `401 invalid_session`, `404 chatbot_not_found`, `409 message_in_progress|idempotency_conflict|stale_message_recovered`, `410 session_expired`, `422 invalid_request|message_too_long`, `429 rate_limit_exceeded|session_limit_reached|quota_exceeded`, grounded provider mappings, and `503 knowledge_unavailable`.

## Limits and migration

Message requests have independent fixed-window counters per HMAC-derived IP, public chatbot, and public session. Defaults are 30, 300, and 20 requests respectively per 60 seconds. Completion is authenticated but intentionally not rate-counted so a client can close local state after a message bucket is exhausted.

Migration `20260721000018` expands the rate-limit scope check to include `session`; it creates no transcript or identity table and requires no backfill. Its down path deletes ephemeral session buckets before restoring the previous constraint. Prefer forward repair after inspecting live MySQL constraints because DDL may auto-commit.

## Verification

HTTP/security tests cover successful grounded execution and persistence, public citation/usage allowlists, exact origin/session/chatbot binding, missing or wrong bearer credentials, strict schemas and character/count limits, idempotent replay/conflict, pending recovery, provider failure persistence and redaction, completion, preflight, and per-session rate limiting. Provider doubles ensure the suite makes no paid requests.
