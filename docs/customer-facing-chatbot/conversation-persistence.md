# Conversation session and message persistence

## Implementation status

Milestone 5 is implemented as an internal domain and persistence boundary. It deliberately adds no route, controller, browser storage, CORS behavior, widget, preview UI, execution/provider call, scheduled maintenance command, or integration credential.

The implementation adds publication-bound sessions, hash-only session authorization credentials, durable message reservations and outcomes, copied privacy/expiry settings, usage aggregates, bounded expiry/purge repository operations, a forward migration, and tests.

## Code map

| Area | Implementation |
| --- | --- |
| Domain | `ChatbotSession`, `ChatbotMessage`, status/channel/role enums, reservation and completion records. |
| Credentials | `RandomChatbotSessionCredentialGenerator`; independent 256-bit public ID and bearer-token values. |
| Validation | `ChatbotConversationService`; channel/test/origin, credentials, content, request ID, idempotency, outcome, and metadata bounds. |
| Persistence | `ChatbotConversationRepositoryInterface`, `PdoChatbotConversationRepository`. |
| Migration | `20260720000015_create_chatbot_conversation_tables.php`. |
| Tests | In-memory service/concurrency-semantics tests and opt-in disposable-MySQL repository/migration tests. |

No new provider credential is stored. The later shared execution service will use the installation provider/model already copied into the bound publication.

## Session creation and authorization

Creation locks the chatbot and resolves its active immutable publication. Browser and integration sessions require an `active` chatbot; administrator preview may use an `active` or `disabled` chatbot but never an archived one. Browser origins must exactly match the active publication's normalized origin snapshot.

Every session receives:

- `cs_` plus 256 random bits as the non-secret public identifier;
- a separate `cst_v1_` bearer token containing 256 random bits;
- only the bearer token's SHA-256 hex hash and a 15-character safe prefix in storage;
- one chatbot and one immutable publication ID;
- immutable channel, normalized browser origin, and production/test classification;
- copied maximum user turns, maximum message characters, idle timeout, and `0|7|30|90` retention;
- status, user-turn count, usage totals, activity, expiry, terminal, and purge-eligibility timestamps.

The plaintext bearer token is returned once by the service and is not recoverable from the domain record or database. Authentication resolves the indexed token hash, then uses constant-time comparisons for both hash and public session ID. Milestones 8–10 subsequently exposed the one-time token through public session creation and keep it only in per-tab widget `sessionStorage`.

Browser sessions are always production traffic. Administrator preview sessions are always test traffic. Integration sessions retain an explicit immutable `is_test` classification for the later scoped integration contract.

## Messages and idempotency

`message_count` counts accepted user turns, not both rows in a user/assistant pair. Accepting a turn locks the session, checks terminal/idle/absolute expiry and the copied limit, reserves a unique session-scoped SHA-256 idempotency-key hash, writes a pending user message, increments the count, and advances last activity/idle expiry in one transaction.

Retry behavior is deterministic:

- same key and same content while pending: `in_progress`;
- same key and same content after an assistant outcome: `replay` with the stored outcome;
- same key with different content: conflict;
- repeated request UUID under a different key: conflict.

The session row lock prevents simultaneous requests from bypassing the copied user-turn limit. Database unique constraints independently protect session/idempotency, session/role/request ID, and one assistant reply per user message.

Completion atomically changes the pending user message to completed, inserts the completed assistant response with actual provider/model, latency, usage, bounded retrieval/citation metadata, and updates session usage/activity totals. Failure similarly records a content-free failed assistant outcome with a safe error code and conservative provider-token usage. Completing the same reservation twice is rejected. Provider execution and stale-pending recovery remain milestone 6 concerns.

## Expiry, retention, and deletion

Idle expiry advances with accepted/completed activity but is capped by the copied absolute expiry. A message attempt at the expiry boundary commits `expired` status before being rejected. `expireDue()` provides bounded, `SKIP LOCKED` batch claiming for later maintenance wiring.

Terminal transitions are one-way and set purge eligibility as follows:

- retention `0`: terminal time;
- retention `7|30|90`: the session's last activity plus the copied number of days.

`purgeEligible()` hard-deletes bounded eligible session batches; foreign-key cascade removes their messages. Authenticated immediate deletion also hard-deletes one session and its messages. Restart semantics remain completion, not deletion. API Activity/log data and backups retain their independent policies.

The repository provides the primitives only. The administrator retention UI, reviewed/advisory-locked manual purge, scheduler/CLI wiring, and public idempotent-delete response arrive in their later milestones.

## Schema and indexes

`chatbot_sessions` has unique public-ID and token-hash indexes, foreign keys to chatbot/publication, channel/status/retention checks, copied bounds, counts/totals, and indexes for:

- chatbot + test classification + last activity;
- publication dependency;
- idle-expiry batches;
- absolute-expiry batches;
- purge-eligibility batches.

`chatbot_messages` cascades with its session. Its self-reference enforces one assistant reply to an existing user row. It stores role/status/content and hash, session-scoped idempotency and request identifiers, actual execution/usage fields, JSON metadata, safe error, and chronological/completion timestamps. Unique and chronological/pending indexes support reservation, replay, history, recovery, and retention behavior.

No tenant/account/workspace column exists. Ownership is the actual single-installation resource chain: chatbot → publication → session → message.

## Migration, deployment, and forward repair

Migration `20260720000015` creates sessions first and messages second; `down()` drops them in reverse order. It does not backfill or alter existing rows, routes, configuration, or provider behavior.

Before production application:

1. Back up MySQL and prove the backup can be restored.
2. Confirm `chatbot_sessions` and `chatbot_messages` do not already exist unexpectedly.
3. Run the full quality gate and clean/idempotent migration test against disposable MySQL 8.
4. Apply `php bin/migrate.php` before deploying code that creates sessions.
5. Verify the migration ledger, foreign keys, checks, unique constraints, and retention indexes.

MySQL DDL can auto-commit. If application code must be rolled back, leave these unreachable additive tables in place. If migration 15 fails before its ledger entry, inspect `information_schema` and restore the backup where practical. Otherwise write a new, narrowly scoped, tested forward-repair migration that preserves rows and completes or corrects the exact missing object. Never edit an applied migration or mark it applied merely to bypass a partial failure.

## Test coverage

- independent 256-bit identifier/token formats, safe prefix, and correct SHA-256 hash;
- one-time plaintext return with no recoverable token in stored session state;
- active-publication binding, exact normalized origin, copied limits/retention, and production/test rules;
- matching public-ID/token authentication and generic rejection;
- atomic user-turn reservation/count, pending retry, completed replay, changed-payload conflict, and limit enforcement;
- completion metadata and aggregate token accounting;
- expiry-boundary status persistence, last-activity retention calculation, bounded purge, and cascading deletion;
- clean-schema and idempotent second migration pass.

Automated tests make no provider calls. Real-MySQL coverage remains opt-in through the existing disposable database-name safety rule.
