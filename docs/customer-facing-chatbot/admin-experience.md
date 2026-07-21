# Admin experience

## Implementation status

Milestones 4, 7, 11–13 implement chatbot administration, draft preview, production/test conversation inspection and retention, scoped server credentials, and bounded analytics. See [Administrator list and edit flow](admin-list-and-edit.md), [Administrator preview](admin-preview.md), [Conversation administration and retention](conversation-administration-and-retention.md), [Scoped server integration credentials](scoped-integration-credentials.md), and [Analytics and operational readiness](analytics-and-operational-readiness.md). A dedicated administrator embed-code panel remains deferred; operators can use the documented widget snippet.

## General rules

The chatbot area must use the current server-rendered dashboard, navigation, design tokens, escaped PHP views, authenticated session, and CSRF protection. Controllers remain thin; validation and workflows live in services; SQL remains in repositories.

All growing lists must filter, sort, count, and paginate in SQL using allowlisted query state and deterministic ID tie-breakers. Browser-only filtering or silent result caps are not acceptable.

## Chatbot list

Display:

- name;
- draft/published and enabled/disabled state;
- provider and effective chat model;
- assigned and ready source counts;
- production conversation count and last activity;
- created and updated timestamps;
- actions appropriate to the current lifecycle.

Support search; status, model, and source filters; allowlisted sorting; 25/50/100 page sizes; canonical query-string state; empty states; and creation. Provider filtering is unnecessary while only one provider exists.

Duplicate creates a new draft with a new public ID and no conversation history. Disable stops new public sessions/messages without deleting configuration or history. Destructive behavior must follow an explicit archive/soft-delete/permanent-delete decision and use exact confirmation for irreversible removal.

## Create and edit

### Basic information

- internal name and description;
- enabled state, subject to lifecycle rules;
- public display name.

### AI provider

Show the installation provider, effective chat model, embedding model, and safe configuration-health state. Do not show a raw key, last four characters, or named credential selector because the current provider key is environment configuration rather than a stored record.

The initial release has no model selector. Chat and embedding models are installation-wide and read-only in this form. A publication records the effective provider/model metadata; if environment configuration later differs, the public chatbot becomes configuration-stale until the administrator reviews and republishes it.

### Knowledge

- select one or more existing sources;
- show source type, enabled state, active revision readiness, and embedding compatibility;
- configure top-K only within existing supported limits;
- configure minimum similarity only after a product-safe allowed range/default is approved;
- toggle public citation display while retaining internal retrieval metadata;
- do not expose unsupported reranking or vector-store controls.

The form must not imply that a rebuilding source is unavailable when its prior active revision remains ready. Publication validation evaluates effective retrievability, not merely the newest revision status.

### Behavior

- server-only role/purpose and additional response rules;
- answer style, language behavior, and desired response length within provider limits;
- safe fallback message;
- refusal/unsupported-question behavior;
- grounded-only policy by default.

General-knowledge answers outside assigned sources are deferred. Enabling them would weaken the current no-evidence invariant and requires a separate security/product decision.

### Conversation

- welcome message and automated-assistant disclosure;
- input placeholder and bounded starter questions;
- idle/absolute session expiry;
- maximum messages per session;
- maximum message characters no higher than server/provider bounds;
- retention period (`0`, `7`, `30`, or `90` days; default `30`), with an explanation that content is temporarily persisted for every active multi-turn session;
- visitor restart control.

### Appearance

Initial settings should remain intentionally small: display name, launcher label/approved icon, primary accent, light/dark mode where supported, left/right position, panel title, and compact/standard size. Store validated values, expose them as CSS custom properties or safe enums, and reject arbitrary CSS/HTML/JavaScript.

### Security and access

- public access enabled/disabled;
- exact allowed origins;
- request/message limits by supported scope;
- allowed browser metadata fields;
- public ID rotation;
- scoped server integration access when implemented;
- privacy notice URL and disclosure/consent copy.

## Publication lifecycle

The lifecycle is:

```text
unpublished draft -> active with publication <-> disabled -> archived -> permanently deleted
```

Draft/publication state and immediate availability are distinct: a chatbot is unpublished when `active_publication_id` is null; `active`, `disabled`, and `archived` control whether an existing publication may be used. Saving a draft never makes it public. Before publication, the service must validate:

- installation provider configuration is usable;
- selected model is supported;
- grounding-required chatbots have at least one assigned retrievable source;
- instructions, fallback, welcome copy, starter questions, and limits are bounded;
- every origin is normalized and valid;
- rate/session/retention/privacy settings are internally consistent;
- client-visible configuration contains no secret or server-only field.

Publishing creates an immutable numbered configuration snapshot plus immutable source and origin relations, then atomically updates `active_publication_id`. Edits to a published chatbot remain mutable draft changes until deliberately republished; the active publication remains stable. Published snapshots are append-only. Historical reactivation requires current validation and an auditable action.

Rotating a public ID invalidates the prior ID immediately and requires updating embed code. Rotation must not change the internal chatbot ID or erase sessions.

## Preview and test console

Preview is admin-only and may execute draft configuration. It must:

- call the shared chat execution service used by public messaging;
- start/reset a clearly marked test session;
- enforce source assignment, safety, size, context, and provider quota rules;
- show citations, similarity/retrieval details, model, latency, usage, and request ID where available;
- never reveal provider secrets or hidden chain of thought;
- exclude test traffic from production summaries or make the distinction explicit everywhere.

Preview may bypass publication and public-origin checks because the administrator is already authenticated, but it must not bypass chatbot configuration or RAG safeguards.

## Conversation list

Display chatbot, non-sensitive session reference, started/last activity, message count, state, approximate usage, origin, test/production classification, optional external reference, and actions.

Support search only over approved indexed identifiers, date range, chatbot, origin, state, and test/production filters; allowlisted sorting; pagination; clear active-filter summaries; and canonical invalid-page recovery. Do not load message bodies or large diagnostic JSON into the list projection.

## Conversation detail

Render messages chronologically and safely as text or sanitized supported markup. Show citations, source labels, model, latency, token usage, safe errors, and request IDs where recorded. Retrieval chunks should be shown only through bounded diagnostic excerpts and must not expose private paths or unrelated source data.

Do not display authorization headers, secrets, raw system prompts, chain of thought, raw provider responses, or unapproved visitor metadata.

## Retention and purge UX

Retention applies to chatbot sessions/messages independently of the existing API Activity retention. Manual purge must use the established safe pattern: authoritative preview count, validated scope, CSRF, exact confirmation phrase, short-lived server-side intent, maximum reviewed record boundary, advisory lock, bounded deletes, and audit log.

Purge scopes should include one chatbot, one session, an age/date range, test traffic, or all eligible conversations. New records created after preview must not be swept accidentally. Purge hard-deletes sessions and cascades messages. Visitor restart only completes a session; authenticated visitor deletion is a separate hard-delete action. Backups retain purged data until their own expiry and the UI/docs must say so.
