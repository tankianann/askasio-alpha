# Developer guide

## First-day orientation

Read in this order:

1. [Architecture](architecture.md)
2. [Architecture decisions](decisions.md)
3. [API](api.md) or [Database](database.md), depending on your task
4. [Coding standards](coding-standards.md)
5. [Roadmap](roadmap.md)
6. [Knowledge base](knowledge-base.md)

The application is framework-free. Begin at `public/index.php`, then `bootstrap/app.php`, `config/routes.php`, the target controller/service/repository, and its tests. For ingestion begin at `bootstrap/worker.php` and `DocumentIngestionProcessor`.

## Getting started

Requirements and full configuration are in [Deployment](deployment.md). Minimal local workflow:

```bash
composer install
cp .env.example .env
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
# configure APP_SECRET, DB_*, OpenAI models/key, storage, and optional OCR
php bin/migrate.php
php bin/create-admin.php
php -S 127.0.0.1:8080 -t public public/index.php
```

In another terminal:

```bash
php bin/worker.php
```

Or use `php bin/process-jobs.php --once` for a single queued job.

## Common commands

| Command | Purpose |
| --- | --- |
| `composer install` | Install exact locked dependencies. |
| `composer migrate` | Apply pending migrations. |
| `composer create-admin` | Create the only administrator. |
| `composer worker` | Run persistent ingestion worker. |
| `composer process-jobs` | Process at most one available job. |
| `composer recover-jobs` | Recover expired worker reservations. |
| `composer embed-chunks` | Backfill incompatible/missing active embeddings. |
| `php bin/embed-chunks.php --rebuild` | Clear and rebuild all active embeddings. |
| `composer retrieve -- "query"` | CLI retrieval diagnostic; direct script also supports `--top-k`/`--min-similarity`. |
| `composer prune-api-requests` | Apply configured API Activity retention. |
| `composer prune-chatbot-conversations` | Expire due sessions and apply copied chatbot conversation retention. |
| `composer test` | Run PHPUnit; DB tests skip unless configured. |
| `composer analyse` | Run PHPStan level 5. |

## Typical feature workflow

1. Read the relevant docs and implementation. Check [Roadmap](roadmap.md#future-improvements) so you do not re-open an intentionally accepted trade-off accidentally.
2. State one bounded objective and explicit non-goals. For architecture/security/schema/API changes, add or update an ADR first or in the same commit.
3. Identify affected boundaries: route/controller, parser/value object, service, repository/schema, view, CLI/worker, documentation.
4. Write or update tests before/with implementation. Use fakes for external systems; use the disposable database for SQL/locking/migration behavior.
5. Implement the smallest coherent micro-milestone. Do not mix unrelated refactoring with behavior changes.
6. Run focused tests while iterating, then the full quality gate.
7. Review privacy, logs, error messages, transaction/rollback behavior, concurrency, limits, and production configuration.
8. Update docs and `.env.example` in the same change.
9. Hand off with behavior, files, migrations/commands, verification results, limitations, and suggested commit message.

## Adding an admin feature

1. Add route under the protected `/admin` group.
2. Use session admin context; state mutations automatically pass through CSRF middleware but still need controller/domain authorization.
3. Parse/validate input outside templates.
4. Put workflows in a service and SQL in repository/query-builder classes.
5. Render escaped values through `ViewRenderer` and preserve no-JS form behavior.
6. Add flash success/failure feedback and safe empty states.
7. For a growing list, use `PageRequest`/`PaginatedResult`, URL query state, SQL filtering/count/sort allowlists, and deterministic ordering.

## Adding or changing an API endpoint

1. Decide whether the change is backward-compatible within `/api/v1`.
2. Register it in `config/routes.php` with request logging, API authentication, and an appropriate rate-limit namespace.
3. Use `JsonRequestParser`; reject unknown fields and validate length/type/range.
4. Add provider quota preflight before paid calls and reconcile every post-reservation success/failure path.
5. Use the common JSON error envelope and safe provider error categories.
6. Ensure Activity records only numeric usage/metadata.
7. Add no-provider-call tests for authentication/rate/quota/validation gates where relevant.
8. Update [API reference](api.md).

## Changing the database

1. Add a new timestamped migration; never edit an applied migration.
2. Prefer additive/backward-compatible deploy sequencing.
3. Add foreign keys/checks/unique constraints and measured indexes where appropriate.
4. Test a clean schema, a second idempotent migration pass, and repository behavior against MySQL.
5. Consider DDL auto-commit and write a forward repair plan.
6. Update [Database](database.md) and deployment/backup notes.

## Changing ingestion/RAG behavior

- Preserve the previous active revision on all failures.
- Enforce safety limits before paid embeddings.
- Validate worker timeout math when changing limits/timeouts/retries/batch sizes.
- Keep extraction metadata (title, URL/file, headings, pages, offsets) through chunks/citations.
- Changing embedding model/dimensions requires backfill/rebuild behavior and operator warning.
- Prompt, threshold, token, chunk, or ranking changes require representative retrieval/answer evaluation beyond unit tests.

## Git conventions

The historical repository uses a `master` branch and milestone/Conventional-Commit-like messages. Adopt short-lived branches for future work and keep commits reviewable.

Suggested branch names:

```text
feature/trusted-proxies
fix/retrieve-provider-errors
chore/job-retention
docs/rag-evaluation
```

Suggested commit format:

```text
feat(api): add trusted proxy resolution
fix(worker): preserve job state on provider timeout
chore(db): add terminal job retention index
docs: update deployment proxy topology
```

One logical micro-milestone should normally be one commit. Never commit `.env`, production source files, logs, dumps, credentials, or generated test/cache artifacts.

## Pull request expectations

A PR should include:

- problem and desired behavior;
- explicit scope/non-goals;
- design/ADR and trade-offs for significant decisions;
- migrations, configuration, deployment, and rollback/forward-fix notes;
- tests performed and exact results;
- privacy/security/concurrency/performance review;
- screenshots only when UI behavior changes;
- documentation updates;
- remaining limitations/follow-up tasks.

Review checklist:

- [ ] Inputs and provider/database JSON are validated.
- [ ] SQL is prepared; dynamic columns are allowlisted; queries are bounded.
- [ ] Multi-step writes and locks have clear transaction semantics.
- [ ] Admin mutations are authenticated/CSRF-protected; destructive scope is confirmed.
- [ ] Secrets/content/raw IPs are absent from errors, logs, HTML, and Activity.
- [ ] Paid calls are behind validation/auth/rate/quota gates.
- [ ] Source activation and worker failure invariants remain intact.
- [ ] API/schema/config compatibility is documented.
- [ ] Unit and appropriate MySQL/HTTP tests pass with no paid network calls.
- [ ] Deployment and documentation are updated.

## Quality gate

```bash
composer validate --strict
composer audit
find app bootstrap bin config database public resources tests -name '*.php' -exec php -l {} \;
composer analyse
composer test
```

To enable real database tests locally, use an expendable database ending in `_test`. The harness will drop and recreate it:

```bash
TEST_DB_HOST=127.0.0.1 \
TEST_DB_PORT=3306 \
TEST_DB_DATABASE=rag_app_test \
TEST_DB_USERNAME=root \
TEST_DB_PASSWORD=local-test-password \
composer test
```

Never point these variables at development or production data.

## Troubleshooting

### Site returns 404

The web server must use `public/` as document root and send missing paths to `public/index.php`. With Herd, re-link/select the public directory. A `HEAD` request may not match a GET route in the custom router; use GET for route checks.

### “Something went wrong”

Copy the `X-Request-ID`, inspect `storage/logs/application-*.log`, and check the latest migration. HTTP responses intentionally hide the cause.

### Database connection denied

Check whether `DB_HOST=127.0.0.1` requires a MySQL account for `'user'@'127.0.0.1'` rather than `'user'@'localhost'`. Verify PDO MySQL in both CLI and FPM.

### Upload rejected before application validation

Raise PHP-FPM/web `upload_max_filesize` and `post_max_size` above `MAX_UPLOAD_SIZE_MB`, then restart/reload FPM. Keep the application limit finite.

### Jobs remain pending

Run `php bin/process-jobs.php --once`, inspect exit code/log, verify OpenAI/OCR/storage configuration, and ensure the worker service is active. The worker refuses invalid timeout relationships.

### Worker repeatedly restarts

Inspect systemd journal and application logs. Unexpected infrastructure failures intentionally exit. Verify DB reachability, writable paths, cgroup/PHP memory limits, OCR binary, and abandoned timeout math.

### PDF has no text

If OCR is enabled, confirm `PDF_OCR_BINARY`, `ocrmypdf --version`, Tesseract languages, `proc_open`, and systemd writable/private temp behavior. OCR currently triggers only when native extraction returns zero text.

### Retrieval returns no matches

Verify source is enabled, not deleted, active revision is `ready`, chunks have embeddings for the configured model and dimensions, and threshold is appropriate. Run `php bin/retrieve.php "query" --min-similarity=-1` for calibration, then rebuild embeddings if models changed.

### Chat says information is unavailable

This is expected when no chunk meets threshold/context constraints; no chat-generation call occurs. Debug retrieval separately before changing prompts.

### Quota rejects apparently small chat

Preflight reserves a worst-case allowance including maximum context, output, prompt overhead, and query bytes. It reconciles to actual use after completion. Lower concurrency, raise a reviewed limit, or reduce context/output configuration; do not bypass the gate.

### CSS appears wrong

There is no asset compilation. Hard-refresh browser caches, verify all committed files under `public/assets/css/` are deployed, and confirm the web server serves existing assets directly instead of routing them to the front controller.

## Starting a future fresh-chat milestone

Provide the engineer/agent with:

- repository path and current commit;
- the one milestone objective and non-goals;
- instruction to read `docs/README.md`, `architecture.md`, `decisions.md`, `roadmap.md`, and relevant API/database/deployment docs first;
- requirement to inspect current code/tests rather than assume the docs are perfect;
- required quality gate and prohibition on paid provider calls in tests;
- expected micro-milestone/commit-sized implementation and documentation updates.
