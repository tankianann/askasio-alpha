# Administrator preview

## Implemented scope

Milestone 7 adds an authenticated, same-origin preview of a chatbot's current draft at `GET /admin/chatbots/{id}/preview`. Sending and restarting use CSRF-protected POST routes and Post/Redirect/Get. The feature exposes no public chatbot endpoint, CORS behavior, widget asset, or integration credential.

Preview uses the same `SharedChatExecutionService` as future public messages. Source authorization, history budgeting, fallback, citation validation/projection, provider usage, error mapping, persistence, and installation-wide quota reconciliation therefore cannot diverge into a second preview-only pipeline.

## Immutable draft execution binding

A preview session is deliberately not bound to the active publication: it tests the mutable draft the administrator is about to publish. Migration `20260720000016` makes `chatbot_sessions.chatbot_publication_id` nullable and adds:

- `preview_draft_revision`, the exact draft revision being tested;
- `preview_configuration_json`, an immutable bounded snapshot of the validated draft, assigned source IDs, and effective installation provider/model configuration.

A database check requires exactly one execution binding. Production/publication sessions retain a non-null publication and no preview fields. Draft-preview rows require both preview fields, `channel = admin_preview`, and `is_test = 1`. This does not add a tenant/account column or provider secret storage.

Starting a preview locks and verifies the chatbot, draft revision, and source assignments. Each turn executes the stored snapshot, so later draft edits cannot silently change an existing conversation. On the next send after an edit, or after the current preview becomes terminal, the service completes the old session where possible and starts a fresh snapshot. Restart has the same explicit behavior. Old test transcripts remain subject to their copied retention setting.

The snapshot stores provider/model identifiers only. It never stores or returns the installation API key.

## Authorization and traffic classification

Every preview still receives the normal independent 256-bit conversation token. Only its SHA-256 hash and safe prefix are persisted. The one-time plaintext token is kept inside the administrator's server-side PHP session, keyed by chatbot ID; it is never rendered into HTML, placed in a URL, browser storage, Activity, or logs.

The shared executor rejects audience/session mismatches. Administrator preview requires both the `admin_preview` channel and immutable `is_test = true`; public execution rejects that channel. Disabled chatbots remain previewable, while archived chatbots do not. The existing conversation indexes and `is_test` projection keep preview usage separate from production traffic for later conversation administration and reporting.

## Preview screen and diagnostics

The server-rendered screen shows the draft revision, test-only status, transcript, reset control, and latest assistant diagnostics. All transcript and diagnostic text is escaped. The form has bounded input length, CSRF, a fresh idempotency key, and a request ID; submission redirects back to the preview.

Administrator diagnostics are intentionally bounded to:

- provider and model names;
- latency and input/output/embedding/provider token counts;
- fallback use, context tokens, and admitted history turns/tokens;
- admitted retrieval matches with internal source/chunk IDs, similarity, title/type, approved URL, and bounded excerpt.

The page does not expose bearer tokens, provider keys, system prompts, raw provider errors, arbitrary metadata, unselected chunks, or public-origin authorization. Public-safe citations remain a separate projection and do not include internal IDs, similarity, or excerpts.

## Migration, deployment, and forward repair

Back up the database, deploy application code and migration `20260720000016` together, run the normal migration command once, and verify that existing publication-bound sessions still satisfy the new exclusive-binding check. No data backfill is required because existing rows retain their publication IDs and receive null preview fields.

If deployment fails before the migration is recorded, inspect the actual columns, foreign key, and check constraint before retrying because MySQL DDL can auto-commit. Repair forward to the exact migration shape rather than editing an applied migration. The `down()` path must delete draft-preview sessions before restoring the publication column to non-null; that deletion cascades their messages and is destructive, so production rollback should prefer a forward repair.

## Verification

Unit tests cover draft snapshot creation, immutable test classification, revision-triggered restart, shared execution, source-scoped diagnostics, transcript escaping, and route behavior. The existing shared-execution suite covers audience isolation, history, fallback, citations, usage, failures, and quota reconciliation for both publication and draft bindings. An opt-in real-MySQL integration test covers the nullable publication/exclusive preview binding and snapshot hydration.
