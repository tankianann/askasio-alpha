# Core domain and persistence

## Implementation status

Milestone 2 is implemented as a domain/persistence foundation. It adds no admin/public routes, views, bootstrap wiring, environment variables, provider credential storage, source/origin associations, sessions, or widget assets.

Implemented boundaries:

- chatbot identity and immediate lifecycle status;
- mutable one-to-one draft with optimistic revision;
- immutable numbered core publication snapshots and active pointer;
- installation provider/chat/embedding configuration snapshot;
- high-entropy rotatable public IDs;
- normalized runtime/privacy settings and validated presentation/appearance JSON;
- repository/service validation, bounded list projections, migration, and tests.

Milestone 3 now completes source/origin snapshots and publication readiness. See [Source assignments and publication](source-assignments-and-publication.md). No controller or public execution path exposes publications yet.

## Code map

| Area | Implementation |
| --- | --- |
| Domain | `app/Domain/Chatbots/*` immutable records and enums. |
| Validation/lifecycle | `ChatbotDraftValidator`, `ChatbotService`. |
| Provider snapshot | `InstallationChatbotProviderConfigurationFactory`; reads existing `providers.*` configuration only. |
| Public IDs | `RandomChatbotPublicIdGenerator`; `cb_` plus 256 random bits encoded base64url. |
| Persistence | `ChatbotRepositoryInterface`, `PdoChatbotRepository`. |
| Lists | `ChatbotListQuery`, filter/sort enums, `ChatbotListSqlQueryBuilder`, secret/config-free `ChatbotListItem`. |
| Migration | `20260720000013_create_chatbot_core_tables.php`. |
| Tests | Unit validation/service/query/factory tests, in-memory repository, real-MySQL repository/migration tests. |

## Schema

### `chatbots`

Stores identity and immediate control only:

- high-entropy unique `public_id`;
- internal `name` and nullable `description`;
- `status`: `active`, `disabled`, or `archived`;
- nullable `active_publication_id`;
- created/updated/archive timestamps.

An active row with a null publication pointer is an unpublished draft. Disabling never changes the active snapshot. Archiving is required before permanent deletion.

### `chatbot_drafts`

One row per chatbot, cascading with identity. It stores schema version, optimistic revision, normalized execution/privacy settings, validated JSON presentation/appearance, and update time. Database checks enforce positive values, `0..1` similarity, expiry ordering, citation boolean, and the `0|7|30|90` retention enum.

Service validation is stricter than database checks:

- schema version `1` only;
- top-K no higher than configured existing chat maximum;
- message length no higher than the existing chat input maximum;
- maximum 100 messages/session;
- idle expiry 5–1440 minutes;
- absolute expiry at least idle and at most 10080 minutes;
- HTTPS privacy URL;
- exact JSON keys/enums, bounded plain text, no presentation HTML;
- canonical JSON key order and normalized hexadecimal accent.

Draft updates use `WHERE revision = :expected_revision` and increment atomically inside the same transaction as identity edits. A stale edit raises `StaleChatbotDraftException` and rolls back.

### `chatbot_publications`

Append-only core snapshot with:

- unique `(chatbot_id, publication_number)`;
- source draft revision, schema version, SHA-256 canonical configuration hash;
- a copy of every normalized/JSON draft setting;
- installation `chat_provider`, `chat_model`, `embedding_provider`, `embedding_model`, and optional dimensions;
- publication timestamp.

Publishing locks identity and draft, rechecks the active configuration hash inside that lock, verifies the expected revision, inserts the next immutable snapshot, and updates `active_publication_id` in one transaction. Publishing does not re-enable a disabled chatbot. Both service and locked repository transaction reject unchanged republishing; the service also rejects archived publication. Editing a draft never mutates the active publication.

Milestone 3 adds draft/publication source and origin relations to this transaction before any public use.

## Lifecycle and deletion

```text
active unpublished draft -> active publication
active <-> disabled
active/disabled -> archived -> permanent deletion
```

- `enable` requires an active publication.
- `publish` is forbidden after archive.
- public-ID rotation is forbidden after archive and atomically invalidates the previous lookup.
- permanent deletion requires archive, clears the cyclic active pointer, and deletes identity; drafts/publications cascade.
- publishing a disabled chatbot updates the snapshot while keeping it disabled.

There is no HTTP authorization surface in this milestone; future controllers must use `ChatbotService` rather than repositories directly.

## List projections

The repository list query selects identity, status, draft revision, active publication number, effective chat provider/model, and timestamps only. It excludes system instructions, fallback text, presentation/appearance JSON, description, configuration hash, and future sensitive/large relations.

Search is parameterized across name and public ID. Status and draft/published filters run before pagination. Sorting is allowlisted with a deterministic ID tie-breaker. The current repository supports existing 25/50/100 `PageRequest` behavior; parsing/admin UI arrive later.

## Provider/model behavior

No provider key or connection ID is stored. `InstallationChatbotProviderConfigurationFactory` reads the existing typed `providers.chat_provider`, `providers.embedding_provider`, and OpenAI model/dimension settings. `ChatbotService` snapshots only these names/dimensions during publication.

The OpenAI secret remains environment/process configuration. Per-chatbot credential/model selection remains out of scope. Milestone 3 validates assigned source embeddings against current installation metadata; later public execution must also compare the publication snapshot with runtime installation configuration and surface configuration-stale publications.

## Migration and deployment

Migration `20260720000013` creates the three new tables, their checks/indexes/foreign keys, then adds the active-publication foreign key after both sides exist. There is no data backfill and no change to existing tables, configuration, routes, or provider behavior.

Before production application:

1. Back up MySQL and confirm the backup is restorable.
2. Stop feature deployment if tables named `chatbots`, `chatbot_drafts`, or `chatbot_publications` already exist unexpectedly.
3. Run the normal quality gate and clean-schema migration test against MySQL 8.
4. Run `php bin/migrate.php` once.
5. Confirm migration ledger entry `20260720000013_create_chatbot_core_tables` and all three tables/foreign keys/checks.
6. Deploying this migration alone changes no reachable application behavior because nothing is wired yet.

## Rollback and forward repair

MySQL DDL may auto-commit, so production rollback is backup restore or a reviewed forward repair—not an assumption that `down()` is atomic.

- Before any chatbot data exists, test/development may invoke the migration's `down()` in reverse dependency order: remove active-publication FK, publications, drafts, then chatbots.
- After data exists, do not drop tables to roll back application code. The feature is unreachable in this milestone, so leave additive schema in place and deploy the prior code safely.
- If migration fails before its ledger entry, inspect `information_schema` and logs. Prefer restoring the pre-migration backup. Do not repeatedly rerun against partially created tables.
- If restore is not appropriate, write and test a narrowly scoped forward-repair migration/manual runbook that completes or corrects the exact missing table, constraint, or index. Preserve any rows; never mark the original migration applied merely to bypass an error.
- Once the migration has been applied in any shared environment, do not edit it. All corrections use a new ordered migration.

## Test coverage

- validation normalization, ranges, retention, HTTPS, HTML/unknown JSON rejection;
- 256-bit public ID format and rotation;
- create/update/publish immutability, unchanged publication rejection, optimistic concurrency;
- enable/disable/archive/permanent-delete guards;
- installation provider/model factory;
- parameterized list filters and allowlisted ordering;
- real-MySQL transaction, snapshot immutability, list projection, rotation, stale revision, cascade deletion;
- clean schema plus idempotent second migration pass.

Automated tests make no provider calls. Real-MySQL tests remain opt-in through the existing disposable database ending in `_test` safety rule.
