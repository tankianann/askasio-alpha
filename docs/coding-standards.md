# Coding standards

## Language and runtime

- Target PHP 8.3 or later; CI covers PHP 8.3 and 8.4.
- Every PHP source file starts with `declare(strict_types=1);`.
- Use PSR-4 namespace `App\` for `app/` and `Tests\` for `tests/`.
- Prefer PHP language types, readonly constructor promotion, enums, value objects, and return types over informal array contracts.
- Use PHPDoc for generic/list/array shapes that PHP cannot express, not to repeat obvious native types.

## Style

- Follow the existing PSR-12-like layout and Composer autoloading.
- One primary class/interface/enum per file; filename matches symbol.
- Classes are `final` by default. Leave a class open only when inheritance is an intentional extension mechanism.
- Interfaces end in `Interface`; PDO implementations begin with `Pdo`; test implementations use `Fake` or `InMemory`.
- Enums use singular domain names (`JobStatus`, `SourceType`).
- Methods and variables use camelCase; classes use PascalCase; environment/database identifiers use snake case.
- Prefer early validation/returns and focused methods. Avoid large controller actions and deeply nested conditionals.
- Do not add global mutable state, static service locators, or hidden environment reads inside domain logic.
- Comments explain non-obvious intent, invariants, or security rationale. Do not narrate straightforward code.

## Directory responsibilities

- `Controllers`: translate HTTP input/output; no raw SQL and minimal business logic.
- `Services`: orchestrate application use cases and transactions exposed by repositories.
- `Repositories`: persistence interfaces/adapters and SQL mapping.
- `Domain`: dependency-light records, enums, and value objects.
- `Http`: transport primitives and middleware.
- `Ingestion`, `Providers`, `RAG`, `Networking`: specialized domain/integration logic.
- `bootstrap`: explicit composition only; no reusable business logic.
- `resources/views`: presentation with escaped values; no database calls or mutations.

## Dependency injection

- Use constructor injection and readonly dependencies.
- Construct production objects in a composition root (`bootstrap/*.php`).
- Inject interfaces at external/replaceable boundaries: repositories, embedding/chat provider, vector store, extractor, OCR, URL fetcher, maintenance lock, session store.
- Do not introduce an interface for a stable internal helper solely for test mocking; test through behavior or inject the true unstable boundary.
- Optional dependencies may support backward-compatible tests during a transition, but production wiring must be explicit and tested.

## HTTP and controllers

- Validate media type/body size/JSON before field validation.
- Use `HttpException` only for safe client-facing status/code/message triples.
- Keep API errors in the standard envelope and include request ID.
- Reject unknown fields for strict endpoint contracts; existing retrieve behavior is a known inconsistency, not a preferred convention.
- Admin mutations require session authentication and CSRF; destructive operations require server-side authorization and explicit confirmation proportional to impact.
- Return `Cache-Control: no-store` for credentials, admin pages, and sensitive API responses.
- Never expose exception details, SQL/provider messages, filesystem paths, credentials, or stack traces.

## Validation

- Treat all HTTP, CLI, environment, file, database JSON, URL, DNS, and provider responses as untrusted.
- Normalize once at the boundary, then pass typed values inward.
- Centralize duplicated list/query parsing in dedicated parser/value objects.
- Use allowlists for sortable SQL columns, source types, status values, model options, and request fields.
- Validate both application limits and platform limits (for example, upload size in PHP/web server and application).
- Use `mb_*` for user-facing character limits and `strlen` where byte limits/reservations are intentional.

## Database and transactions

- Use PDO prepared statements with native prepares; never interpolate untrusted values.
- Dynamic SQL identifiers must come from enum/allowlist mappings.
- Select only required columns, especially excluding hashes and `LONGTEXT` from list projections.
- Filter before pagination and use deterministic secondary ordering by ID.
- Multi-step state changes use transactions and verify affected-row counts.
- Follow consistent lock ordering in concurrent workflows.
- Store timestamps in UTC using `UTC_TIMESTAMP(6)`.
- Do not edit applied migrations. Add a forward migration and test a completely empty schema plus idempotent second run.

## Error handling

- Exceptions represent exceptional failure; do not silently catch them.
- Separate validation/client errors, controlled domain failures, provider categories, and unexpected infrastructure errors.
- Worker document/provider failures may retry or fail the job. Unexpected queue/database/process failures terminate for supervisor restart.
- Preserve the previous active source revision whenever new processing fails.
- Provider-facing controllers translate known errors into safe stable codes; never return upstream response bodies.
- A catch that exists only for cleanup/reconciliation must rethrow the original failure unless cleanup itself indicates a more serious consistency failure.

## Logging and privacy

- Use the injected PSR logger; include request/worker/job/source identifiers and numeric timing/usage where useful.
- Do not log full questions, request bodies, retrieved content, answers, bearer keys, provider credentials, auth headers, raw IPs, or uploaded document contents.
- `SecretRedactionProcessor` is defense in depth, not permission to log secrets.
- Put safe actionable messages in job/admin state; put detailed exceptions only in redacted operational logs.
- API Activity is a privacy-minimized metadata store, not an application log or transcript.

## Templates, HTML, CSS, and JavaScript

- Escape every untrusted value with the view-provided escape helper.
- Keep business rules out of templates; simple formatting/branching is acceptable.
- Preserve progressive enhancement: forms and navigation work without a JavaScript framework.
- Use vanilla JavaScript and the existing design tokens/components.
- Do not introduce a CSS framework or build pipeline without an architectural decision.
- Keep static assets under `public/assets/`; no compilation is currently required.

## Provider and RAG conventions

- Model names and credentials are configuration, never constants.
- Provider adapters must validate response shape and map authentication, configuration, rate, timeout, malformed, and general failures.
- Automated tests use fakes and never call paid APIs.
- Preserve instruction/data separation in grounded prompts.
- Retrieval must remain limited to enabled, non-deleted, active, ready, model/dimension-compatible chunks.
- Changes to similarity, chunking, token estimation, context selection, or prompts require evaluation fixtures, not only unit tests.

## Testing philosophy

- Test security invariants and failure behavior, not just happy paths.
- Unit tests use in-memory repositories and provider/network/OCR fakes.
- Integration tests use an explicitly disposable database whose name ends in `_test`; the harness creates and drops it.
- Keep tests deterministic, isolated, fast, and free from production credentials/network calls.
- Every bug fix adds a regression test at the lowest layer that proves it and, where wiring matters, an HTTP/DB test.
- Important areas: authentication/passwords, sessions/CSRF, API keys, request validation, SSRF, upload signatures, chunking, similarity, activation, queue claims/recovery, retention/purge privacy, quota no-call gates, and complete bootstrap routing.
- PHPUnit warnings fail the suite.

## Static analysis and quality gate

Current PHPStan level is 5 with one process and `treatPhpDocTypesAsCertain: false`. New code must introduce no new errors. Raise the level gradually in focused changes rather than adding a broad baseline.

Required checks:

```bash
composer validate --strict
composer audit
find app bootstrap bin config database public resources tests -name '*.php' -exec php -l {} \;
composer analyse
composer test
```

CI repeats these on PHP 8.3/8.4 with MySQL 8.4.

## Documentation expectations

Update documentation in the same change when modifying:

- an API contract or error code;
- environment variables/defaults;
- a migration/table/index/retention policy;
- operational commands, worker behavior, deployment requirements;
- security/privacy behavior;
- an architecture decision or roadmap status.

