# Source assignments and publication

## Implementation status

Milestone 3 is implemented as an unwired domain/persistence slice. Administrators can assign sources and browser origins through the service/repository boundary, and publication now freezes and activates a complete validated snapshot. No chatbot HTTP routes, admin screens, sessions, public API, or widget have been added.

## Assignment model

`chatbot_draft_sources` and `chatbot_draft_origins` are mutable children of the one draft. Replacing both sets is one transaction guarded by the draft's expected revision. A material assignment change increments the same monotonic `chatbot_drafts.revision` used by configuration edits; an identical normalized assignment is a no-op.

Drafts may be empty while being assembled. Publication requires at least one source and one origin. A draft accepts existing, non-deleted sources even when they are disabled, processing, or embedding-incompatible so an administrator can prepare work before it becomes publishable.

Source IDs are positive, unique, sorted, and limited to 100. Origins are unique, sorted, and limited to 50. Stored origins contain only a lower-case scheme and normalized host plus a non-default port. Remote origins require HTTPS; HTTP is allowed only for `localhost`, `127.0.0.1`, and `::1`. Paths other than `/`, credentials, queries, and fragments are rejected.

## Publication transaction

Publication performs the following in one database transaction:

1. Lock the chatbot and draft and verify the expected revision.
2. Re-read and compare the stored source/origin assignments.
3. Reject an unchanged active configuration hash. The hash covers the normalized draft, source IDs, origins, and effective installation provider/model configuration.
4. Validate every assigned source against its current active version.
5. Insert the next immutable core publication and its source/origin snapshot rows.
6. Update `chatbots.active_publication_id`.

Any failure rolls back the publication, relation rows, and pointer change. Editing the draft later cannot change an active publication. Existing immediate status semantics remain unchanged: publishing does not enable a disabled chatbot, and the active pointer remains the sole production-version selector.

## Ready and compatible sources

Publication mirrors the existing retrieval eligibility boundary. Every assigned source must be enabled, not soft-deleted, have a non-null `sources.active_version_id`, and point to a `ready` source version with at least one stored embedding matching the installation embedding model and configured dimensions.

Readiness deliberately follows `sources.active_version_id`, not `MAX(version_number)` or the newest revision. A newer pending, processing, or failed replacement therefore does not disqualify the older ready active version. Conversely, a ready historical version does not rescue an unready active pointer.

Failures are classified as missing, deleted, disabled, no active version, active version not ready, or embedding incompatible. The service converts these into bounded administrator-safe validation messages. Public execution will later also reject a publication whose snapshotted installation provider/model no longer matches runtime configuration.

## Immutable scope and dependencies

`chatbot_publication_sources` and `chatbot_publication_origins` are append-only snapshot children. Production execution must read only the active publication's relations and must never accept source IDs from a browser client.

Dependency queries report whether a source appears in each chatbot's mutable draft, active publication, or publication history. Source foreign keys use `ON DELETE RESTRICT`: immutable history is not silently rewritten. Permanent source deletion consults the dependency query inside its existing locked transaction and reports dependent chatbot names before private files are staged. The administrator must remove draft usage and permanently delete the dependent archived chatbot/publication history before the source can be hard-deleted.

## Migration and forward repair

Migration `20260720000014_create_chatbot_assignment_tables.php` adds the four relation tables and their source lookup indexes/foreign keys. It has no backfill: pre-existing chatbot drafts and core publications begin with empty relations and cannot be republished through `ChatbotService` until assignments are supplied.

Before production migration, back up MySQL and verify migration `20260720000013` is present. Apply the normal ordered migrator, then confirm all four tables and the migration ledger entry. Deploying the schema changes no public behavior because the feature remains unrouted.

MySQL DDL can auto-commit. If application code must be rolled back after a successful migration, leave these additive empty-capable tables in place. If DDL fails before the ledger entry, inspect `information_schema`, restore the backup when appropriate, or create a reviewed forward-repair migration for the exact partial state. Never edit an applied migration or mark it applied manually. On an unused test database only, `down()` drops publication origins, publication sources, draft origins, then draft sources.

## Test coverage

- canonical origin normalization and strict rejection;
- assignment normalization, shared optimistic revision, and immutable publication copies;
- empty/unready/incompatible publication rejection with no active pointer mutation;
- active-ready version eligibility when a newer version is pending;
- source dependency projections and deletion protection;
- clean-schema migration/idempotence coverage on opt-in disposable MySQL.

Provider calls are not made by these tests. Real-MySQL tests use the existing database-name-suffix safety guard.
