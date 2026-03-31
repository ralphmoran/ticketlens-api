---
title: "Setup & Deployment Guide"
description: "Production-ready walkthrough covering CLI installation, backend setup, and deployment. Includes every real issue encountered during development with the exact fix applied."
---

# TicketLens — Setup & Deployment Guide

---

## Table of Contents

1. [Prerequisites](#prerequisites)
2. [CLI Installation](#cli-installation)
3. [Backend Setup — Local (Laravel Sail)](#backend-setup-local-laravel-sail)
4. [Backend Setup — Production](#backend-setup-production)
5. [Environment Reference](#environment-reference)
6. [API Routes](#api-routes)
7. [Testing Locally](#testing-locally)
8. [Live Test Commands — Pro and Teams Features](#live-test-commands-pro-and-teams-features)
9. [Troubleshooting](#troubleshooting)

---

## Prerequisites

| Tool           | Min Version | Where used              |
|----------------|-------------|-------------------------|
| Node.js        | 18          | CLI only (20+ for CI)   |
| PHP            | 8.2         | Backend only            |
| Composer       | 2.x         | Backend only            |
| Docker Desktop | Latest      | Required for Sail       |

---

## CLI Installation

### Option A — Global install via npm

```bash
npm install -g ticketlens
ticketlens --version
```

### Option B — npx (no install)

```bash
npx ticketlens init
```

### Option C — Development / local clone

```bash
git clone https://github.com/ralphmoran/ticket-lens.git
cd ticket-lens
npm link  # makes `ticketlens` available globally from local clone
```

> Zero runtime npm dependencies by design. `npm install` is only needed if you are adding dev tooling.

### First-time configuration

```bash
ticketlens init
```

The interactive wizard prompts for:

- Jira base URL (bare hostnames auto-probed for https then http)
- Auth type: Cloud uses API token, Server/DC uses PAT or username + password
- Email address (Cloud auth only)
- Ticket prefixes, e.g. `PROJ,ACME`
- Project paths for auto-profile resolution

Credentials are saved to `~/.ticketlens/credentials.json` with `chmod 600` applied automatically.
Config is saved to `~/.ticketlens/profiles.json`.

### Switch profiles

```bash
ticketlens switch        # interactive arrow-key selector
ticketlens profiles      # list all configured profiles
```

---

## Backend Setup — Local (Laravel Sail)

The backend is a separate Laravel 11 API at `ticketlens-api/`. It handles:

- Digest schedule management
- Email delivery via queued jobs
- AI summarization (BYOK and cloud routing)
- License validation (LemonSqueezy)

### 1. Clone and install

```bash
git clone https://github.com/ralphmoran/ticketlens-api.git
cd ticketlens-api
composer install
```

### 2. Configure environment

```bash
cp .env.example .env
php artisan key:generate
```

Critical `.env` values for local dev:

```env
APP_ENV=local
APP_DEBUG=false
APP_URL=http://localhost

DB_CONNECTION=mysql
DB_HOST=mysql
DB_PORT=3306
DB_DATABASE=ticketlens
DB_USERNAME=sail
DB_PASSWORD=password

QUEUE_CONNECTION=redis
REDIS_HOST=redis

MAIL_MAILER=smtp
MAIL_HOST=mailpit
MAIL_PORT=1025
MAIL_FROM_ADDRESS=noreply@ticketlens.dev
MAIL_FROM_NAME="TicketLens"

TICKETLENS_SKIP_LICENSE=true

ANTHROPIC_API_KEY=sk-ant-xxxx
```

> `DB_HOST=mysql` and `REDIS_HOST=redis` refer to Docker service names defined in `compose.yaml`, not `localhost`.

### 3. Start Sail

```bash
./vendor/bin/sail up -d
```

Containers started: `laravel.test` (PHP 8.5, artisan built-in server), `mysql:8.4`, `redis:alpine`, `mailpit`.

### 4. Database setup

Sail passes `DB_DATABASE` and `DB_USERNAME` to the MySQL Docker image at first boot, so the database and user grants are created automatically on a fresh volume. No manual SQL is needed for a clean install.

> **Only needed if a stale volume exists:** If you previously ran Sail with a different `DB_DATABASE` (e.g. the default `laravel`), the existing MySQL volume won't have the `ticketlens` database. In that case, create it manually:
>
> ```bash
> docker exec -it ticketlens-api-mysql-1 mysql -u root -p
> # Root password = DB_PASSWORD from .env (default: password)
> ```
>
> ```sql
> CREATE DATABASE IF NOT EXISTS ticketlens;
> GRANT ALL PRIVILEGES ON ticketlens.* TO 'sail'@'%';
> FLUSH PRIVILEGES;
> EXIT;
> ```

Run migrations:

```bash
./vendor/bin/sail artisan migrate
```

### 5. Start the queue worker

Digest emails are dispatched as queued jobs. The worker must be running:

```bash
./vendor/bin/sail artisan queue:work --sleep=1 --tries=3 --timeout=60
```

Run this in a separate terminal and keep it running during local development.

> Use `--timeout=60` to match production. The `SendDigestEmail` job inherits the worker's timeout; a lower value here can kill jobs that complete fine in production.

### 6. Verify setup

```bash
# Health check
curl http://localhost/up

# Route list
./vendor/bin/sail artisan route:list --path=v1
```

Expected output:

```
POST       v1/digest/deliver
POST       v1/schedule
GET|HEAD   v1/schedule
DELETE     v1/schedule
POST       v1/summarize
```

---

## Backend Setup — Production

### Server requirements

- PHP 8.2+ with extensions: `pdo_mysql`, `redis`, `bcmath`, `mbstring`, `xml`
- MySQL 8.x or PostgreSQL 14+
- Redis 6+
- Queue worker managed by Supervisor or Laravel Forge
- SMTP provider: Mailgun, Postmark, SES, or equivalent

### Deployment checklist

```bash
# Install production dependencies only
composer install --no-dev --optimize-autoloader

# Migrate database
php artisan migrate --force

# Cache config, routes, views
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Storage link (if using local disk driver)
php artisan storage:link
```

Production `.env` changes from local:

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://api.ticketlens.dev

TICKETLENS_SKIP_LICENSE=false
LEMONSQUEEZY_API_KEY=your-key-here

MAIL_HOST=smtp.mailgun.org
MAIL_PORT=587
MAIL_USERNAME=your-user
MAIL_PASSWORD=your-password
```

### Supervisor config (queue worker)

```ini
[program:ticketlens-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/ticketlens-api/artisan queue:work redis --sleep=3 --tries=3 --backoff=10,60,300 --timeout=60
autostart=true
autorestart=true
numprocs=2
user=www-data
redirect_stderr=true
stdout_logfile=/var/log/ticketlens-worker.log
```

### Nginx config

```nginx
server {
    listen 80;
    server_name api.ticketlens.dev;
    root /var/www/ticketlens-api/public;

    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }
}
```

---

## Environment Reference

| Variable                    | Required     | Default        | Description                                       |
|-----------------------------|--------------|----------------|---------------------------------------------------|
| `APP_ENV`                   | yes          | `local`        | Set to `production` in prod                       |
| `APP_KEY`                   | yes          | —              | Generate with `artisan key:generate`              |
| `DB_DATABASE`               | yes          | `ticketlens`   | MySQL database name                               |
| `DB_HOST`                   | yes          | `mysql`        | Docker service name in Sail; `127.0.0.1` in prod  |
| `QUEUE_CONNECTION`          | yes          | `redis`        | Must be `redis`, not `sync`, for queued jobs      |
| `MAIL_HOST`                 | yes          | `mailpit`      | `mailpit` locally; your SMTP host in prod         |
| `TICKETLENS_SKIP_LICENSE`   | no           | `false`        | Set `true` to bypass LemonSqueezy locally         |
| `ANTHROPIC_API_KEY`         | for BYOK     | —              | Required for `--summarize` without `--cloud`      |
| `LEMONSQUEEZY_API_KEY`      | for prod     | —              | Required when `SKIP_LICENSE=false`                |

---

## API Routes

All routes are prefixed `/v1/`. There is no `/api/` segment — this is set via `apiPrefix: ''` in `bootstrap/app.php`.

### Authentication

Every request requires a Bearer token matching a licensed key:

```
Authorization: Bearer <license-key>
```

The raw key is never stored. The backend stores `sha256(key)` and compares on each request.

**Rate limits per license key:**

| Route                  | Limit       |
|------------------------|-------------|
| `POST /v1/summarize`   | 10 req/min  |
| `POST /v1/schedule`    | 5 req/min   |
| `POST /v1/digest/deliver` | 20 req/min |
| Global                 | 120 req/min |

**Brute force protection:** 5 consecutive auth failures trigger a 15-minute IP lockout.

---

### POST /v1/schedule

Create or update a digest schedule for the authenticated license key. This endpoint performs an upsert — it always returns `201` whether creating or updating.

```bash
curl -X POST http://localhost/v1/schedule \
  -H "Authorization: Bearer my-license-key" \
  -H "Content-Type: application/json" \
  -d '{
    "email": "dev@example.com",
    "timezone": "America/New_York",
    "deliverAt": "07:00"
  }'
```

Response `201`:

```json
{ "scheduled": true, "nextDelivery": "2026-03-31T07:00:00-05:00" }
```

> Note the field name is `deliverAt` (camelCase), not `deliver_at`. Sending `deliver_at` returns a 422.

---

### GET /v1/schedule

Show the current digest schedule for the authenticated key.

```bash
curl http://localhost/v1/schedule \
  -H "Authorization: Bearer my-license-key"
```

Response `200`:

```json
{
  "email": "dev@example.com",
  "timezone": "America/New_York",
  "deliverAt": "07:00",
  "active": true,
  "lastDeliveredAt": null,
  "nextDelivery": "2026-03-31T07:00:00-05:00"
}
```

---

### DELETE /v1/schedule

Remove the digest schedule for the authenticated key.

```bash
curl -X DELETE http://localhost/v1/schedule \
  -H "Authorization: Bearer my-license-key"
```

Response `200`: `{ "deleted": true }`

---

### POST /v1/digest/deliver

Trigger an immediate digest email. This endpoint is called by `ticketlens triage --digest`.

```bash
curl -X POST http://localhost/v1/digest/deliver \
  -H "Authorization: Bearer my-license-key" \
  -H "Content-Type: application/json" \
  -d '{
    "profile": "production",
    "staleDays": 5,
    "summary": { "total": 2, "needsResponse": 1, "aging": 1 },
    "tickets": [
      {
        "ticketKey": "PROJ-123",
        "summary": "Fix cart checkout bug",
        "status": "Code Review",
        "urgency": "needs-response"
      }
    ]
  }'
```

Response: `{"delivered": true}`

The email is dispatched as a `SendDigestEmail` job with 3 retries and backoff of 10s / 60s / 300s.

---

### POST /v1/summarize

Generate an AI summary of a ticket brief. Used by `ticketlens --summarize --cloud`.

```bash
curl -X POST http://localhost/v1/summarize \
  -H "Authorization: Bearer my-license-key" \
  -H "Content-Type: application/json" \
  -d '{"brief": "<full ticket brief text>"}'
```

Response: `{"summary": "..."}`

---

## Testing Locally

### Backend test suite

```bash
./vendor/bin/sail artisan test
```

39 tests passing. Coverage includes:

- `DigestControllerTest` — schedule creation, job dispatch, 404/422 handling
- `ScheduleControllerTest` — CRUD and rate limiting
- `SummarizeControllerTest` — Anthropic service mock
- `ValidateLicenseKeyTest` — brute force lockout, key hashing

### Check email delivery via Mailpit

```bash
# REST API — confirm subject and recipient
curl -s http://localhost:8025/api/v1/messages | jq '.messages[0] | {subject, to}'

# Open browser UI
open http://localhost:8025
```

### CLI test suite

```bash
cd ~/Desktop/Projects/ticket-lens
node --test 'skills/jtb/scripts/test/*.test.mjs'
# 534 tests, 0 failures
```

---

## Live Test Commands — Pro and Teams Features

### Setup: create a test schedule

```bash
curl -s -X POST http://localhost/v1/schedule \
  -H "Authorization: Bearer test-key-123" \
  -H "Content-Type: application/json" \
  -d '{"email":"you@example.com","timezone":"America/New_York","deliverAt":"07:00"}' | jq
```

| Response | Meaning |
|----------|---------|
| `201`    | Schedule created or updated (always 201 — this endpoint is an upsert) |
| `422`    | Validation error — check field names, especially `deliverAt` (camelCase) |
| `401`    | Key rejected or IP lockout active — verify `TICKETLENS_SKIP_LICENSE=true` |

---

### Pro — AI summary (BYOK)

```bash
ANTHROPIC_API_KEY=sk-ant-xxxx ticketlens PROJ-123 --summarize
```

Requires `ANTHROPIC_API_KEY` in environment or `~/.ticketlens/credentials.json`.

---

### Pro — AI summary via TicketLens cloud

```bash
ticketlens PROJ-123 --summarize --cloud
```

Routes the brief through `POST /v1/summarize`. License key required.

> On first use, the CLI shows an interactive consent prompt before sending data to the cloud endpoint. In non-TTY environments (CI, pipes), consent defaults to denied and the command exits with code 1. Pass `--yes` or pre-accept consent via `ticketlens config` to bypass.

---

### Pro — VCS diff + review context

```bash
ticketlens PROJ-123 --check
```

Appends `git diff HEAD` (or SVN equivalent) and Claude Code review instructions to the output brief.

---

### Pro — Triage digest email

```bash
ticketlens triage --digest
```

Posts scored triage results to `POST /v1/digest/deliver` and exits silently on success — no triage table is printed to stdout. Verify the email landed in Mailpit:

```bash
curl -s http://localhost:8025/api/v1/messages | jq '.messages[0].Subject'
# "Your triage digest — 2 tickets need attention (Mon Mar 30)"
```

---

### Teams — Triage another developer's tickets

```bash
ticketlens triage --assignee="Jane Dev"
```

---

### Teams — Filter by sprint

```bash
ticketlens triage --sprint="Sprint 12"
```

---

### Teams — Export results

```bash
ticketlens triage --export=csv
ticketlens triage --export=json
```

Output path: `~/.ticketlens/exports/YYYY-MM-DD-HH-MM-{profile}.{csv|json}`

Example: `~/.ticketlens/exports/2026-03-30-09-00-default.csv`

---

### Teams — Combined

```bash
ticketlens triage --assignee="Jane Dev" --sprint="Sprint 12" --export=csv
```

---

## Troubleshooting

### Access denied for user 'sail' to database 'ticketlens'

Sail's MySQL only grants `sail` access to a database named `laravel` by default. Any other `DB_DATABASE` value requires manual setup:

```bash
docker exec -it ticketlens-api-mysql-1 mysql -u root -p
```

```sql
CREATE DATABASE IF NOT EXISTS ticketlens;
GRANT ALL PRIVILEGES ON ticketlens.* TO 'sail'@'%';
FLUSH PRIVILEGES;
```

---

### ModelNotFoundException returns 500 instead of 404

`ModelNotFoundException` does not extend `HttpException`, so Laravel's built-in exception handler does not convert it to a 404 automatically. The fix is to handle it inside the `Throwable` renderer in `bootstrap/app.php`, with an explicit `instanceof` check before the generic catch-all:

```php
->withExceptions(function (Exceptions $exceptions) {
    $exceptions->render(function (\Throwable $e, $request) {
        if ($e instanceof \Illuminate\Validation\ValidationException) return null;
        if ($e instanceof \Symfony\Component\HttpKernel\Exception\HttpException) return null;
        if ($e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException) {
            return response()->json(['error' => 'Not found'], 404);
        }
        if (str_starts_with($request->path(), 'v1/')) {
            return response()->json(['error' => 'Request failed'], 500);
        }
        return null;
    });
})
```

Returning `null` for `ValidationException` and `HttpException` lets Laravel handle those natively.

---

### app()->isLocal() returns wrong value in tests

`app()->isLocal()` reads the application environment from the booted container and does not respond to `Config::set('app.env', ...)` calls in test setup. Use `config('app.env') !== 'production'` instead — this reads from the config array and does respond to `Config::set`.

---

### email:rfc,dns validation fails in Docker

DNS lookups fail inside the Docker test network. Change validation rules from `email:rfc,dns` to `email:rfc` to avoid false negatives in tests.

---

### Emails queued but never delivered

The queue worker is a separate process. If it is not running, jobs sit in Redis indefinitely:

```bash
./vendor/bin/sail artisan queue:work --tries=3
```

---

### strip_tags() corrupting Jira content

An earlier version of `AnthropicService` called `strip_tags()` on the ticket brief before sending it to the LLM. This silently removed HTML entities and angle-bracket syntax (e.g. `Array<string>`). The fix: strip only null bytes, nothing else.

```php
$sanitized = mb_substr(str_replace("\x00", '', $brief), 0, 50_000);
```

---

### `ticketlens triage --digest` shows "upgrade to Pro" even though TICKETLENS_SKIP_LICENSE=true

`TICKETLENS_SKIP_LICENSE=true` only bypasses license validation on the Laravel backend. The CLI performs its own license check by reading `~/.ticketlens/license.json` before making any API call. If that file is absent or the stored key is not marked `active`, the CLI shows the upgrade prompt and exits without posting anything.

To test `--digest` locally without a real LemonSqueezy key, activate any key via `ticketlens activate <key>` first, or manually create `~/.ticketlens/license.json` with:

```json
{ "key": "test-key-123", "status": "active", "tier": "pro" }
```

---

### Rate limiter or brute force lockout during local testing

After 5 failed auth attempts the requesting IP is blocked for 15 minutes. To clear the lockout:

```bash
./vendor/bin/sail artisan tinker
```

```php
use Illuminate\Support\Facades\RateLimiter;
RateLimiter::clear('auth-fail:127.0.0.1');
```

---

### Queue job retried but schedule shows wrong last_delivered_at

`last_delivered_at` must be updated **before** dispatching the job. If the DB write is done after dispatch and fails, the job may re-execute and see a stale timestamp. Correct order:

```php
$schedule->update(['last_delivered_at' => now()]);
SendDigestEmail::dispatch($schedule->id, $payload);
```
