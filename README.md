# RAG Server

A framework-free PHP application for managing knowledge sources and answering grounded questions through a versioned REST API. Milestones 1–3 provide the application foundation, secure single-administrator interface, and versioned source management; ingestion, retrieval, and chat arrive in later milestones.

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
- Public URL policy that rejects non-HTTP protocols, credentials, localhost, literal private/reserved addresses, and metadata hosts
- Markdown MIME, extension, UTF-8, binary-content, and size validation
- PDF MIME, extension, `%PDF-` signature, and size validation
- SHA-256 hashing for uploaded files
- Cryptographically randomized stored filenames outside `/public`
- Transactional source/version creation with failed-file cleanup

## Requirements

- PHP 8.3 or later with `fileinfo`, `json`, `pdo`, and `pdo_mysql`
- Composer 2
- MySQL 8 or MariaDB

Confirm extensions with:

```bash
php -m | grep -E 'fileinfo|json|PDO|pdo_mysql'
```

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

## Optional Docker database

The Compose file runs only MySQL so the PHP application can continue using the local PHP/Composer toolchain:

```bash
docker compose up -d database
cp .env.example .env
```

Set `DB_PASSWORD=rag_password` in `.env`, wait for the container health check, then run the migration and server commands above. The Docker credentials are development-only and must not be reused in production.

## Tests and checks

Tests do not contact a database or paid provider by default:

```bash
composer test
find app bootstrap bin config database public resources tests -name '*.php' -exec php -l {} \;
composer validate --strict
```

## Configuration and security

Copy `.env.example` to `.env`; `.env` is git-ignored. Provider secrets are present only as empty configuration entries for future milestones and are not loaded into responses or logs.

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

Use `APP_DEBUG=false` in production. Unexpected errors always produce a generic response and a request ID; enabling development debugging never exposes stack traces or exception messages to HTTP clients. Details remain in `storage/logs/`, with credential-shaped context recursively redacted. Logs rotate daily and retain 14 files by default. Questions will not be logged unless `LOG_API_QUESTIONS=true` is explicitly configured in a future API milestone.

The database connection sets its session timezone to UTC. Application timestamps exposed by health use UTC; `APP_TIMEZONE` is retained for future display-layer localization.

## Source uploads and storage

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

URL creation does not perform a network request in Milestone 3. The current policy rejects obvious unsafe destinations. Milestone 5 will additionally resolve and validate every destination IP immediately before connecting and repeat validation after each redirect.

## Migration policy

Migration filenames are ordered and immutable once deployed. Each file returns an object implementing `App\Database\Migration`. The runner records successful migrations in `schema_migrations` and safely skips them on subsequent runs.

MySQL and MariaDB may implicitly commit DDL statements. The runner uses transactions when the driver keeps them active, but schema changes cannot be assumed to roll back on every supported server. Write forward-fix migrations for deployed schema changes instead of editing an applied migration.

## Current milestone boundary

Milestone 3 intentionally does not fetch URLs, extract document text, create chunks, queue jobs, generate embeddings, or activate versions. Newly created versions remain honestly marked `pending`, and `active_version_id` remains empty until a later ingestion worker succeeds. Milestone 4 will add the MySQL-backed ingestion queue, atomic claiming, retry/failure handling, worker commands, and processing-status operations.
