# Deployment

## Required services and software

- PHP 8.3+ CLI and FPM with `curl`, `dom`, `fileinfo`, `iconv`, `intl`, `json`, `mbstring`, `pdo`, and `pdo_mysql`.
- Composer 2.
- MySQL 8 (8.4 is CI-tested). MariaDB requires release-specific compatibility verification.
- Apache or Nginx configured with `public/` as the document root.
- OCRmyPDF/Tesseract when `PDF_OCR_ENABLED=true`.
- Outbound HTTPS to OpenAI and to administrator-approved public URL sources.
- systemd or cron for ingestion, plus cron/system scheduler for maintenance.

Redis is not required or used. There is no front-end asset compilation; CSS, JavaScript, SVGs, and fonts under `public/assets/` are committed and served directly.

## Local development

```bash
composer install
cp .env.example .env
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
```

Put the generated value in `APP_SECRET`, create the database/user, configure `DB_*`, then:

```bash
php bin/migrate.php
php bin/create-admin.php
php -S 127.0.0.1:8080 -t public public/index.php
```

Create a least-privilege MySQL account:

```sql
CREATE DATABASE rag_app CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'rag_user'@'localhost' IDENTIFIED BY 'replace-with-a-strong-password';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, DROP
    ON rag_app.* TO 'rag_user'@'localhost';
FLUSH PRIVILEGES;
```

Check health at `http://127.0.0.1:8080/api/v1/health` and sign in at `/admin/login`.

### Laravel Herd

Herd must serve the application's `public/` directory. Example:

```dotenv
APP_URL=https://ragserver.test
SESSION_SECURE_COOKIE=auto
```

Visiting the site root redirects to `/admin`; unauthenticated users then redirect to `/admin/login`. A 404 at the root usually means Herd is serving the repository root or lacks front-controller routing.

### Optional Docker database

`docker-compose.yml` provides MySQL 8.4 only; PHP runs on the host:

```bash
docker compose up -d database
```

The included `rag_password`/root values are local-development defaults and must never be reused in production.

## Environment variables

`.env.example` is the authoritative inventory. `.env` is git-ignored. Values below are defaults or required behavior, not production recommendations in every environment.

### Application and database

| Variable | Default / requirement | Meaning |
| --- | --- | --- |
| `APP_ENV` | `production` in config | Environment label; use `development`, `testing`, or `production`. |
| `APP_DEBUG` | `false` | Parsed but HTTP errors remain safe in all modes; planned for cleanup. |
| `APP_URL` | `http://localhost:8080` | Canonical operator-facing URL. |
| `APP_SECRET` | required, at least 32 characters | HMAC/session-adjacent application secret; preserve across releases. |
| `APP_TIMEZONE` | `UTC` | Admin date input/display timezone; database remains UTC. |
| `DB_HOST`, `DB_PORT` | `127.0.0.1`, `3306` | Database endpoint. |
| `DB_DATABASE` | `rag_app` | Schema name. |
| `DB_USERNAME`, `DB_PASSWORD` | required | Least-privilege application credentials. |
| `FILESYSTEM_PATH` | `storage/sources` | Private source root; must resolve outside `public/`. |
| `MAX_UPLOAD_SIZE_MB` | `20` | Application upload ceiling; PHP/web limits must be at least as high. |
| `LOG_LEVEL` | `info` in config (`debug` in example) | Monolog threshold. |

### Administrator/session security

| Variable | Default | Meaning |
| --- | --- | --- |
| `SESSION_NAME` | `rag_admin_session` | Session cookie name. |
| `SESSION_IDLE_MINUTES` | `120` | Idle expiration. |
| `SESSION_REGENERATE_MINUTES` | `15` | Periodic ID regeneration. |
| `SESSION_SECURE_COOKIE` | `auto` | `always`, `never`, or direct-request `auto`; use `always` in production. |
| `LOGIN_MAX_ATTEMPTS` | `5` | Login throttle limit. |
| `LOGIN_WINDOW_MINUTES` | `15` | Login throttle window. |

### Queue and document safety

| Variable | Default | Meaning |
| --- | --- | --- |
| `JOB_MAX_ATTEMPTS` | `3` | Attempts per ingestion job. |
| `JOB_RETRY_BASE_SECONDS` | `30` | Initial exponential retry delay. |
| `JOB_RETRY_MAX_SECONDS` | `3600` | Retry delay cap. |
| `JOB_ABANDONED_TIMEOUT_MINUTES` | `45` | Worker reservation expiry; validated against maximum processing time. |
| `JOB_POLL_SECONDS` | `2` | Long-running worker idle polling. |
| `INGESTION_MAXIMUM_EXTRACTED_CHARACTERS` | `500000` | Hard normalized/extracted text ceiling. |
| `RAG_MAXIMUM_CHUNKS_PER_DOCUMENT` | `256` | Hard chunk ceiling before embedding. |
| `PDF_MAXIMUM_PAGES` | `250` | PDF/OCR page ceiling. |
| `RAG_CHUNK_SIZE_TOKENS` | `500` | Estimated target chunk size. |
| `RAG_CHUNK_OVERLAP_TOKENS` | `75` | Estimated overlap. |
| `RAG_MINIMUM_CHUNK_TOKENS` | `20` | Tiny chunk suppression threshold. |

### URL ingestion

| Variable | Default |
| --- | --- |
| `URL_CONNECT_TIMEOUT_SECONDS` | `5` |
| `URL_REQUEST_TIMEOUT_SECONDS` | `20` |
| `URL_MAXIMUM_REDIRECTS` | `5` |
| `URL_MAXIMUM_RESPONSE_BYTES` | `5242880` |
| `URL_USER_AGENT` | `RAGServer/1.0` |

Connection timeout must not exceed request timeout. Redirect destinations are independently validated.

### OCR

| Variable | Default |
| --- | --- |
| `PDF_OCR_ENABLED` | `true` |
| `PDF_OCR_BINARY` | `ocrmypdf` |
| `PDF_OCR_LANGUAGES` | `eng` (`+` separated) |
| `PDF_OCR_PROCESS_TIMEOUT_SECONDS` | `900` |
| `PDF_OCR_PAGE_TIMEOUT_SECONDS` | `120` |
| `PDF_OCR_JOBS` | `1` |
| `PDF_OCR_MAX_OUTPUT_SIZE_MB` | `100` |
| `PDF_OCR_ROTATE_PAGES` | `true` |
| `PDF_OCR_DESKEW` | `true` |

Use an absolute binary path under systemd/cron. Page timeout cannot exceed process timeout. OCR starts only when native extraction finds no text.

### OpenAI and RAG

| Variable | Default / requirement |
| --- | --- |
| `EMBEDDING_PROVIDER` | `openai` (only implemented value) |
| `LLM_PROVIDER` | `openai` (only implemented value) |
| `OPENAI_API_KEY` | required for embeddings/chat |
| `OPENAI_BASE_URL` | `https://api.openai.com` |
| `OPENAI_EMBEDDING_MODEL` | required; no hardcoded model |
| `OPENAI_EMBEDDING_DIMENSIONS` | optional model default |
| `OPENAI_CHAT_MODEL` | required; no hardcoded model |
| `OPENAI_CHAT_REASONING_EFFORT` | optional; example `low` |
| `OPENAI_CHAT_MAX_OUTPUT_TOKENS` | `600` |
| `OPENAI_CHAT_MAXIMUM_RETRIES` | `0` |
| `OPENAI_CONNECT_TIMEOUT_SECONDS` | `10` |
| `OPENAI_REQUEST_TIMEOUT_SECONDS` | `60` |
| `OPENAI_MAXIMUM_RETRIES` | `3` for embedding client |
| `RAG_EMBEDDING_BATCH_SIZE` | `64` |
| `RAG_RETRIEVAL_DEFAULT_TOP_K` | `8` |
| `RAG_RETRIEVAL_MAXIMUM_TOP_K` | `20` |
| `RAG_RETRIEVAL_MINIMUM_SIMILARITY` | `0.20` |
| `RAG_RETRIEVAL_MAXIMUM_QUERY_CHARACTERS` | `4000` |
| `RAG_CHAT_TOP_K` | `5` |
| `RAG_CHAT_MAXIMUM_TOP_K` | `8` |
| `RAG_CHAT_CONTEXT_MAX_TOKENS` | `4000` estimated |
| `RAG_CHAT_HISTORY_MAX_TOKENS` | `1000` estimated completed-turn history for customer-facing chatbot execution |
| `RAG_CHAT_MAXIMUM_QUESTION_CHARACTERS` | `4000` |
| `API_MAXIMUM_BODY_BYTES` | `65536` |

Changing embedding model or dimensions requires rebuilding active embeddings:

```bash
php bin/embed-chunks.php --rebuild
```

### API limits, quotas, and retention

| Variable | Default |
| --- | --- |
| `API_RATE_LIMIT_WINDOW_SECONDS` | `60` |
| `API_RATE_LIMIT_PER_KEY` | `60` |
| `API_RATE_LIMIT_PER_IP` | `120` |
| `API_CHAT_RATE_LIMIT_PER_KEY` | `10` |
| `API_CHAT_RATE_LIMIT_PER_IP` | `20` |
| `CHATBOT_PUBLIC_RATE_LIMIT_WINDOW_SECONDS` | `60` |
| `CHATBOT_PUBLIC_CONFIG_RATE_LIMIT_PER_IP` | `120` |
| `CHATBOT_PUBLIC_CONFIG_RATE_LIMIT_PER_CHATBOT` | `600` |
| `CHATBOT_PUBLIC_SESSION_RATE_LIMIT_PER_IP` | `20` |
| `CHATBOT_PUBLIC_SESSION_RATE_LIMIT_PER_CHATBOT` | `120` |
| `CHATBOT_PUBLIC_MESSAGE_RATE_LIMIT_PER_IP` | `30` |
| `CHATBOT_PUBLIC_MESSAGE_RATE_LIMIT_PER_CHATBOT` | `300` |
| `CHATBOT_PUBLIC_MESSAGE_RATE_LIMIT_PER_SESSION` | `20` |
| `CHATBOT_PUBLIC_PENDING_TIMEOUT_SECONDS` | `120` (allowed 30–3600) |
| `CHATBOT_CONVERSATION_PURGE_BATCH_SIZE` | `500` |
| `CHATBOT_INTEGRATION_RATE_LIMIT_PER_CREDENTIAL` | `120` |
| `CHATBOT_INTEGRATION_RATE_LIMIT_PER_IP` | `240` |
| `PROVIDER_GLOBAL_DAILY_TOKEN_LIMIT` | `1000000` |
| `PROVIDER_GLOBAL_MONTHLY_TOKEN_LIMIT` | `10000000` |
| `PROVIDER_API_KEY_DAILY_TOKEN_LIMIT` | `100000` |
| `PROVIDER_API_KEY_MONTHLY_TOKEN_LIMIT` | `1000000` |
| `PROVIDER_QUOTA_RESERVATION_TTL_SECONDS` | `900` (allowed 60–3600) |
| `API_REQUEST_LOG_RETENTION_DAYS` | `30`; accepted: 0/30/90/180/365 |
| `API_REQUEST_LOG_PURGE_BATCH_SIZE` | `1000` |

A provider token limit of `0` means unlimited. Use explicit finite production limits plus the OpenAI project hard budget.

Provider construction enforces an application output range of `64..32768`, retry range of `0..10`, positive timeouts, an HTTPS base URL, supported provider/reasoning values, and required model/credential values. These checks do not prove that the selected provider model supports the configured combined context/output envelope, nor can they inspect the provider project's rate or monetary limits. Complete the external model-limit and project-hard-budget checks in [Chatbot analytics and operational readiness](customer-facing-chatbot/analytics-and-operational-readiness.md#provider-hard-limit-validation).

`ANTHROPIC_API_KEY` and `ANTHROPIC_MODEL` exist as placeholders but no Anthropic implementation is available.

## Production Apache/PHP-FPM

Install PHP-FPM/CLI, required extensions, Apache, and OCRmyPDF. `proc_open()` must remain enabled when OCR is on.

Example virtual host:

```apache
<VirtualHost *:80>
    ServerName ragserver.example.com
    DocumentRoot /var/www/ragserver/public

    <Directory /var/www/ragserver/public>
        Options -Indexes
        AllowOverride None
        Require all granted
        DirectoryIndex index.php
        FallbackResource /index.php
    </Directory>

    <FilesMatch "\.php$">
        SetHandler "proxy:unix:/run/php/php8.3-fpm.sock|fcgi://localhost/"
    </FilesMatch>
</VirtualHost>
```

Add HTTPS and redirect HTTP. Use `SESSION_SECURE_COOKIE=always`. Do not deploy behind an unreviewed TLS-terminating proxy until trusted-proxy support is implemented, or ensure the application receives the original HTTPS connection and client IP.

Forwarded headers are ignored even when present. A conventional TLS-terminating proxy is therefore a release blocker: `SESSION_SECURE_COOKIE=always` does not repair collapsed visitor-IP controls or same-origin reconstruction. See the explicit [trusted-proxy deployment gate](customer-facing-chatbot/analytics-and-operational-readiness.md#trusted-proxy-deployment-gate).

PHP-FPM baseline for 20 MB uploads:

```ini
upload_max_filesize = 20M
post_max_size = 22M
display_errors = Off
expose_php = Off
```

## Filesystem ownership

The web/worker user needs writes only to source storage, logs, and cache. Code should be read-only; `.env` should be mode `0640` or stricter.

```bash
sudo mkdir -p /var/lib/ragserver/sources
sudo chown -R www-data:www-data /var/lib/ragserver/sources
sudo chown -R www-data:www-data /var/www/ragserver/storage/logs /var/www/ragserver/storage/cache
sudo chgrp www-data /var/www/ragserver/.env
sudo chmod 640 /var/www/ragserver/.env
```

Set `FILESYSTEM_PATH=/var/lib/ragserver/sources` and ensure it exists before boot.

## Build and release process

There is no asset build. A production release consists of source checkout/artifact deployment, Composer install, migration, optional embedding backfill, worker restart, and PHP-FPM reload.

```bash
sudo systemctl stop ragserver-worker
composer install --no-dev --optimize-autoloader
php bin/migrate.php
php bin/embed-chunks.php
sudo systemctl start ragserver-worker
sudo systemctl reload php8.3-fpm
```

Before release, run the quality commands from [Developer guide](developer-guide.md#quality-gate). Back up before migrations. `create-admin.php` is initial setup only.

Use the chatbot-specific [release procedure and forward-repair runbook](customer-facing-chatbot/analytics-and-operational-readiness.md#release-procedure) when customer-facing routes are enabled.

## Ingestion worker

Prefer the reviewed `deploy/systemd/ragserver-worker.service`. Adjust every path and `ReadWritePaths` entry to the installation. It runs PHP with 512 MB, sets cgroup soft/hard memory controls, uses a private temporary directory, makes the system/application read-only except declared writes, and restarts on failure.

```bash
sudo install -o root -g root -m 0644 deploy/systemd/ragserver-worker.service /etc/systemd/system/
sudo systemd-analyze verify /etc/systemd/system/ragserver-worker.service
sudo systemctl daemon-reload
sudo systemctl enable --now ragserver-worker
sudo journalctl -u ragserver-worker -f
```

Start with one worker because OCR and embedding batches can be expensive. Multiple workers are queue-safe, but benchmark provider and host capacity first.

For low volume, cron can run one job per invocation instead of systemd:

```cron
* * * * * www-data cd /var/www/ragserver && /usr/bin/flock -n storage/cache/worker.lock /usr/bin/php -d memory_limit=512M bin/process-jobs.php --once >/dev/null
```

Do not run both ingestion modes initially.

## Scheduled maintenance

Recommended `/etc/cron.d/ragserver-maintenance` when systemd runs ingestion:

```cron
SHELL=/bin/sh
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin

*/10 * * * * www-data cd /var/www/ragserver && /usr/bin/php bin/recover-jobs.php >/dev/null
15 2 * * * www-data cd /var/www/ragserver && /usr/bin/php bin/prune-api-requests.php >/dev/null
30 2 * * * * www-data cd /var/www/ragserver && /usr/bin/php bin/prune-chatbot-conversations.php >/dev/null
```

The commands are idempotent. API Activity and chatbot conversation retention use separate database advisory locks. Preserve stderr or alert on non-zero exit. No ingestion-job retention command exists yet.

## Backups and restoration

A complete backup is a coordinated snapshot of:

1. MySQL database;
2. `FILESYSTEM_PATH` uploaded originals;
3. `.env` stored separately and securely.

For strict consistency, stop the worker and prevent source mutations during database/filesystem snapshots. Logs are optional operational data. Backups retain records/files already purged from the live application until backup expiry.

Restoration drill:

1. Restore to a separate database/storage path.
2. Verify permissions and environment.
3. Run `php bin/migrate.php`.
4. Check source-version/file consistency and health.
5. Run `php bin/embed-chunks.php` if active embeddings are absent/incompatible.
6. Process a safe test source and call retrieval/chat with a test API key.
7. Only then use the restored system for production.

## Monitoring

At minimum alert on:

- `/api/v1/health` non-200 or database degraded;
- systemd worker inactive/restart bursts;
- failed/pending/old processing jobs;
- maintenance command failure;
- provider authentication/rate/timeout/error rates;
- quota remaining and OpenAI project spend;
- PHP/OCR memory kills and worker duration;
- MySQL connections, slow queries, table/index size, disk, backups;
- private source/log/cache filesystem capacity;
- API status/latency and unusual 401/429 volume.

No metrics exporter is implemented; current sources are health JSON, dashboard aggregates, API Activity, Monolog files, systemd journal, and database monitoring.

The administrator chatbot analytics view supplies bounded, content-free session/chatbot/token summaries. The implemented-versus-future signal inventory and request-ID troubleshooting matrix are maintained in [Analytics and operational readiness](customer-facing-chatbot/analytics-and-operational-readiness.md#monitoring-inventory).

## CI/CD

`.github/workflows/quality.yml` runs on every push and pull request for PHP 8.3 and 8.4 with MySQL 8.4. It installs dependencies, validates and audits Composer, lints PHP, runs PHPStan level 5, and executes all PHPUnit tests including clean-schema and HTTP smoke tests.

CI is a quality pipeline only. It does not deploy, run production migrations, rotate workers, or verify backups. Add environment-specific deployment automation only after secrets, approvals, rollback, migration sequencing, and health gates are defined.

## Production acceptance checklist

- [ ] `public/` is the only document root and indexes are off.
- [ ] HTTPS enforced; production receives correct scheme/IP; secure cookies set to `always`.
- [ ] Strong stable `APP_SECRET`; `.env` permissions reviewed.
- [ ] MySQL private and least privilege; migrations backed up and applied.
- [ ] Source/log/cache permissions correct; storage outside public.
- [ ] OpenAI project hard budget plus application quotas configured.
- [ ] Selected provider model supports the configured context/output envelope and provider rate limits are monitored.
- [ ] OCR binary/languages/timeouts verified with representative PDFs.
- [ ] Document limits and worker timeout relationship accepted.
- [ ] systemd worker installed, enabled, and monitored—or cron one-shot chosen, not both.
- [ ] recovery and API Activity retention scheduled and monitored.
- [ ] chatbot conversation expiry/retention scheduled, run once, and monitored.
- [ ] Database + filesystem backup and restore test completed.
- [ ] CI green; no real provider calls in automated tests.
- [ ] Representative retrieval/citation/unsupported-answer evaluation completed.
