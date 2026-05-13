# TicketLens API

![Tests](https://github.com/ralphmoran/ticketlens-api/actions/workflows/test.yml/badge.svg)

Backend API and web console for [TicketLens](https://github.com/ralphmoran/ticket-lens) — the privacy-first Jira context tool for AI coding workflows.

**Stack:** Laravel 11 · MySQL 8 · Redis · Inertia.js · Vue 3 · Tailwind · Laravel Sail (Docker)

---

## What's in this repo

| Layer | Purpose |
|-------|---------|
| `/v1/*` API | License validation, digest scheduling, email delivery, AI summarization |
| `/console/*` web app | Owner control panel + per-user settings dashboard (Inertia + Vue 3) |

---

## Quick start

```bash
git clone https://github.com/ralphmoran/ticketlens-api.git
cd ticketlens-api
composer install
cp .env.example .env
php artisan key:generate
./vendor/bin/sail up -d
./vendor/bin/sail artisan migrate --seed
./vendor/bin/sail exec laravel.test npm run build
```

App runs at **http://localhost**. Mailpit (email preview) at **http://localhost:8025**.

> Full environment reference and production deployment steps: [`docs/setup-and-deployment.md`](docs/setup-and-deployment.md)

---

## Test accounts

All passwords: `password`. Login at `/console/login`.

| Email | Tier | `is_owner` | Owns group? | Sidebar shows |
|-------|------|:----------:|:-----------:|---------------|
| `free@test.local` | free | false | no | Overview |
| `pro@test.local` | pro | false | no | Overview + Workflow |
| `team-member@test.local` | team | false | no (seat under manager) | Overview + Workflow + Team |
| `team-manager@test.local` | team | false | yes | Overview + Workflow + Team + Admin |
| `owner@test.local` | team | true | yes | Everything + Owner |

`tier`, team-manager role (group ownership), and platform-owner role (`is_owner`) are independent — each axis gates a different slice of the UI.

---

## Running tests

```bash
./vendor/bin/sail artisan test
# 153 tests, 475 assertions
```

Tests use an in-memory SQLite database — no running Sail containers required.

---

## Architecture

### Console (`/console/*`)

All console routes require session authentication. The owner panel requires `is_owner=true`.

```
/console/login                      Auth

# Overview
/console/dashboard                  Dashboard with trial grant notices
/console/analytics                  Usage analytics (Pro+)
/console/account                    API keys, settings, profile (all tiers)

# Workflow
/console/schedules                  Digest scheduling (Pro+)
/console/digests                    Digest history (Pro+)
/console/summarize                  AI summarization (Pro+)
/console/compliance                 Compliance checker (Pro+)
/console/export                     Data export (Pro+)

# Team
/console/queue                      Attention queue (Team+)
/console/team                       Multi-account team view (Team+)

# Admin (team manager / owner)
/console/admin/team-health          Team health metrics
/console/admin/members              Member management (team manager)
/console/admin/process-metrics      Process metrics (team manager)
/console/admin/seats                Seat management (team manager)

# Owner panel
/console/owner/dashboard            Owner overview
/console/owner/clients              All user accounts
/console/owner/clients/{id}         User detail — tier, grants, audit history
/console/owner/licenses             License key management
/console/owner/tiers                Tier → feature matrix
/console/owner/revenue              Revenue overview
/console/owner/audit                Global append-only audit trail
/console/owner/grants               Active feature grants
/console/owner/impersonate/{id}     Impersonate a user (owner only)
```

#### Console navigation

The console uses a fixed sidebar with collapsible desktop mode. When expanded it shows labelled nav groups (Overview, Workflow, Team, Admin, Owner Panel). When collapsed it shows icon-only navigation; the Owner Panel items appear as a floating popover on hover.

The desktop top header shows a `Group › Page` breadcrumb aligned to the content area, a ⌘K command palette for quick section navigation, a settings shortcut, and an avatar dropdown.

### API (`/v1/*`)

Every request requires `Authorization: Bearer <license-key>`. The raw key is never stored — only `sha256(key)`.

```
POST   /v1/schedule           Create or update digest schedule
GET    /v1/schedule           Get current schedule
DELETE /v1/schedule           Remove schedule
POST   /v1/digest/deliver     Trigger immediate digest email
POST   /v1/summarize          AI summary via cloud (BYOK)
```

---

## Key services

| Service | Responsibility |
|---------|---------------|
| `TierService` | Maps tier → permission bitmask, syncs `users.permissions` |
| `PermissionService` | Computes effective permissions: tier bits OR active grant bits |
| `AuditService` | Append-only audit log for all admin writes |
| `AnthropicService` | Summarization via Claude API |

### Permission model

Permissions are stored as a bitmask on `users.permissions`. `PermissionService::effective()` ORs the user's tier-based bitmask with any active feature grants, making grants purely additive.

```php
// Effective permissions = tier bits | active grant bits
$bits = $user->permissions | UserFeatureGrant::active()->where('user_id', $user->id)->sum('bit');
```

---

## Owner control panel

Accessible to the single `is_owner=true` account at `/console/owner/*`.

| Page | Route | Purpose |
|------|-------|---------|
| Clients | `/console/owner/clients` | List, search, filter all accounts |
| Client detail | `/console/owner/clients/{id}` | Edit tier, grant/revoke features, view audit history |
| Licenses | `/console/owner/licenses` | License key management |
| Teams | `/console/owner/teams` | Team groups and membership |
| Tiers & Features | `/console/owner/tiers` | Manage tier → feature matrix |
| Revenue | `/console/owner/revenue` | Revenue overview |
| Audit Log | `/console/owner/audit` | Global append-only audit trail |

### Feature grants (Phase 3)

Time-limited feature grants let the owner give a user access to a feature outside their tier — useful for pilots and trials.

- Grants are soft-revoked (`revoked_at` timestamp, never deleted)
- `RevokeExpiredGrantsJob` runs hourly and auto-revokes expired grants
- Active grants show as amber notice cards on the user's dashboard
- Every grant create/revoke is written to the audit log

```bash
# Expire a grant immediately (owner panel Revoke button, or via tinker)
./vendor/bin/sail artisan tinker --execute="
  App\Models\UserFeatureGrant::find(1)->update(['revoked_at' => now()]);
"

# Run the hourly expiry job manually
./vendor/bin/sail artisan schedule:run
```

---

## Scheduled jobs

| Job | Schedule | Purpose |
|-----|----------|---------|
| `SendDigestEmail` | On demand (queued) | Delivers triage digest emails |
| `RevokeExpiredGrantsJob` | Hourly | Marks expired feature grants as revoked, resyncs permissions |

Start the queue worker during local development:

```bash
./vendor/bin/sail artisan queue:work --tries=3 --timeout=60
```

---

## Building frontend assets

The Vue/Inertia frontend is compiled with Vite. Always build inside the Sail container — building on the host may fail due to native module differences:

```bash
./vendor/bin/sail exec laravel.test npm run build
```

The production build outputs to `public/build/` (gitignored). After pulling changes that include new or modified Vue components, always rebuild.
