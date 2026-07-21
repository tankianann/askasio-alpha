# Administrator list and edit flow

## Implementation status

Milestone 4 is implemented. The authenticated, CSRF-protected administrator dashboard now exposes chatbot navigation, a paginated list, creation, bounded editing, source/origin assignment, publication, and lifecycle controls. There is still no public chatbot API, session, preview, conversation UI, widget, or integration credential.

## Routes and access boundary

All routes live below `/admin/chatbots` inside the existing session-authentication and CSRF middleware group. GET routes render the list, create form, and URL-backed edit tabs. POST routes create/update drafts, batch add/remove sources (with the original single-source routes retained for compatibility), replace origins, publish, enable/disable, rotate the public ID, archive, and permanently delete.

Controllers resolve numeric chatbot/source route identifiers and return the existing safe 404 boundary when a resource is absent or mismatched. Mutations use service validation and Post/Redirect/Get flash messages except invalid configuration edits, which return escaped old input with status `422`.

## Paginated list

The chatbot list uses the bounded `ChatbotListItem` projection and SQL count/result queries. It supports:

- 25, 50, or 100 rows per page with canonical out-of-range redirects;
- search by internal name or public ID;
- lifecycle status and draft/published filters;
- exact effective chat-model filtering;
- current draft source-name filtering through an indexed relation query;
- allowlisted name/status/publication/updated sorting with deterministic ID tie-breaking.

No system instructions, descriptions, JSON settings, origins, provider secrets, source content, or publication hashes are loaded into the list projection. Unfiltered and filtered empty states are distinct. Invalid query parameters redirect to canonical defaults with a bounded safe message.

## Create and edit

Creation asks only for an internal name and optional description. It creates a private draft with validated installation-derived retrieval limits and documented defaults, then redirects to editing. It does not publish or enable a public endpoint.

The edit screen is split into server-routed Settings, Knowledge, Access, and Lifecycle tabs with shared state metadata plus persistent Preview/Publish header actions:

- read-only immediate status, public ID, installation provider/model metadata, and publication state;
- basic identity and public display name;
- grounded server instructions, fallback, top-K, similarity, and citation display;
- welcome/input/starter text, message/session limits, expiry, retention, privacy URL, and disclosure;
- validated appearance enums/text/accent;
- separately saved exact origins under Access;
- filtered, paginated, batch source assignment under Knowledge;
- immediate and destructive controls under Lifecycle.

Configuration and assignment forms carry the shared expected draft revision. Stale submissions fail rather than overwrite newer edits. Saving a draft never publishes it.

The source picker reuses the SQL-paginated knowledge-source list instead of loading the full catalog. Checkbox batches merge all selected additions or removals with the current assignment set in one optimistic-revision update, then return to the preserved Knowledge filters. Both assigned rows and picker rows show publication readiness based on the active source version, including disabled/deleted/missing-active-version/embedding-incompatible states. A newer pending replacement does not hide a ready active revision.

## Provider-degraded state

The application remains bootable when installation chat or embedding model names are missing. The chatbot edit UI shows a safe configuration error and read-only `Not configured` values; draft editing remains available. `ChatbotService` blocks publication, so a placeholder value can never become an active publication snapshot.

Provider credentials, key prefixes, raw errors, and environment values are never shown.

## Lifecycle behavior

- Publish validates the current complete draft/source/origin scope and activates a new immutable snapshot.
- Disable and enable are immediate controls; enabling requires an active publication.
- Public-ID rotation immediately invalidates the previous identifier without altering draft/publication history.
- Archive is terminal in the current UI and makes editing, publication, enablement, and rotation unavailable.
- Permanent deletion appears only for archived chatbots and requires the exact internal name before the service's archive guard and repository cascade run.

All controls use POST and CSRF protection. No lifecycle form implies that public chat already exists.

## Deliberately excluded settings

The UI does not expose model/provider selection, provider credentials, arbitrary CSS/HTML/JavaScript, general-knowledge fallback, answer-style/language controls, reranking/vector-store knobs, request/IP limits, metadata allowlists, preview, session restart, conversation data, analytics, integration credentials, embed code, or widget settings that lack an implemented schema/service behavior.

## Deployment and rollback

Milestone 4 adds no migration or environment variable. Deploy code and static assets after migrations `20260720000013` and `20260720000014` are present. Existing chatbot records with empty assignments are editable but cannot be published until at least one ready source and one allowed origin are configured.

Rollback is application-code rollback only. The additive milestone 2–3 schema remains compatible. Removing this UI does not modify drafts or active publications.

## Test coverage

- list pagination, filter persistence, filtered/unfiltered empty states, and safe invalid-query feedback;
- allowlisted/parameterized model and source filters;
- escaped invalid form values and `422` validation;
- create, source assignment, origin normalization, publication, disable/enable, public-ID rotation, archive, exact-confirmation deletion;
- provider-degraded rendering and service-level publication rejection;
- existing repository/service/source-scope and clean-schema coverage.
