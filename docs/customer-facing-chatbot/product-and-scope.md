# Product and scope

## Feature summary

The customer-facing chatbot lets the administrator configure one or more public assistants that answer website visitors from explicitly assigned Ask Asio knowledge sources. Each chatbot can be previewed in the dashboard, published through a versioned public API, and embedded on an external website.

The feature must preserve Ask Asio's grounded-answer behavior: retrieve eligible chunks first, answer only from admitted context, cite the sources used, and return a clear fallback without a chat-generation call when the evidence is insufficient.

## Product model

### Installation

The installation is the security and ownership boundary. There is one administrator and one account/tenant. All chatbots, sources, API keys, provider settings, sessions, and usage data belong to this installation.

### Chatbot

A chatbot is a configured public assistant. It has:

- an internal numeric ID and a separate high-entropy public ID;
- name and internal description;
- draft/published lifecycle and enabled/disabled availability;
- selected chat model from the installation's supported provider configuration;
- one or more explicitly assigned knowledge sources;
- server-only instructions, fallback behavior, and retrieval settings;
- welcome, starter-question, input, citation, and appearance settings;
- origin, rate-limit, session, storage, and retention settings;
- a monotonically increasing configuration version and audit timestamps.

Multiple chatbots may share a knowledge source. A chatbot must never retrieve from an unassigned source, even though all sources have the same administrator.

### Provider connection

The current application has one installation-level OpenAI connection configured through environment variables. For the initial release:

- the raw key stays in `.env`/process configuration and is never stored in a chatbot row;
- the admin UI may show the provider/model and configuration health, but not the secret or a fake credential selector;
- a chatbot stores an allowlisted model name only if per-chatbot model selection is implemented safely; otherwise it uses the configured installation model;
- disabling or rotating the environment credential affects all chatbots and existing authenticated RAG endpoints.

Named, database-stored provider connections require a separate decision covering encryption, key management, rotation, dependency checks, and migration. They are not implied by this feature's first release.

### Knowledge assignment

A chatbot has an explicit many-to-many relationship with existing `sources`. Only enabled, non-deleted sources with a ready compatible active version may contribute chunks at request time. Publication should fail when grounding is required and none of the assigned sources is ready.

Rebuilding a source must preserve the existing active version behavior: the prior ready version remains usable until a new revision activates. Disabling or deleting a source makes it ineligible immediately without silently widening retrieval to other sources.

### Chat session

A session is one visitor conversation with one chatbot. It has a non-guessable public identifier or token, server-side chatbot ownership, creation/last-activity/expiry timestamps, production/test classification, origin, status, message count, bounded approved metadata, and usage aggregates.

An optional external user reference may be accepted only from an authenticated server integration. Browser callers must not assert trusted identity or arbitrary metadata.

### Chat message

A message records the session, role, bounded content when storage is enabled, status, model, latency, usage, retrieval/citation metadata, safe error code, request ID, and timestamp. It must never record or return provider secrets, hidden chain of thought, authorization headers, or an unrestricted copy of internal prompts.

## Users and stories

### Administrator

The single administrator can:

1. Create, edit, duplicate, publish, disable, archive/delete, and rotate the public ID of a chatbot.
2. Assign one or more ready knowledge sources and configure grounded behavior.
3. View the active installation provider/model without seeing the raw key.
4. Configure welcome copy, suggested questions, appearance, allowed origins, limits, privacy notice, and retention.
5. Preview draft configuration through the same execution path used publicly.
6. View paginated conversations and usage, distinguish tests from production, and perform confirmed purge operations.
7. Copy embed instructions and create scoped server integration credentials when that milestone is implemented.
8. Receive actionable validation errors when publishing is unsafe or incomplete.

### Website visitor

A visitor can:

1. Open an accessible chatbot and see its automated-assistant disclosure, welcome text, and starter questions.
2. Start a bounded session, send a message, receive a grounded response, and inspect approved citations.
3. Continue within the same valid session or explicitly restart it.
4. Receive safe, useful errors and a clear insufficient-information response without internal details.

### Integration developer

An external developer can:

1. Load the versioned browser widget using only a public chatbot ID.
2. Use documented non-streaming session/message endpoints and stable error codes.
3. Use a distinct scoped server credential for trusted integration flows when available.
4. Never receive the installation's AI-provider key.

## Initial release scope

The first release should include:

- multiple chatbots within the single installation;
- explicit source assignment;
- reuse of the installation-level OpenAI connection and existing embedding configuration;
- grounded RAG with citations and fixed/configured fallback;
- admin list, create/edit, preview, publish, disable, public-ID rotation, and safe delete/archive behavior;
- public configuration, session, and non-streaming message APIs;
- an isolated, accessible JavaScript widget;
- exact origin allowlists plus documented browser-origin limitations;
- chatbot/session/IP rate limits integrated with provider quotas;
- conversation persistence, pagination, retention, and purge;
- usage/audit integration that respects the existing privacy contract;
- unit, real-MySQL integration, HTTP, widget, security, and end-to-end tests;
- complete deployment, rollback/forward-fix, and operator documentation.

## Deferred scope

- multi-tenancy, organisations, workspaces, roles, or additional administrators;
- named database-stored provider credentials and arbitrary provider switching;
- a packaged WordPress plugin (the API and embed foundations come first);
- streaming unless an explicit infrastructure design is approved;
- human handoff, CRM/lead capture, appointments, visitor file uploads, voice, and tool calling;
- arbitrary custom JavaScript or advanced theme builders;
- answer caching, model failover, cross-chatbot routing, and customer billing;
- authenticated external-user identity beyond bounded server-supplied references;
- multilingual administration and advanced analytics dashboards.

## Success criteria

The feature succeeds when an administrator can publish an explicitly source-scoped chatbot, an allowed website can hold a bounded conversation and receive cited grounded answers, a disallowed origin or invalid session cannot use it, secrets never reach the client, stored conversations obey retention, and the same configured behavior is observable in admin preview and public use.

