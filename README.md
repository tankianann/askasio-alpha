# RAG Server

A framework-free PHP application for managing knowledge sources and answering grounded questions through a versioned REST API. Milestone 1 provides the production-oriented application foundation; administrator authentication, sources, ingestion, retrieval, and chat arrive in later milestones.

## Milestone 1 functionality

- PHP 8.3+ front controller with `/public` as the only web root
- Immutable environment-backed configuration
- Lazy, exception-based PDO MySQL/MariaDB connection using native prepared statements
- Ordered PHP migration runner with a `schema_migrations` ledger
- Lightweight router with route parameters, groups, middleware, names, and all required HTTP verbs
- Request/response abstractions, request IDs, security headers, and safe JSON/HTML errors
- Rotating Monolog files outside the public root
- `GET /api/v1/health`, including non-sensitive database availability
- PHPUnit unit/integration-style tests that make no external requests

## Requirements

- PHP 8.3 or later with `json`, `pdo`, and `pdo_mysql`
- Composer 2
- MySQL 8 or MariaDB

Confirm extensions with:

```bash
php -m | grep -E 'json|PDO|pdo_mysql'
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

5. Start the application with `/public` as the document root:

   ```bash
   php -S 127.0.0.1:8080 -t public public/index.php
   ```

6. Check health:

   ```bash
   curl -i http://127.0.0.1:8080/api/v1/health
   ```

The endpoint returns HTTP 200 when both application and database are healthy, or HTTP 503 with `database: unavailable` when the database cannot be reached. It never returns credentials, hostnames, exception messages, or stack traces.

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

Always configure a web server with `public/` as its document root. Serving the repository root could expose application and operational files despite the front-controller design.

Use `APP_DEBUG=false` in production. Unexpected errors always produce a generic response and a request ID; enabling development debugging never exposes stack traces or exception messages to HTTP clients. Details remain in `storage/logs/`, with credential-shaped context recursively redacted. Logs rotate daily and retain 14 files by default. Questions will not be logged unless `LOG_API_QUESTIONS=true` is explicitly configured in a future API milestone.

The database connection sets its session timezone to UTC. Application timestamps exposed by health use UTC; `APP_TIMEZONE` is retained for future display-layer localization.

## Migration policy

Migration filenames are ordered and immutable once deployed. Each file returns an object implementing `App\Database\Migration`. The runner records successful migrations in `schema_migrations` and safely skips them on subsequent runs.

MySQL and MariaDB may implicitly commit DDL statements. The runner uses transactions when the driver keeps them active, but schema changes cannot be assumed to roll back on every supported server. Write forward-fix migrations for deployed schema changes instead of editing an applied migration.

## Milestone boundaries

Milestone 1 intentionally does not include administrator sessions, source tables, upload handling, background jobs, AI providers, API keys, or RAG endpoints. The next milestone will add the administrator table, CLI account creation, secure session authentication, CSRF defenses, login throttling, and the protected server-rendered admin shell.
