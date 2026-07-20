# Documentation index

These documents are the single source of truth for future Ask Asio development. Update them in the same change as any behavior, configuration, schema, API, deployment, or architectural decision they describe.

| Document | Purpose |
| --- | --- |
| [Architecture](architecture.md) | Components, boundaries, request lifecycles, RAG and worker flows. |
| [Roadmap](roadmap.md) | Completed work, current state, planned work, and technical debt. |
| [Architecture decisions](decisions.md) | ADR-style record explaining why the system is built this way. |
| [API reference](api.md) | Versioned endpoints, authentication, validation, responses, errors, limits, and examples. |
| [Database](database.md) | Schema, relationships, migrations, indexes, retention, and scaling notes. |
| [Deployment](deployment.md) | Local setup, environment, Apache/PHP-FPM, worker, maintenance, backup, CI/CD, and monitoring. |
| [Security](security.md) | Trust boundaries, controls, privacy contract, operations, and incident response. |
| [Coding standards](coding-standards.md) | PHP conventions, dependency injection, validation, logging, testing, and static analysis. |
| [Developer guide](developer-guide.md) | Onboarding, feature workflow, Git/PR expectations, commands, and troubleshooting. |
| [Glossary](glossary.md) | Project terminology and where each concept appears. |
| [Knowledge base](knowledge-base.md) | Lessons learned, package/runtime behavior, pitfalls, and performance findings. |
| [Changelog](changelog.md) | Human-readable history organized by milestone and hardening phase. |
| [Customer-facing chatbot](customer-facing-chatbot/README.md) | Living feature specification: product, admin UX, architecture/data, public API/widget, security/privacy, quality, and delivery plan. |

## Documentation status

- Reviewed source baseline: `61e4ed4` on 2026-07-20.
- Initial milestones 1–9: complete.
- Dashboard pagination/data-lifecycle audit: complete.
- CI and real-MySQL integration testing: complete.
- Worker/document safety hardening: complete.
- Provider quota enforcement: complete for authenticated chat and retrieval.
- Customer-facing chatbot: milestone 6 shared chat execution complete; administrator preview is next and public chatbot behavior remains unavailable.
- Recommended next milestone: trusted reverse-proxy support and production scheme/IP correctness. See [Roadmap](roadmap.md#recommended-next-milestone).

## Source of truth rules

1. The code wins when a document and implementation disagree; fix the document immediately.
2. `.env.example` and `config/*.php` are authoritative for supported configuration keys and defaults.
3. `config/routes.php` and API controllers are authoritative for routes and validation.
4. `database/migrations/` is authoritative for the schema. Applied migrations are immutable.
5. Tests define security- and failure-sensitive behavior and must not make real paid provider calls.
6. Historical reviews describe the code when written; use [Roadmap](roadmap.md) for current status.
