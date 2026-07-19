# Ask Asio

A framework-free PHP application for managing knowledge sources and answering grounded questions through a versioned REST API. Milestones 1–9 provide the application foundation, secure single-administrator interface, immutable source lifecycle, durable ingestion queue, extraction/chunking, OpenAI embeddings, cosine-similarity retrieval, authenticated application API keys, grounded chat with citations, and production hardening.

## Implemented functionality

### Foundation

- PHP 8.3+ front controller with `/public` as the only web root
- Immutable environment-backed configuration
- Lazy, exception-based PDO MySQL/MariaDB connection using native prepared statements
- Ordered PHP migration runner with a `schema_migrations` ledger
- Lightweight router with route parameters, groups, middleware, names, and all required HTTP verbs
- Request/response abstractions, request IDs, security headers, and safe JSON/HTML errors
- Rotating Monolog files outside the public root
- `GET /api/v1/health`, including non-sensitive database availability
- PHPUnit unit/integration-style tests that make no external requests

### Administrator authentication

- Single administrator stored in MySQL with normalized unique username
- Interactive `php bin/create-admin.php` command
- Argon2id password hashing when supported, with secure fallback and automatic rehashing
- Login and logout with generic authentication failures
- Strict, cookie-only PHP sessions with idle expiry and periodic ID regeneration
- Session ID regeneration after successful login and invalidation on logout
- `HttpOnly`, `SameSite=Lax`, and request-aware `Secure` cookies
- Session-bound 256-bit CSRF tokens on every state-changing administrator form
- MySQL-backed login throttling by HMAC-hashed username and IP
- Protected, responsive server-rendered administrator layout and dashboard

### Knowledge source management

- Versioned URL, Markdown, and PDF source records
- Immutable source-version origins with pending ingestion lifecycle state
- Source list, details, metadata, and complete version history
- Enable, disable, and soft-delete actions protected by CSRF
- Immutable replacement uploads, URL refreshes, and reprocessing from any completed version
- Explicit permanent deletion of versions, chunks, embeddings, jobs, and private files after soft deletion and exact-name confirmation
- Public URL policy that rejects non-HTTP protocols, credentials, localhost, literal private/reserved addresses, and metadata hosts
- Markdown MIME, extension, UTF-8, binary-content, and size validation
- PDF MIME, extension, `%PDF-` signature, and size validation
- SHA-256 hashing for uploaded files
- Cryptographically randomized stored filenames outside `/public`
- Transactional source/version creation with failed-file cleanup

### Ingestion job queue

- MySQL-backed ingestion jobs created in the same transaction as each source version
- Migration backfill for existing pending source versions
- Atomic priority/availability claiming with `FOR UPDATE SKIP LOCKED`
- Unique worker reservations and ownership checks on completion/failure
- Configurable maximum attempts and exponential retry delays
- Permanent failure handling that updates the source-version processing status
- Recovery of reservations abandoned by crashed workers, including jobs whose version committed successfully before the worker exited
- Long-running and cron-friendly CLI entry points
- Structured, secret-redacted claim/completion/failure/recovery logs
- Dashboard queue counts, dedicated Jobs screen, and per-source job history

### Extraction and chunking

- Replaceable extractor and chunker interfaces connected to the production worker
- SSRF-resistant URL fetching with HTTP(S)-only policy, DNS/IP validation, pinned connections, proxy bypass, manual redirect revalidation, TLS verification, timeouts, byte limits, and HTML content-type checks
- Readable HTML extraction that removes scripts, styles, navigation, and other common page noise while preserving title, canonical URL, headings, and section hierarchy
- CommonMark-based Markdown parsing with raw HTML stripped before readable-text extraction
- Page-aware PDF text extraction with automatic OCRmyPDF/Tesseract fallback for scanned or image-only documents
- UTF-8 normalization, deterministic SHA-256 hashes of normalized extracted content, and separate uploaded-file hashes
- Configurable paragraph/sentence-aware chunks with overlap, token estimates, ordering, section/page metadata, and character offsets
- Transactional chunk persistence and source-version activation; the former active version changes only after a replacement succeeds
- Activation ordering that prevents an older concurrent job from replacing a newer active version
- Unchanged refreshed content retained as an inactive version without replacing the current active version
- Escaped extracted-text and formatted metadata inspection in source version history

### Embeddings and retrieval

- Provider-independent embedding and vector-store interfaces
- OpenAI embeddings using the model configured in `.env`; no model name is hardcoded
- Batched embedding requests with HTTPS verification, bounded timeouts, retry backoff, and explicit configuration/authentication/rate-limit/timeout/malformed-response exceptions
- Immutable source versions activate only after all newly generated chunks have valid embeddings
- Existing active chunks can be safely backfilled with `php bin/embed-chunks.php`
- Embedding model, vector dimensions, and embedding timestamp stored with every embedded chunk
- Cosine similarity calculated in PHP over MySQL JSON vectors
- Retrieval restricted to enabled, non-deleted sources and their active, ready versions
- Optional source ID/type filters, configurable `top_k`, and configurable similarity threshold
- CLI retrieval debugger with structured source, page, heading, score, and chunk output
- Authenticated `POST /api/v1/retrieve` endpoint for retrieval debugging

### API keys and request controls

- Administrator API-key creation with optional expiry and a one-time plaintext display
- `rag_live_` keys generated from 256 bits of cryptographically secure randomness
- Only a safe visible prefix and SHA-256 secret hash stored in MySQL
- Constant-time `hash_equals()` verification after hash lookup
- Immediate revocation and permanent key deletion
- Bearer authentication with generic failures and `WWW-Authenticate` responses
- MySQL-backed fixed-window limits by API key and HMAC-hashed client IP
- Request audit records containing request ID, numeric key ID, endpoint, status, duration, error category, and numeric usage
- No bearer secrets, complete questions, or raw client IP addresses in API request records
- Configurable API Activity retention with a concurrency-safe scheduled purge command
- Administrator API-key and recent API-request screens

### Grounded RAG chat

- Authenticated `POST /api/v1/chat` endpoint using the OpenAI Responses API
- Provider-independent chat boundary with a replaceable OpenAI implementation
- Code-managed grounded prompt that treats questions and source excerpts as untrusted data
- Answers restricted to retrieved context with stable inline references such as `[S1]`
- Structured citations containing source, version, chunk, URL, page, heading, and excerpt data
- Configurable retrieval count, similarity threshold, context-token estimate, output-token ceiling, reasoning effort, and model
- No paid chat-generation request when retrieval returns no qualifying context
- Safe provider configuration, authentication, rate-limit, timeout, malformed-response, and general failure responses
- Input, cached-input, output, reasoning, total, context, and retrieved-chunk usage reporting
- A stricter, independent chat rate-limit namespace by API key and client IP
- Provider-side response storage disabled for chat requests

## Requirements

- PHP 8.3 or later with `curl`, `dom`, `fileinfo`, `iconv`, `intl`, `json`, `mbstring`, `pdo`, and `pdo_mysql`
- Composer 2
- MySQL 8 or MariaDB
- OCRmyPDF with Tesseract when `PDF_OCR_ENABLED=true`
- An OpenAI API project, API billing, and an API key for embedding, retrieval, and chat generation

Confirm extensions with:

```bash
php -m | grep -E 'curl|dom|fileinfo|iconv|intl|json|mbstring|PDO|pdo_mysql'
```

Install the local OCR engine on macOS with Homebrew:

```bash
brew install ocrmypdf
```

Poppler is optional for manual PDF rendering and visual diagnostics:

```bash
brew install poppler
```

On Debian or Ubuntu, use:

```bash
sudo apt-get update
sudo apt-get install -y ocrmypdf poppler-utils
```

Confirm the engine and installed Tesseract languages:

```bash
ocrmypdf --version
tesseract --version
tesseract --list-langs
```

Homebrew's OCRmyPDF package includes English. Additional languages require their corresponding Tesseract trained-data packages. See the [OCRmyPDF installation guide](https://ocrmypdf.readthedocs.io/en/latest/installation.html) and [Tesseract language data](https://tesseract-ocr.github.io/tessdoc/Data-Files.html).

## Local setup without Docker

1. Install dependencies and create local configuration:

   ```bash
   composer install
   cp .env.example .env
   ```

2. Generate an application secret. Keep the output only in `.env`:

   ```bash
   php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
   ```

3. Create the database and a least-privilege local user using a privileged MySQL account:

   ```sql
   CREATE DATABASE rag_app CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   CREATE USER 'rag_user'@'localhost' IDENTIFIED BY 'replace-with-a-strong-password';
   GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, DROP
       ON rag_app.* TO 'rag_user'@'localhost';
   FLUSH PRIVILEGES;
   ```

   Set matching `DB_*` values in `.env`. When using `DB_HOST=127.0.0.1`, MySQL may treat the account as `'rag_user'@'127.0.0.1'`; create/grant that host variant if required by your installation.

4. Run migrations:

   ```bash
   php bin/migrate.php
   ```

   Configure the embedding and chat providers before running the ingestion worker or calling chat:

   ```dotenv
   EMBEDDING_PROVIDER=openai
   LLM_PROVIDER=openai
   OPENAI_API_KEY=replace-with-a-project-api-key
   OPENAI_EMBEDDING_MODEL=text-embedding-3-small
   OPENAI_CHAT_MODEL=gpt-5-mini
   OPENAI_CHAT_REASONING_EFFORT=low
   OPENAI_CHAT_MAX_OUTPUT_TOKENS=600
   ```

   `OPENAI_EMBEDDING_DIMENSIONS` is optional. Leave it empty to use the model's default dimensions. Changing the embedding model or dimensions later requires re-embedding the stored chunks.

5. Create the single administrator. Password input is hidden on an interactive terminal:

   ```bash
   php bin/create-admin.php
   ```

   Usernames are normalized to lowercase and must be 3-64 characters. Passwords must contain at least 12 characters. The command refuses to create a second administrator.

6. Start the application with `/public` as the document root:

   ```bash
   php -S 127.0.0.1:8080 -t public public/index.php
   ```

7. Check health and open the administrator interface:

   ```bash
   curl -i http://127.0.0.1:8080/api/v1/health
   ```

   Open `http://127.0.0.1:8080/admin/login` in a browser.

The endpoint returns HTTP 200 when both application and database are healthy, or HTTP 503 with `database: unavailable` when the database cannot be reached. It never returns credentials, hostnames, exception messages, or stack traces.

## Laravel Herd

When the repository is parked in Herd, Herd should serve `public/` as the document root. Configure the local URL to match the secured Herd site:

```dotenv
APP_URL=https://ragserver.test
SESSION_SECURE_COOKIE=auto
```

Then visit `https://ragserver.test`. The root route redirects to the protected administrator dashboard, and unauthenticated visitors are redirected to `/admin/login`. In `auto` mode the session cookie receives the `Secure` attribute whenever the current request uses HTTPS.

After signing in, source management is available at `https://ragserver.test/admin/sources`.
Queue status is available at `https://ragserver.test/admin/jobs`.
API keys are managed at `https://ragserver.test/admin/api-keys`, and recent API activity is available at `https://ragserver.test/admin/api-requests`.

## Optional Docker database

The Compose file runs only MySQL so the PHP application can continue using the local PHP/Composer toolchain:

```bash
docker compose up -d database
cp .env.example .env
```

Set `DB_PASSWORD=rag_password` in `.env`, wait for the container health check, then run the migration and server commands above. The Docker credentials are development-only and must not be reused in production.

## Production deployment on Apache

This section is the production deployment baseline through Milestone 9.

### Server packages

On Ubuntu or Debian, install Apache, PHP-FPM, the required PHP extensions, and the local OCR engine. Package names can vary by distribution:

```bash
sudo apt-get update
sudo apt-get install -y \
    apache2 \
    php8.3-fpm \
    php8.3-cli \
    php8.3-mysql \
    php8.3-curl \
    php8.3-mbstring \
    php8.3-intl \
    php8.3-xml \
    php8.3-zip \
    unzip \
    ocrmypdf
```

Confirm that the web and CLI environments meet the application requirements. `proc_open()` must remain enabled for local OCR:

```bash
php -v
php -m | grep -E 'curl|dom|fileinfo|iconv|intl|json|mbstring|PDO|pdo_mysql'
php -r 'var_dump(function_exists("proc_open"));'
ocrmypdf --version
tesseract --list-langs
```

### Production environment

Create `.env` from `.env.example` and use production-specific values:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://ragserver.example.com
APP_SECRET=replace-with-a-stable-random-secret
SESSION_SECURE_COOKIE=always

FILESYSTEM_PATH=/var/lib/ragserver/sources
LOG_LEVEL=info

PDF_OCR_ENABLED=true
PDF_OCR_BINARY=/usr/bin/ocrmypdf
PDF_OCR_LANGUAGES=eng

EMBEDDING_PROVIDER=openai
LLM_PROVIDER=openai
OPENAI_API_KEY=replace-with-a-project-api-key
OPENAI_EMBEDDING_MODEL=text-embedding-3-small
OPENAI_CHAT_MODEL=gpt-5-mini
OPENAI_CHAT_REASONING_EFFORT=low
OPENAI_CHAT_MAX_OUTPUT_TOKENS=600
```

Generate `APP_SECRET` once and preserve it across releases. The macOS Homebrew path `/opt/homebrew/bin/ocrmypdf` must normally be changed to `/usr/bin/ocrmypdf` on Linux.

Install production dependencies, migrate, and create the administrator:

```bash
composer install --no-dev --optimize-autoloader
php bin/migrate.php
php bin/create-admin.php
php bin/embed-chunks.php
```

Run `create-admin.php` only during initial setup. Run migrations after deploying every release; the migration runner safely skips migrations that were already applied.

### Apache virtual host

The document root must be the application's `public/` directory. The repository does not rely on `.htaccess`, so configure the front controller in the virtual host:

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

    ErrorLog ${APACHE_LOG_DIR}/ragserver-error.log
    CustomLog ${APACHE_LOG_DIR}/ragserver-access.log combined
</VirtualHost>
```

`FallbackResource` routes non-file requests through `public/index.php` while allowing existing assets to be served normally. See the [Apache front-controller documentation](https://httpd.apache.org/docs/current/en/mod/mod_dir.html#fallbackresource).

Enable the required Apache configuration and validate it before reloading:

```bash
sudo a2enmod proxy_fcgi setenvif
sudo a2enconf php8.3-fpm
sudo a2ensite ragserver.conf
sudo apachectl configtest
sudo systemctl reload apache2
```

Add HTTPS using the hosting provider or an ACME client such as Certbot. Redirect HTTP to HTTPS and retain `SESSION_SECURE_COOKIE=always` in production.

### Filesystem permissions and PHP limits

The web process and ingestion worker must be able to write source storage, logs, and cache. Application code and `.env` should not be generally writable:

```bash
sudo mkdir -p /var/lib/ragserver/sources
sudo chown -R www-data:www-data /var/lib/ragserver/sources
sudo chown -R www-data:www-data \
    /var/www/ragserver/storage/logs \
    /var/www/ragserver/storage/cache
sudo chgrp www-data /var/www/ragserver/.env
sudo chmod 640 /var/www/ragserver/.env
```

Configure PHP-FPM so its request limits exceed the application upload limit:

```ini
upload_max_filesize = 20M
post_max_size = 22M
display_errors = Off
expose_php = Off
```

### Ingestion worker service

A persistent systemd worker is recommended. It processes uploads immediately, survives reboots, and restarts after unexpected failures. Create `/etc/systemd/system/ragserver-worker.service`:

```ini
[Unit]
Description=Ask Asio processing worker
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
User=www-data
Group=www-data
WorkingDirectory=/var/www/ragserver
ExecStart=/usr/bin/php /var/www/ragserver/bin/worker.php
Restart=on-failure
RestartSec=5
TimeoutStopSec=30
PrivateTmp=true
NoNewPrivileges=true
UMask=0027

[Install]
WantedBy=multi-user.target
```

Enable and inspect the worker:

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now ragserver-worker
sudo systemctl status ragserver-worker
sudo journalctl -u ragserver-worker -f
```

Begin with one worker because PDF OCR can be CPU- and memory-intensive. The worker also requires outbound HTTPS access to `api.openai.com` for embeddings. The queue supports multiple atomic workers, but additional workers should be added only after observing production resource use and provider rate limits.

Each worker checks for expired reservations before claiming another job. `JOB_ABANDONED_TIMEOUT_MINUTES` must be longer than the longest legitimate URL, PDF/OCR, and embedding run. The default is 30 minutes, which is intentionally longer than the default 15-minute OCR process timeout. A separate recovery command can be run safely even when the extraction or provider pipeline is unavailable:

```bash
php bin/recover-jobs.php
```

For a very low-volume installation, cron may be used instead of systemd:

```cron
* * * * * www-data cd /var/www/ragserver && /usr/bin/flock -n storage/cache/worker.lock /usr/bin/php bin/process-jobs.php --once >/dev/null
```

The one-shot command processes at most one available job. `flock` prevents overlapping cron invocations. Do not configure both cron and the persistent service for the initial deployment.

As an additional operational safeguard, run recovery periodically. It is idempotent and handles only processing reservations older than the configured timeout:

```cron
*/10 * * * * www-data cd /var/www/ragserver && /usr/bin/php bin/recover-jobs.php >/dev/null
```

### API Activity retention maintenance

API request logs are retained for 30 days by default. Select one of the supported policies in `.env`:

```dotenv
# 0 keeps records forever; otherwise use 30, 90, 180, or 365.
API_REQUEST_LOG_RETENTION_DAYS=30
API_REQUEST_LOG_PURGE_BATCH_SIZE=1000
```

Retention is applied by an explicit maintenance command rather than during customer requests:

```bash
php bin/prune-api-requests.php
# or
composer prune-api-requests
```

The command calculates its cutoff in UTC, deletes only rows strictly older than that cutoff, and repeatedly deletes the oldest records in bounded batches. It is idempotent. A MySQL advisory lock scoped to the configured database prevents concurrent invocations; a second invocation exits successfully without deleting anything. Start with the default batch size and reduce it if production database monitoring shows undesirable lock or replication pressure. The existing `api_request_logs(created_at)` index supports cutoff selection and ordered deletion.

Schedule it once per day. The host cron timezone does not affect retention boundaries because the application converts the current instant and cutoff to UTC:

```cron
15 2 * * * www-data cd /var/www/ragserver && /usr/bin/php bin/prune-api-requests.php >/dev/null
```

Failures return a non-zero exit code and are written to the secret-redacted application log. Configure cron or monitoring to alert on command failure. If retention is set to `0`, the command reports that records are kept forever and makes no database changes. Backups may continue to contain records that have already expired from the live database, so apply a separate backup-retention policy where required.

Administrators can also open **API Activity → Purge activity** for a controlled manual purge. Available scopes are:

- records older than 30, 90, 180, or 365 days;
- records created before a selected date, interpreted at midnight in `APP_TIMEZONE` and converted to UTC;
- records matching the current validated API Activity filters; or
- all API Activity records.

The application shows an authoritative count before deletion and stores the reviewed scope in the administrator session behind a random, short-lived token. Confirmation requires typing the displayed phrase exactly. The snapshot also records the highest matching log ID, so requests recorded after review are never added to the approved deletion set. Execution uses the same database advisory lock as scheduled retention, deletes in configured batches, and records the administrator ID, reviewed count, scope, and actual deleted count in the secret-redacted operational log. The UI never sends SQL conditions or a client-controlled deletion count.

Manual purge routes are protected by administrator session authentication and CSRF validation. Deletion is permanent in the live database; backups may still retain older copies according to their independent lifecycle. Filter a dataset first and use **matching current filters** when a narrower purge is preferable.

### Backups, monitoring, and releases

A complete backup includes both the MySQL database and `FILESYSTEM_PATH`; the database does not contain the immutable uploaded files. The `.env` file should be backed up separately and securely. Logs are optional operational data. For a strictly consistent backup, briefly stop the ingestion worker and prevent administrator source changes while the database and source directory snapshots are taken. Test restoration periodically on a separate database and storage path.

Useful health checks are:

```bash
curl -fsS https://ragserver.example.com/api/v1/health
sudo systemctl is-active ragserver-worker
sudo journalctl -u ragserver-worker --since today
```

For subsequent releases:

```bash
sudo systemctl stop ragserver-worker
composer install --no-dev --optimize-autoloader
php bin/migrate.php
php bin/embed-chunks.php
sudo systemctl start ragserver-worker
sudo systemctl reload php8.3-fpm
```

Run the PHPUnit suite in CI or before packaging the release, since production installs intentionally omit Composer development dependencies.

Keep MySQL bound to localhost or a private network, expose only required firewall ports, monitor disk usage for source uploads and private `.trash` staging, configure log rotation/retention, and alert on failed jobs or a stopped worker. URL refresh is an explicit administrator action in this release; it is not scheduled automatically.

## Tests and checks

Tests do not contact a database or paid provider by default:

```bash
composer test
find app bootstrap bin config database public resources tests -name '*.php' -exec php -l {} \;
composer validate --strict
```

## Configuration and security

The complete production security checklist is in [SECURITY.md](SECURITY.md).

Copy `.env.example` to `.env`; `.env` is git-ignored. Provider secrets are never loaded into responses or logs. Provider configuration errors use safe messages that do not contain keys, request headers, or embedding input text.

`APP_SECRET` is mandatory from Milestone 2 onward and must contain at least 32 characters. It keys the HMAC identifiers used by login throttling and must remain stable; rotating it clears the effective relationship with existing throttle records.

Authentication defaults can be changed through:

```dotenv
SESSION_NAME=rag_admin_session
SESSION_IDLE_MINUTES=120
SESSION_REGENERATE_MINUTES=15
SESSION_SECURE_COOKIE=auto
LOGIN_MAX_ATTEMPTS=5
LOGIN_WINDOW_MINUTES=15
```

Use `SESSION_SECURE_COOKIE=always` when the application must only be accessed over HTTPS. `auto` derives the setting from the direct request. Forwarded proxy headers are intentionally not trusted; a reverse-proxy deployment needs an explicit trusted-proxy policy before secure-cookie detection should use those headers.

Always configure a web server with `public/` as its document root. Serving the repository root could expose application and operational files despite the front-controller design.

Use `APP_DEBUG=false` in production. Unexpected errors always produce a generic response and a request ID; enabling development debugging never exposes stack traces or exception messages to HTTP clients. Details remain in `storage/logs/`, with credential-shaped context recursively redacted. Logs rotate daily and retain 14 files by default. Complete questions and answers are not logged.

Secure HTTPS responses include HSTS, all administrator responses are marked `Cache-Control: no-store`, and all responses receive CSP, MIME-sniffing, referrer, permissions, and same-origin resource headers. HSTS is intentionally omitted on plain HTTP development requests.

The database connection sets its session timezone to UTC. Application timestamps exposed by health use UTC; `APP_TIMEZONE` is retained for future display-layer localization.

## Source uploads and storage

### Source update and deletion lifecycle

Every replacement, URL refresh, and reprocessing request creates a new immutable source version and queue job. Existing versions are never edited or overwritten. The previous active version remains available to retrieval until the new version has been fully extracted, chunked, embedded, and committed. If processing fails, the active version is unchanged. If two jobs somehow finish out of order, only the newest version is eligible to become active.

Use **Refresh URL** for URL sources, **Upload replacement** for Markdown/PDF sources, or **Reprocess as new version** on a completed version. Only one pending or processing job may be created for a source through the administrator workflow at a time.

Soft deletion disables retrieval but preserves all records and files. Permanent deletion is available only after soft deletion and requires typing the source name exactly. It removes the source, all versions, chunks/embeddings, ingestion jobs, and its private source directory. Permanent deletion is irreversible and is blocked while a job is pending or processing. Take a verified backup before using it when retention is required.

`FILESYSTEM_PATH` must resolve outside `public/`; the application refuses to start if it points into the web root. The default is:

```dotenv
FILESYSTEM_PATH=storage/sources
MAX_UPLOAD_SIZE_MB=20
```

PHP and the web server must permit a request at least as large as this application limit. For a 20 MB source limit, suitable development values are:

```ini
upload_max_filesize=20M
post_max_size=22M
```

The application independently checks the actual temporary-file size, reported size, extension, detected MIME type, and format signature/text encoding. Original filenames are stored only as metadata and are never used as filesystem names. Source files are preserved during soft deletion.

URL creation does not perform a network request in the administrator request. The worker validates the URL structure, resolves every destination immediately before connecting, rejects the entire result if any address is private/reserved, pins cURL to a validated public address, disables environment proxies, and repeats the checks after each manually handled redirect. Only HTML/XHTML responses within the configured limits are accepted.

Uploaded-file SHA-256 and normalized extracted-content SHA-256 are stored separately. The latter detects unchanged readable content without conflating it with raw file bytes.

## Ingestion queue and workers

New source versions and their ingestion jobs are committed together. A job moves through:

```text
pending -> processing -> completed
                    \-> pending (retry with delay)
                    \-> failed (permanent or attempts exhausted)
```

Worker behavior is configured through:

```dotenv
JOB_MAX_ATTEMPTS=3
JOB_RETRY_BASE_SECONDS=30
JOB_RETRY_MAX_SECONDS=3600
JOB_ABANDONED_TIMEOUT_MINUTES=30
JOB_POLL_SECONDS=2
```

Extraction and chunking defaults can be tuned through:

```dotenv
RAG_CHUNK_SIZE_TOKENS=500
RAG_CHUNK_OVERLAP_TOKENS=75
RAG_MINIMUM_CHUNK_TOKENS=20
URL_CONNECT_TIMEOUT_SECONDS=5
URL_REQUEST_TIMEOUT_SECONDS=20
URL_MAXIMUM_REDIRECTS=5
URL_MAXIMUM_RESPONSE_BYTES=5242880
URL_USER_AGENT=RAGServer/1.0

PDF_OCR_ENABLED=true
PDF_OCR_BINARY=ocrmypdf
PDF_OCR_LANGUAGES=eng
PDF_OCR_PROCESS_TIMEOUT_SECONDS=900
PDF_OCR_PAGE_TIMEOUT_SECONDS=120
PDF_OCR_JOBS=1
PDF_OCR_MAX_OUTPUT_SIZE_MB=100
PDF_OCR_ROTATE_PAGES=true
PDF_OCR_DESKEW=true
```

The worker first attempts native PDF text extraction. OCR starts only when the document contains no extractable text. OCRmyPDF runs as a shell-free argument-array subprocess with one worker by default, bounded per-page and whole-process timeouts, a maximum output size, and private temporary output that is removed after extraction. The searchable OCR copy is transient; the immutable original upload remains unchanged. Digital signatures may be invalidated only on that disposable OCR copy. OCR metadata is stored with the extracted source version.

Set `PDF_OCR_BINARY` to an absolute path for cron or service environments whose `PATH` is restricted. Apple Silicon Homebrew normally uses `/opt/homebrew/bin/ocrmypdf`; Intel Homebrew normally uses `/usr/local/bin/ocrmypdf`.

The one-shot command is designed for cron:

```bash
php bin/process-jobs.php --once
```

The long-running worker is:

```bash
php bin/worker.php
```

The worker now extracts, chunks, embeds, stores, and activates source versions. Extraction and embedding must both succeed before a new version can replace the current active version. A cron entry can run the one-shot command for low-volume deployments, for example:

```cron
* * * * * cd /absolute/path/to/ragserver && /usr/bin/flock -n storage/cache/worker.lock /absolute/path/to/php bin/process-jobs.php --once >/dev/null
```

For production, prefer the systemd service documented in the production deployment section. Use either systemd or cron, not both initially.

Abandoned-job recovery runs automatically before a healthy worker claims work. It distinguishes between a genuinely unfinished attempt and a version that was already committed as `ready` or `inactive` before the worker crashed. The latter is completed without reprocessing; other expired attempts return to pending or fail after their final attempt. Recovery can also be invoked independently with:

```bash
composer recover-jobs
```

Unexpected exception messages are written only to the secret-redacted application log. The job table and administrator UI receive a generic reference. Controlled ingestion exceptions may provide a deliberately safe operational message. A transient OCR engine failure follows normal job retry rules; a successful OCR run that recognizes no readable text fails permanently with an actionable message.

## Embeddings and retrieval

Embedding and retrieval behavior is configured through:

```dotenv
EMBEDDING_PROVIDER=openai
OPENAI_API_KEY=
OPENAI_BASE_URL=https://api.openai.com
OPENAI_EMBEDDING_MODEL=text-embedding-3-small
OPENAI_EMBEDDING_DIMENSIONS=
OPENAI_CONNECT_TIMEOUT_SECONDS=10
OPENAI_REQUEST_TIMEOUT_SECONDS=60
OPENAI_MAXIMUM_RETRIES=3

RAG_EMBEDDING_BATCH_SIZE=64
RAG_RETRIEVAL_DEFAULT_TOP_K=8
RAG_RETRIEVAL_MAXIMUM_TOP_K=20
RAG_RETRIEVAL_MINIMUM_SIMILARITY=0.20
RAG_RETRIEVAL_MAXIMUM_QUERY_CHARACTERS=4000
API_MAXIMUM_BODY_BYTES=65536
API_RATE_LIMIT_WINDOW_SECONDS=60
API_RATE_LIMIT_PER_KEY=60
API_RATE_LIMIT_PER_IP=120
API_CHAT_RATE_LIMIT_PER_KEY=10
API_CHAT_RATE_LIMIT_PER_IP=20
API_REQUEST_LOG_RETENTION_DAYS=30
API_REQUEST_LOG_PURGE_BATCH_SIZE=1000
```

`API_REQUEST_LOG_RETENTION_DAYS` accepts only `0`, `30`, `90`, `180`, or `365`; `0` means keep forever. Run `bin/prune-api-requests.php` on a daily schedule to enforce the selected policy. API requests only append audit metadata and never perform retention deletion themselves.

Run the embedding backfill after deploying Milestone 6 or whenever legacy active chunks have no embedding:

```bash
php bin/embed-chunks.php
```

The command is idempotent: it selects only active chunks whose embedding is missing or belongs to a different configured model, batches provider requests, and transactionally stores each completed batch. Use `--once` to process at most one configured batch. If dimensions change while retaining the same model name, explicitly rebuild all active embeddings:

```bash
php bin/embed-chunks.php --rebuild
```

Test retrieval without exposing an HTTP endpoint:

```bash
php bin/retrieve.php "What should I know about the refund policy?" --top-k=8
```

For score calibration only, the CLI accepts a threshold override:

```bash
php bin/retrieve.php "test query" --top-k=10 --min-similarity=-1
```

The default `0.20` threshold is intentionally configurable and should be evaluated against representative questions from the deployed corpus. Retrieval compares only vectors produced by the currently configured model with matching dimensions; changing either setting requires clearing and rebuilding prior embeddings.

Create an application API key in the administrator interface, copy it from the one-time display, and call retrieval with:

```bash
curl -X POST https://ragserver.example.com/api/v1/retrieve \
    -H 'Authorization: Bearer rag_live_replace_with_your_key' \
    -H 'Content-Type: application/json' \
    -d '{"query":"refund conditions","top_k":10}'
```

Requests without a valid active, unexpired key return HTTP 401. Rate-limited requests return HTTP 429 with `Retry-After`, `X-RateLimit-Limit`, and `X-RateLimit-Remaining` headers. Successful authenticated requests include the rate-limit headers as well.

## Grounded chat API

Chat-generation and cost controls are configured separately from embedding and retrieval defaults:

```dotenv
LLM_PROVIDER=openai
OPENAI_CHAT_MODEL=gpt-5-mini
OPENAI_CHAT_REASONING_EFFORT=low
OPENAI_CHAT_MAX_OUTPUT_TOKENS=600
OPENAI_CHAT_MAXIMUM_RETRIES=0

RAG_CHAT_TOP_K=5
RAG_CHAT_MAXIMUM_TOP_K=8
RAG_CHAT_CONTEXT_MAX_TOKENS=4000
RAG_CHAT_MAXIMUM_QUESTION_CHARACTERS=4000

API_CHAT_RATE_LIMIT_PER_KEY=10
API_CHAT_RATE_LIMIT_PER_IP=20
```

`OPENAI_CHAT_REASONING_EFFORT` is optional. Leave it empty for models that do not support reasoning configuration. Supported values accepted by the application are `none`, `minimal`, `low`, `medium`, `high`, `xhigh`, and `max`, but model support differs; an unsupported model/effort combination returns a safe `provider_configuration_error`.

The chat provider defaults to zero automatic HTTP retries. This prevents an ambiguous timeout from silently repeating a potentially billable generation. API clients can retry deliberately using their own bounded policy.

`RAG_CHAT_TOP_K` is the server default. Clients may request another value, but cannot exceed `RAG_CHAT_MAXIMUM_TOP_K`. After retrieval, the application adds chunks in similarity order only while they fit within `RAG_CHAT_CONTEXT_MAX_TOKENS`. The token count is a conservative local estimate; actual provider usage is returned from OpenAI separately.

Call chat using an active application API key:

```bash
curl -X POST https://ragserver.example.com/api/v1/chat \
    -H 'Authorization: Bearer rag_live_replace_with_your_key' \
    -H 'Content-Type: application/json' \
    -d '{"question":"What is the refund policy?","conversation_id":null,"top_k":5}'
```

A successful response contains `answer`, structured `citations`, numeric `usage`, and `request_id`. Usage includes `retrieved_chunks`, the local `context_tokens` estimate, and provider-reported `input_tokens`, `cached_input_tokens`, `output_tokens`, `reasoning_tokens`, and `total_tokens`. Numeric values are copied into the API request audit log; complete questions and answers are not logged.

This milestone is intentionally single-turn. Omit `conversation_id` or send `null`; a non-null value returns HTTP 422 rather than pretending that conversation history was stored. If no chunks meet the retrieval threshold, the endpoint returns the standard insufficient-information answer without calling the chat provider.

The integration follows OpenAI's current recommendation to use the [Responses API for text generation](https://developers.openai.com/api/docs/guides/text). Before changing models, run representative retrieval and answer evaluations and verify citation accuracy, unsupported-answer refusal, latency, and token usage.

The health endpoint remains public:

```text
GET /api/v1/health
```

API request logs retain the numeric API-key identifier for audit correlation after permanent key deletion, but never retain the complete key. Client IP addresses are HMAC-hashed with `APP_SECRET`. Keep `APP_SECRET` stable or IP hashes produced before and after rotation will not correlate.

## Migration policy

Migration filenames are ordered and immutable once deployed. Each file returns an object implementing `App\Database\Migration`. The runner records successful migrations in `schema_migrations` and safely skips them on subsequent runs.

MySQL and MariaDB may implicitly commit DDL statements. The runner uses transactions when the driver keeps them active, but schema changes cannot be assumed to roll back on every supported server. Write forward-fix migrations for deployed schema changes instead of editing an applied migration.

## Current milestone boundary

The initial nine-milestone application scope is implemented. The dashboard data-lifecycle audit is now implemented through shared pagination/query state, the API Activity read experience, scheduled retention maintenance, and confirmed filter-aware manual purging. Further dashboard scaling work remains separately reviewable.
