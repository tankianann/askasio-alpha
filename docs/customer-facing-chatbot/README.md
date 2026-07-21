# Customer-facing chatbot

## Status

This directory is the living specification for Ask Asio's customer-facing chatbot feature. It establishes the documentation baseline before feature code is written.

- Specification status: proposed for implementation review.
- Implementation status: milestones 11 and 12 conversation administration/retention and scoped server integrations complete; analytics/operational readiness is next.
- Decision milestone: ADRs 027–033 recorded; draft/publication execution bindings and shared execution policy accepted.
- Product boundary: one installation, one administrator, one tenant/account.
- Current baseline: environment-configured OpenAI provider, shared knowledge-source catalog, compatible grounded `/api/v1/chat`, hash-only credentials, durable publication/draft-preview conversations, administrator preview, and exact-origin public configuration/session creation.

Requirements use **must** for release requirements, **should** for preferred behavior that needs an explicit reason to omit, and **may** for optional behavior. Items labelled **Decision required** are deliberately unresolved and must be settled before their implementation milestone.

## Documents

| Document | Purpose |
| --- | --- |
| [Product and scope](product-and-scope.md) | Product model, users, stories, release scope, deferrals, and success criteria. |
| [Admin experience](admin-experience.md) | Dashboard screens, configuration, preview, publication, conversations, and destructive actions. |
| [Architecture and data](architecture-and-data.md) | Existing foundations, target flow, service boundaries, lifecycle, and proposed schema. |
| [Vertical-slice design](vertical-slice-design.md) | Accepted decisions combined into the draft/publication/session persistence and execution shape. |
| [Core domain and persistence](core-domain-persistence.md) | Implemented milestone 2 schema, lifecycle, validation, projections, migration, tests, and forward repair. |
| [Source assignments and publication](source-assignments-and-publication.md) | Implemented milestone 3 mutable assignments, immutable scope snapshots, active-version readiness, dependencies, migration, and tests. |
| [Administrator list and edit flow](admin-list-and-edit.md) | Implemented milestone 4 navigation, paginated list/picker, bounded forms, lifecycle actions, safe states, routes, and tests. |
| [Conversation persistence](conversation-persistence.md) | Implemented milestone 5 session/message schema, hash-only tokens, publication binding, idempotency, expiry, retention, migration, and tests. |
| [Shared chat execution](shared-chat-execution.md) | Implemented milestone 6 source-scoped grounding, history, fallback, citations, persistence, failures, quota reconciliation, and compatibility. |
| [Administrator preview](admin-preview.md) | Implemented milestone 7 immutable draft-preview sessions, admin routes/UI, safe diagnostics, test classification, migration, and tests. |
| [Public configuration and session API](public-configuration-and-session-api.md) | Implemented milestone 8 public DTOs, exact-origin CORS, session creation, hash-only tokens, request limits, migration, and tests. |
| [Public message API](public-message-api.md) | Implemented milestone 9 bearer authorization, execution, idempotency, failure recovery, usage, limits, migration, and tests. |
| [Widget foundation](widget-foundation.md) | Implemented milestone 10 loader, isolated accessible UI, browser storage, API calls, restart, safe rendering, responsiveness, and compatibility. |
| [Conversation administration and retention](conversation-administration-and-retention.md) | Implemented milestone 11 bounded views, classification, scheduled/manual retention, locking, deletion, and audit behavior. |
| [Scoped server integration credentials](scoped-integration-credentials.md) | Implemented milestone 12 separate one-time credentials, chatbot scopes, server API, limits, usage, migration, and tests. |
| [Analytics and operational readiness](analytics-and-operational-readiness.md) | Implemented milestone 13 bounded content-free summaries, configuration/limit inventory, release/monitoring/troubleshooting, backup/restore, and forward-fix guidance. |
| [Public API and integrations](public-api-and-integrations.md) | Browser API, session/message contracts, widget, CORS, WordPress, and server-to-server access. |
| [Security and privacy](security-and-privacy.md) | Trust boundaries, resource scoping, abuse controls, secrets, retention, and content safety. |
| [Quality and operations](quality-and-operations.md) | Grounding, reliability, observability, caching, test strategy, deployment, and definition of done. |
| [Delivery plan](delivery-plan.md) | Documentation-first micro-milestones, dependencies, decisions, and review gates. |

## Governing constraints

1. Ask Asio remains a single-administrator, single-tenant application. Multiple chatbots are resources inside the one installation; they are not tenants, workspaces, organisations, or customer accounts.
2. No chatbot table needs `tenant_id`, `account_id`, or `workspace_id`. Multi-tenancy is a future schema and authorization redesign, not hidden scope for this feature.
3. Public requests still require strict server-side resource scoping: a session belongs to one chatbot, a chatbot retrieves only its assigned sources, and a server integration credential may use only its assigned chatbots.
4. The existing framework-free controllers, services, repository interfaces/PDO adapters, request IDs, middleware, error envelope, quota system, RAG pipeline, and server-rendered admin conventions must be extended rather than duplicated.
5. The current OpenAI credential is installation-level configuration in `.env`. The initial chatbot release must reuse that provider connection unless a separate credential-storage milestone is approved.
6. Provider secrets, system instructions, internal source identifiers, private paths, and sensitive diagnostics must never appear in public configuration or widget payloads.
7. Admin preview and public chat must call one shared execution service so grounding, source scoping, quotas, errors, and usage accounting cannot drift.

## Relationship to existing documentation

The repository-wide documents remain authoritative for implemented behavior. This directory describes the target feature until implementation catches up.

- [Architecture](../architecture.md) governs current component and RAG boundaries.
- [API reference](../api.md) governs existing `/api/v1` behavior and error conventions.
- [Database](../database.md) governs current schema and migration policy.
- [Security](../security.md) governs existing controls and privacy contracts.
- [Coding standards](../coding-standards.md) and [Developer guide](../developer-guide.md) govern implementation and review.
- [Roadmap](../roadmap.md) governs repository-wide priority and technical debt.
- [Architecture decisions](../decisions.md) records accepted decisions; material chatbot decisions must be added there when approved.

When this target specification and implemented behavior differ, the current code and repository-wide docs win. Update this feature specification in the same change that resolves the difference.

## Source-specification corrections

The original feature brief used multi-tenant language. This living specification intentionally translates it as follows:

| Original concept | Ask Asio meaning |
| --- | --- |
| tenant/account/workspace-owned chatbot | chatbot in the single Ask Asio installation |
| tenant-owned provider credential | installation-level provider configuration; named stored connections are deferred |
| tenant-scoped knowledge | explicit chatbot-to-source assignment within the installation |
| tenant-scoped session/message | session/message ownership by chatbot with non-guessable public identifiers |
| tenant isolation test | chatbot/source/session/integration-scope authorization test |
| tenant quota/cache key/log field | installation, chatbot, session, API-key, or integration-credential scope as appropriate |
| tenant deletion workflow | administrator-initiated chatbot/conversation purge plus installation backup retention |

This is not merely a wording change: adding tenant columns now would create a misleading partial multi-tenant model without a real tenant identity, authentication boundary, or globally enforced query scope.
