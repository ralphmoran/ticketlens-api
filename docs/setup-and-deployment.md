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
| PHP            | 8.4         | Backend only (CI runs 8.4; Sail image is 8.5) |
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

The backend is a separate Laravel 13 API at `ticketlens-api/`. It handles:

- Digest schedule management
- Email delivery via queued jobs
- AI summarization (BYOK and cloud routing)
- License validation (LemonSqueezy)
- Live Console updates (Laravel Reverb WebSockets)

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
CACHE_STORE=redis
SESSION_DRIVER=redis
REDIS_HOST=redis

MAIL_MAILER=smtp
MAIL_HOST=mailpit
MAIL_PORT=1025
MAIL_FROM_ADDRESS=noreply@ticketlens.dev
MAIL_FROM_NAME="TicketLens"

TICKETLENS_SKIP_LICENSE=true

```

> AI summarization uses per-user provider keys added in the Console (Admin > AI). No `ANTHROPIC_API_KEY` / `GROQ_API_KEY` is read from `.env`.

> `DB_HOST=mysql` and `REDIS_HOST=redis` refer to Docker service names defined in `compose.yaml`, not `localhost`.

### 3. Start Sail

```bash
./vendor/bin/sail up -d
```

Containers started: `laravel.test` (PHP 8.5, artisan built-in server), `mysql:8.4`, `redis:alpine`, `mailpit`, `worker` (queue), plus `reverb` (WebSockets, port 8080) and `proxy` (nginx on port 80) from `docker-compose.override.yml`. Scheduled jobs (`routes/console.php`) need `sail artisan schedule:work` in a terminal; no container runs it.

> **Untrusted networks:** Sail's default `compose.yaml` forwards MySQL (3306) and Redis (6379) to `0.0.0.0` on the host, and dev Redis has no password. Fine on a trusted home/office network; if working from a coffee shop, conference wifi, or a shared VPN, bind them to `127.0.0.1:PORT:PORT` instead of the bare `PORT:PORT` shorthand in `compose.yaml`.

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

### 5. Queue worker

Digest emails, Slack notifications, and other queued jobs are processed by the `worker` service in `compose.yaml` — it starts automatically with `sail up -d` and restarts on its own if it ever crashes (`restart: unless-stopped`), no separate terminal needed.

To confirm it's running:

```bash
docker ps --filter name=ticketlens-api-worker --format "table {{.Names}}\t{{.Status}}"
```

For debugging a specific job interactively, run a one-off foreground worker instead (stop it with Ctrl+C when done — it won't interfere with the persistent one):

```bash
./vendor/bin/sail artisan queue:work --sleep=1 --tries=3 --timeout=60 --once
```

> Use `--timeout=60` to match production. The `SendDigestEmail` job inherits the worker's timeout; a lower value here can kill jobs that complete fine in production.

### 6. Verify setup

```bash
# Health check
curl http://localhost/up        # framework health
curl http://localhost/v1/health # {"status":"ok"}

# Route list
./vendor/bin/sail artisan route:list --path=v1
```

The route list shows every `/v1/*` endpoint (27 at time of writing); see [API Routes](#api-routes) for the auth and tier of each.

---

## Backend Setup — Production

### Server requirements

- PHP 8.4+ with extensions: `pdo_mysql`, `redis`, `bcmath`, `mbstring`, `xml`
- MySQL 8.4 (the schema uses `BEFORE DELETE` triggers, see the trade-off note below)
- Redis 6+
- Queue worker, Reverb server and a scheduler (`schedule:run` every minute)
- SMTP provider: Mailgun, Postmark, SES, or equivalent

### Deployment (Docker, the repo's path)

`docker-compose.prod.yml` + `scripts/deploy.sh` is the supported production path. Copy `.env.production.example` to `.env`, fill in the `<REQUIRED>` values, then run `./scripts/deploy.sh`. It pulls `main`, rebuilds the `app` image, starts `docker compose up -d`, runs `migrate --force`, `config:cache`, `route:cache`, then `scripts/healthcheck.sh`.

Services in `docker-compose.prod.yml`: `app` (php-fpm, port 9000), `nginx` (80/443, proxies `/app/` to Reverb), `reverb` (WebSocket server, port 8080), `queue` (`queue:work --sleep=3 --tries=3 --backoff=5`, no `--timeout`), `scheduler` (`schedule:work`, runs `RevokeExpiredGrantsJob`, `WarmNpmDownloadsCacheJob`, `SendSlackDigestJob`), `mysql` (8.4), `redis` (7, password required).

The app image is built on `php:8.4-fpm-alpine`, matching `composer.json` (PHP ^8.4). The Composer stage uses `php:8.4-cli-alpine` plus the Composer binary, and `.dockerignore` keeps the host `vendor/`, `bootstrap/cache/*.php` and `storage/logs` out of the image. `public/build/` is still copied from the host, so run `npm run build` before deploying.

`deploy.sh` also runs `php artisan landing:build`. Set `ENTERPRISE_CONTACT_EMAIL`, `LEMON_SQUEEZY_WEBHOOK_SECRET` and the optional `SLACK_*` values in `.env`; `docker-compose.prod.yml` passes them to the `app` container.

### Deployment checklist (bare metal alternative)

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

MAIL_HOST=smtp.mailgun.org
MAIL_PORT=587
MAIL_USERNAME=your-user
MAIL_PASSWORD=your-password
```

### Supervisor config (bare metal only; Docker uses the `queue` service)

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
        fastcgi_pass unix:/var/run/php/php8.4-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }
}
```

### MySQL binary-log trigger permissions — accepted trade-off

`docker/mysql/conf.d/triggers.cnf` sets `log_bin_trust_function_creators=1` instance-wide.

**Why it exists:** the schema uses `BEFORE DELETE` triggers. With binary logging enabled, MySQL refuses trigger creation from non-SUPER users (error 1419) unless this flag is set — and migrations run as the app user, not root.

**Decision (2026-07-09, security audit §4.5):** accepted globally, documented here. Nothing else in the app creates triggers or stored functions, replication is not statement-based, and the scoped alternative (granting the migration user `SUPER`/`SET_USER_ID`) is restricted on managed hosting. Revisit only if statement-based replication or point-in-time-recovery tooling is introduced.

**AWS RDS:** this flag cannot be set via SQL on RDS — set `log_bin_trust_function_creators = 1` in the DB parameter group instead.

---

## Environment Reference

| Variable                    | Required     | Default        | Description                                       |
|-----------------------------|--------------|----------------|---------------------------------------------------|
| `APP_ENV`                   | yes          | `local`        | Set to `production` in prod                       |
| `APP_KEY`                   | yes          | —              | Generate with `artisan key:generate`              |
| `DB_DATABASE`               | yes          | `ticketlens`   | MySQL database name                               |
| `DB_HOST`                   | yes          | `mysql`        | Docker service name in Sail; `127.0.0.1` in prod  |
| `QUEUE_CONNECTION`          | yes          | `redis`        | Must be `redis`, not `sync`, for queued jobs      |
| `CACHE_STORE`               | yes          | `redis`        | Same driver locally and in prod — avoids MySQL contention |
| `SESSION_DRIVER`            | yes          | `redis`        | Same driver locally and in prod — avoids MySQL contention |
| `MAIL_HOST`                 | yes          | `mailpit`      | `mailpit` locally; your SMTP host in prod         |
| `TICKETLENS_SKIP_LICENSE`   | no           | `false`        | Set `true` to bypass LemonSqueezy locally         |
| `TICKETLENS_SKIP_LICENSE`   | no           | `false`        | Honoured only when `APP_ENV` is `local` or `testing` (`LicenseValidationService`, `LicenseSkipGuard`) |
| `LEMONSQUEEZY_VALIDATE_URL` | no           | LemonSqueezy public URL | License validation endpoint (`config/services.php`) |
| `BROADCAST_CONNECTION`      | yes          | `reverb`       | Live Console updates                              |
| `REVERB_APP_ID` / `REVERB_APP_KEY` / `REVERB_APP_SECRET` | yes | — | Reverb credentials. Never alias the secret as `VITE_*` |
| `REVERB_HOST` / `REVERB_PORT` / `REVERB_SCHEME` | yes | `reverb` / `8080` / `http` | Where PHP reaches Reverb (Docker service name) |
| `VITE_REVERB_APP_KEY` / `VITE_REVERB_HOST` / `VITE_REVERB_PORT` / `VITE_REVERB_SCHEME` | dev | — | Where the browser reaches Reverb |
| `OWNER_EMAIL` / `OWNER_PASSWORD` / `OWNER_NAME` | prod | `owner@test.local` / `password` | Platform owner account (`OwnerRecoverySeeder`, `db:reset-to-owner`). Must be overridden in production |
| `SLACK_CLIENT_ID` / `SLACK_CLIENT_SECRET` / `SLACK_SIGNING_SECRET` / `SLACK_REDIRECT_URI` | for Slack | — | Slack integration (`config/services.php`) |
| `ENTERPRISE_CONTACT_EMAIL` | production | `enterprise@ticketlens.test` | Landing page "Talk to us" address; run `php artisan landing:build` after changing. Local and staging mail is caught by Mailpit |
| `INERTIA_SSR_ENABLED`       | no           | `true`         | Set `false` in dev unless an SSR server runs on port 13714 |
| `OWNER_ANALYTICS_CACHE_TTL` | no           | `300`          | Seconds to cache Owner analytics pages            |
| `REDIS_PASSWORD`            | prod         | `null`         | Required by `docker-compose.prod.yml`             |
| `DB_ROOT_PASSWORD`          | prod         | —              | MySQL root password in `docker-compose.prod.yml`  |

`ANTHROPIC_API_KEY`, `GROQ_API_KEY` and `LEMONSQUEEZY_API_KEY` appear in `config/services.php` or older docs but are not read by application code. AI provider keys are stored per user through the Console.

> **LemonSqueezy webhook secret.** `POST /webhooks/lemonsqueezy` is verified against `config('services.lemonsqueezy.signing_secret')`, which reads `LEMON_SQUEEZY_WEBHOOK_SECRET`. Set it in production; an unset secret rejects every webhook with 403. The `X-Signature` header is LemonSqueezy's bare hex HMAC-SHA256 digest (a `sha256=` prefix is also accepted). `docker-compose.prod.yml` aborts when `LEMON_SQUEEZY_WEBHOOK_SECRET` or `ENTERPRISE_CONTACT_EMAIL` is unset.

---

## API Routes

All routes are prefixed `/v1/`. There is no `/api/` segment — this is set via `apiPrefix: ''` in `bootstrap/app.php`. Source of truth: `routes/api.php` (`php artisan route:list --path=v1`).

### Authentication

Every authenticated request sends `Authorization: Bearer <token>`. Raw secrets are never stored; the backend keeps a hash.

| Auth | Endpoints |
|------|-----------|
| None | `GET /v1/health`, `POST /v1/licenses/activate`, `POST /v1/licenses/validate`, `POST /v1/reports` |
| CLI token (`auth.cli`) | `GET /v1/profiles`, `/v1/statuses`, `/v1/templates`; `POST /v1/triage/push`, `/v1/triage/share`, `GET /v1/triage/collisions`; `POST /v1/recall/push`, `GET /v1/recall/pull`; `POST|GET|DELETE /v1/schedule` (needs the Schedules permission, else 403); `/v1/ai-providers` CRUD and `/test` |
| CLI token + Pro tier | `POST /v1/summarize`, `POST /v1/recall/auto-capture`, `GET /v1/team/config`, `GET /v1/recall/settings`, `GET /v1/ai-provider-pool`, `GET /v1/ai-provider-roles`, `POST /v1/consensus` |
| License key + Pro tier (`auth.license`) | `POST /v1/digest/deliver` |

CLI tokens are created in Console > Account. `POST /v1/schedule`, `/v1/summarize` etc. do not accept a license key.

**Rate limits** (`routes/api.php`):

| Limiter | Limit | Keyed by | Routes |
|---------|-------|----------|--------|
| `api-global` | 120/min | IP | all authenticated and licence routes |
| `summarize` | 10/min | token or IP | `/summarize`, `/recall/auto-capture` |
| `compliance` (name kept) | 10/min | token or IP | `/consensus` |
| `schedule` | 5/min | token or IP | `/schedule` |
| `digest` | 20/min | token or IP | `/digest/deliver` |
| `ai-test` | 5/min | token or IP | `/ai-providers/{id}/test` |
| `triage`, `recall`, `profiles`, `team-config`, `recall-settings` | 30/min | token or IP | matching routes |
| `license-act` | 10/min | IP | `/licenses/*` |
| `error-reports` | 10/min | IP | `/reports` |
| `health` | 60/min | IP | `/health` |

**Brute force protection:** 5 consecutive auth failures trigger a 15-minute IP lockout (`auth-fail:{ip}`), for both license keys and CLI tokens.

**Error shape on `/v1/*`:** `ModelNotFoundException` returns `{"error":"Not found"}` 404; other unhandled exceptions return `{"error":"Request failed"}` (`bootstrap/app.php`).

---

### POST /v1/schedule

Create or update a digest schedule for the authenticated user (CLI token, Schedules permission). This endpoint performs an upsert — it always returns `201` whether creating or updating.

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

Trigger an immediate digest email (Pro, license-key auth). Called by `ticketlens triage --digest`. Requires an active digest schedule for the key (404 otherwise).

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

Generate an AI summary of a ticket brief (Pro, CLI token). Used by `ticketlens --summarize --cloud`. Runs the caller's enabled AI providers in priority order; returns 503 if none is configured. Writes a `usage_logs` row with the tokens consumed.

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

About 2,000 test cases in 134 files (Pest). Coverage includes:

- `DigestControllerTest` — schedule creation, job dispatch, 404/422 handling
- `ScheduleControllerTest` — CRUD and rate limiting
- `SummarizeControllerTest` — AI provider mock
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
  -H "Authorization: Bearer <cli-token>" \
  -H "Content-Type: application/json" \
  -d '{"email":"you@example.com","timezone":"America/New_York","deliverAt":"07:00"}' | jq
```

| Response | Meaning |
|----------|---------|
| `201`    | Schedule created or updated (always 201 — this endpoint is an upsert) |
| `422`    | Validation error — check field names, especially `deliverAt` (camelCase) |
| `401`    | Token rejected or IP lockout active. Generate a CLI token in Console > Account |
| `403`    | User lacks the Schedules permission (Pro+) |

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

Only needed on a stale MySQL volume created with a different `DB_DATABASE` (a fresh volume creates the database and grants automatically, see step 4):

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

`ModelNotFoundException` does not extend `HttpException`, so it would surface as a 500 on API routes. `bootstrap/app.php` handles it inside the `Throwable` renderer, scoped to `v1/*`:

```php
->withExceptions(function (Exceptions $exceptions): void {
    $exceptions->render(function (\Throwable $e, \Illuminate\Http\Request $request) {
        if ($e instanceof \Illuminate\Validation\ValidationException
            || $e instanceof \Symfony\Component\HttpKernel\Exception\HttpException) {
            return null;
        }
        if ($request->is('v1/*')) {
            if ($e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException) {
                return response()->json(['error' => 'Not found'], 404);
            }
            $status = method_exists($e, 'getStatusCode') ? $e->getStatusCode() : 500;
            return response()->json(['error' => 'Request failed'], $status);
        }
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

The queue worker is a separate process (the `worker` service in Sail, `queue` in the prod compose). If it is not running, jobs sit in Redis indefinitely. Check with `docker ps`, or run one manually:

```bash
./vendor/bin/sail artisan queue:work --tries=3
```

---

### strip_tags() corrupting Jira content

An earlier version of the summarizer (now `AiService`) called `strip_tags()` on the ticket brief before sending it to the LLM. This silently removed HTML entities and angle-bracket syntax (e.g. `Array<string>`). The fix: strip only null bytes, nothing else.

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

`DigestController::deliver` dispatches the job **first** and only then writes `last_delivered_at`, so a queue-driver failure does not poison the cooldown window and the request can be retried:

```php
SendDigestEmail::dispatch($schedule->id, $request->validated());
$schedule->update(['last_delivered_at' => now()]);
```
