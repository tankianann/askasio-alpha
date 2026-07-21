# Security

## Trust boundaries

- The public internet may reach only `public/` through HTTPS.
- Admin browsers authenticate with a secure PHP session and CSRF token.
- External applications authenticate with hash-only bearer API keys.
- Public chatbot browsers are restricted by immutable exact-origin publication policy; created sessions receive a separate hash-only bearer token.
- URL sources and uploaded files are untrusted input.
- Retrieved text is untrusted data, including when inserted into an AI prompt.
- OpenAI and public URL origins are external services; TLS and bounded network operations are mandatory.
- OCRmyPDF/Tesseract is a local subprocess operating on untrusted PDFs under time/size/process limits.

## Implemented controls

### Authentication and sessions

- Argon2id administrator password hashes when supported; plaintext is never stored.
- Generic login failures, HMAC-based login throttling, session regeneration, idle timeout, strict cookie-only sessions.
- `HttpOnly`, `SameSite=Lax`, and configurable/request-aware `Secure` cookies.
- CSRF on every state-changing administrator form, including logout and destructive actions.
- API keys contain 256 random bits, are shown once, stored only as visible prefix + SHA-256 hash, and compared with `hash_equals()`.

### Requests and responses

- Prepared PDO statements and allowlisted sort/filter SQL.
- JSON media type, object shape, body size, field type/range validation.
- API rate limits by key/HMAC IP and public-chatbot creation limits by HMAC IP/chatbot, plus atomic provider-token budgets.
- UUIDv7 request IDs for client/log correlation.
- Safe production errors; no stack traces or raw exception messages in HTTP responses.
- CSP, MIME-sniffing protection, strict referrer policy, permissions policy, same-origin resource policy except explicitly CORS-authorized public API responses, no admin/public-session caching, and HSTS on direct HTTPS.

### Source ingestion

- Files stored outside `public/` using randomized server names.
- Upload size, extension, MIME, signature/UTF-8/binary checks; original names are metadata only.
- URL scheme/credential/host validation, public DNS resolution, private/reserved/metadata rejection, IP pinning, proxy bypass, redirect revalidation, TLS verification, response/type/timeout limits.
- Extracted-character, PDF-page, chunk-count, OCR-output, and process-time boundaries before embedding/activation.
- Extracted content is HTML-escaped in views.

### RAG and providers

- Prompt instructions are code-managed; question/context are JSON-encoded as untrusted data.
- Only supplied source excerpts may be used; unsupported answers return a fixed refusal.
- Citation references are validated for presence and range.
- Provider names/models/keys come from configuration; keys and sensitive headers are redacted from logs.
- OpenAI chat uses `store=false`; automatic chat retries default to zero to avoid duplicate ambiguous billing.
- Quota gate runs before provider access and locks global/key budgets atomically.

## API Activity privacy contract

Stored:

- request ID;
- numeric API-key ID;
- HMAC-derived IP identifier;
- HTTP method and path-only endpoint;
- status, duration, error category;
- numeric provider/quota usage;
- UTC timestamp.

Never stored:

- authorization headers, bearer tokens, provider secrets;
- raw IP addresses or URL query strings;
- request bodies or user questions;
- retrieved documents/chunks/citations;
- model prompts or responses.

The administrator list projection also omits the IP hash. There is intentionally no prompt-logging switch.

## Destructive operations

- Permanent source deletion requires prior soft deletion, exact source-name confirmation, CSRF, and no pending/processing jobs.
- Manual API Activity purge requires authentication, CSRF, an authoritative preview count, exact confirmation phrase, a short-lived server-side intent, and a maximum reviewed ID so new records are excluded.
- Scheduled/manual API Activity purges use a MySQL advisory lock and bounded batches.
- Backups may retain data removed from the live application and need separate retention.

## Deployment requirements

- Serve only `public/`; disable indexes.
- Require HTTPS, `APP_ENV=production`, `APP_DEBUG=false`, and `SESSION_SECURE_COOKIE=always`.
- Keep `.env`, backups, private sources, logs, and cache inaccessible to the web server except required least-privilege writes.
- Run PHP-FPM and the worker as unprivileged users; do not run application processes as root.
- Bind MySQL privately and use a least-privilege application account.
- Install systemd worker supervision and maintenance cron; alert on non-zero exits.
- Maintain a provider-account hard spend limit because ingestion embeddings are outside customer API-key quotas.

## Known security gaps

1. Forwarded headers are not trusted at all. Behind a TLS-terminating proxy, direct scheme/IP detection can be wrong. Do not simply trust `X-Forwarded-*`; implement the allowlisted proxy milestone first.
2. Login throttling by known username can contribute to remote admin denial of service.
3. Public health exposes database availability, although no connection detail.
4. Full-corpus PHP vector search is primarily performance risk but can amplify resource exhaustion as corpus size grows.
5. Citation range validation does not prove semantic faithfulness; RAG evaluation remains necessary.

## Secrets and rotation

- Preserve `APP_SECRET` across releases; rotating it changes HMAC correlation for login/IP identifiers.
- Rotate provider/API/database credentials after suspected disclosure.
- API keys cannot be recovered; create a replacement, update the client, then revoke/delete the old key.
- Never commit `.env`, production dumps, uploaded source files, or application logs.

## Incident response

1. Isolate the host and stop public/worker access if compromise is active.
2. Preserve system/application/database logs according to legal/operational requirements.
3. Revoke application keys and rotate provider/database credentials; rotate `APP_SECRET` if its confidentiality is in doubt.
4. Review private source files as untrusted input.
5. Restore code/database/files from a verified clean, coordinated backup.
6. Validate migrations, embeddings, worker, API authentication, and privacy behavior before reopening traffic.
7. Document scope, timeline, affected credentials/data, remediation, and follow-up controls.

See [Deployment](deployment.md) for the operational checklist and [Knowledge base](knowledge-base.md) for pitfalls.
