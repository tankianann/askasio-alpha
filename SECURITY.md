# Security and production review

This document records the Milestone 9 security baseline. It is an operational checklist, not a guarantee that a deployment is secure under every hosting topology.

## Deployment boundary

- Serve only `public/`; deny directory indexes and never expose the repository root.
- Use HTTPS, `APP_ENV=production`, `APP_DEBUG=false`, and `SESSION_SECURE_COOKIE=always`.
- Keep `.env`, database backups, source files, logs, and cache outside the public web root with least-privilege ownership and modes.
- Run the web process and ingestion worker as unprivileged accounts. Do not run Composer, PHP-FPM, or the worker as root.
- Restrict MySQL to localhost or a private network and grant only the schema permissions required by deployment and runtime policy.
- Allow outbound HTTPS only where practical. URL ingestion intentionally supports arbitrary public HTTP(S) sites; OpenAI providers require access to their configured API origin.

## Application controls reviewed

- Administrator passwords use PHP password hashing and verification; plaintext passwords are never stored.
- Administrator state changes require authenticated sessions and CSRF tokens. Login attempts are throttled by normalized username and client IP identifiers.
- API keys are generated from secure random bytes, shown once, and stored only as a visible prefix plus SHA-256 hash. Authentication comparisons use constant-time equality.
- API requests are size/type/schema validated and rate-limited by API key and HMAC-derived IP identifier.
- SQL uses prepared PDO statements. Templates escape untrusted output, including extracted text and provider/job errors.
- Uploaded files are stored under randomized server names outside `public/` and validated by size, extension, detected MIME type, and signature or UTF-8 content rules.
- URL ingestion permits only HTTP(S), rejects local/private/reserved destinations, validates DNS results before connection, pins the validated address, disables proxy inheritance, and repeats validation after redirects.
- Provider secrets and bearer tokens are redacted from application logs. API request records omit full questions, answers, raw IP addresses, and credentials.
- Source activation, chunks, and embeddings commit together. A failed or older out-of-order version cannot displace the current active version.
- Permanent source deletion requires prior soft deletion, exact-name confirmation, CSRF protection, and no pending/processing jobs.
- Manual API Activity deletion requires administrator authentication, CSRF validation, a server-side short-lived scope snapshot, count review, and an exact confirmation phrase. Newer records beyond the reviewed maximum ID are excluded.
- HTTPS responses use HSTS. Administrator responses disable caching. CSP blocks framing and limits scripts, styles, images, objects, forms, and base URLs.

## Operations checklist

- Preserve `APP_SECRET` across releases and keep API/provider keys in a secrets manager or protected environment file.
- Rotate provider and application API keys on a documented schedule and immediately after suspected disclosure.
- Back up MySQL and `FILESYSTEM_PATH` together; test restoration away from production.
- Monitor health, worker status, failed jobs, provider errors/rate limits, disk capacity, and application logs.
- Set `JOB_ABANDONED_TIMEOUT_MINUTES` above the longest legitimate ingestion run and schedule `bin/recover-jobs.php` as a fallback.
- Choose an explicit API Activity retention period and schedule `bin/prune-api-requests.php`; align database-backup retention with the same privacy requirements.
- Apply OS, PHP, web server, MySQL, OCRmyPDF/Tesseract, and Composer security updates through a tested release process.
- Run `composer audit`, the test suite, PHP syntax checks, and `composer validate --strict` before production releases.
- Review trusted-proxy handling before deploying behind a TLS-terminating proxy. The application intentionally does not trust forwarded headers by default.

## Incident response

If compromise is suspected, isolate the host, preserve relevant logs, revoke application API keys, rotate provider/database credentials and `APP_SECRET` as appropriate, and restore from a verified clean backup. Rotating `APP_SECRET` invalidates correlation with historical HMAC-derived rate-limit and IP identifiers. Review source files as untrusted input even though they are stored outside the web root.
