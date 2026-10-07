# TicketLens API

![Tests](https://github.com/ralphmoran/ticketlens-api/actions/workflows/test.yml/badge.svg)

Backend API and web console for [TicketLens](https://github.com/ralphmoran/ticket-lens) — the privacy-first Jira context tool for AI coding workflows.

**Stack:** Laravel 13 (PHP 8.4+) · MySQL 8.4 · Redis · Inertia.js · Vue 3 · Tailwind · Laravel Reverb · Laravel Sail (Docker)

---

## What's in this repo

| Layer | Purpose |
|-------|---------|
| `/v1/*` API | License activation/validation, CLI profile sync, triage push/share, Recall sync, digest scheduling, AI summarization and consensus |
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
./vendor/bin/sail artisan db:seed --class=DevSeeder   # test accounts below
npm install && npm run build                              # on the host, see "Building frontend assets"
```

App runs at **http://localhost**. Mailpit (email preview) at **http://localhost:8025**. Live updates need the `reverb` service (WebSocket on port 8080); it comes from `docker-compose.override.yml`, see [`docker/README.md`](docker/README.md).

> Full environment reference and production deployment steps: [`docs/setup-and-deployment.md`](docs/setup-and-deployment.md)

---

## Test accounts

All passwords: `password`. Login at `/console/login`.

| Email | Tier | `is_owner` | Owns group? | Sidebar shows |
|-------|------|:----------:|:-----------:|---------------|
| `free@test.local` | free | false | no | Overview (Analytics teaser) |
| `pro@test.local` | pro | false | no | Overview + Workflow (no Export) + Admin > Brief Templates |
| `team-member@test.local` | team | false | no (seat under manager) | Overview + Workflow + Team + Admin > Recall, Brief Templates |
| `team-manager@test.local` | team | false | yes | Overview + Workflow + Team + Admin |
| `owner@test.local` | team | true | yes | Everything + Owner |

`tier`, team-manager role (group ownership), and platform-owner role (`is_owner`) are independent — each axis gates a different slice of the UI.

---

## Running tests

```bash
./vendor/bin/sail artisan test
```

Tests use an in-memory SQLite database — no running Sail containers required.

The suite has roughly 2,000 test cases across 134 files (counted 2026-10-06). On a host with PHP's default 128M `memory_limit`, run `php -d memory_limit=512M ./vendor/bin/pest`; the default can exhaust memory.

Console JS helpers have Node unit tests in `tests/js/`, using Node's built-in runner and no dependency: `node --test tests/js/*.test.mjs`. `ConsoleNavLinksSkipSameUrlTest` runs `sameUrl.test.mjs` inside the Pest suite, so CI covers it. In CI it fails if `node` is missing.

---

## Architecture

### Console (`/console/*`)

All console routes require session authentication. The owner panel requires `is_owner=true`.

```
/console/login                      Auth (register, forgot/reset password, email verify)
/console/auth/cli                   CLI login authorization

# Overview
/console/dashboard                  Dashboard with trial grant notices
/console/analytics                  AI token usage + savings (all tiers; Free sees a teaser)
/console/admin/stats                Response stats (team context)
/console/account                    API keys, CLI token, profile, avatar (all tiers)
/console/behavior                   Per-user Console behavior: idle-warning timeout (5m/10m/1h) and tone (all tiers)
/console/connections                Tracker profile management (all tiers)
/console/notifications              Notification bell feed (all tiers)
/console/upgrade                    Upgrade page, shown on permission denial

# Workflow
/console/schedules                  Digest scheduling (Schedules permission, Pro+)
/console/digest-history             Digest history (Digests permission, Pro+)
/console/summarize                  AI summarization (Summarize permission, Pro+)
/console/export                     Data export (Export permission, Team+ only; not in the Pro preset)
/console/admin/rules                Workflow rules: stale + custom (WorkflowRules permission, manager-only in the nav)

# Team
/console/queue                      Attention queue (Team+)
/console/team                       Multi-account team view (Team+)

# Admin
/console/admin/team-health          Team health (team lead / manager)
/console/admin/compliance-analytics Compliance analytics (team lead / manager)
/console/admin/recall               Recall notes, attachments, settings (Recall permission; verify/bulk/settings are manager actions)
/console/admin/templates            Brief templates (read: any signed-in user, nav shows it to paid tiers; edit: team manager)
/console/admin/members              Member management (team manager)
/console/admin/process-metrics      Process metrics (team manager)
/console/admin/seats                Seat management (team manager)
/console/admin/integrations         Slack integration: connect workspace, select channel, test (team manager / owner)
/console/admin/alerts               Alert rules, digest schedules (team manager / owner)
/console/admin/digests              Team digests (team manager)
/console/admin/jira                 Team Jira config (team manager)
/console/admin/ai-providers         AI providers, pools, roles, prompts: /ai, /ai-pool, /ai-roles, /ai-provider-pools, /ai-provider-roles (Summarize permission; pools managers)

# Owner panel (is_owner)
/console/owner/dashboard            Owner overview
/console/owner/insights             Platform usage analytics
/console/owner/health               Client health
/console/owner/activity             Client activity
/console/owner/clients              All user accounts
/console/owner/clients/{user}       User detail: tier, grants, audit history
/console/owner/clients/{user}/grants  POST create / DELETE .../{grant} revoke a feature grant
/console/owner/teams                Team groups and membership
/console/owner/licenses             License key management
/console/owner/tiers                Tier -> feature matrix
/console/owner/revenue              Revenue overview
/console/owner/audit                Global append-only audit trail
/console/owner/error-reports        Opt-in CLI error reports
/console/owner/{ai,alerts,digests,integrations}  Owner-scoped views of the admin pages
POST /console/owner/impersonate/{user}   Start impersonating a user (owner only)
DELETE /console/impersonate              Stop impersonating
```

Other non-`/console` web routes: `GET /s/{token}` (shared triage page), `POST /webhooks/lemonsqueezy` (HMAC-signed, CSRF-exempt), Reverb auth at `/broadcasting/auth`.

#### Live updates and session handling

- **Live store (Reverb):** the server publishes events through `SseEventService` (`rule.changed`, `triage.pushed`, `notification.updated`, `members.changed`, `digest.changed`, `usage.recorded`) onto the private channel `group.{groupId}` (`routes/channels.php`; owner, or team/pro members of the group). The browser subscribes through Laravel Echo (`resources/js/composables/useServerEvents.js`, `useLiveReload.js`), which triggers Inertia partial reloads. Needs the `reverb` service and `REVERB_*` / `VITE_REVERB_*` env vars (see `.env.example`).
- **Session-expiry warning:** an idle-warning modal (`sessionGuard.js`, `TlSessionModal.vue`) keeps the session alive via `POST /console/session/keepalive`. Timeout (5m/10m/1h) and tone are per-user settings stored through `/console/behavior`.

#### Console navigation

The console uses a fixed sidebar with collapsible desktop mode. When expanded it shows labelled nav groups (Overview, Workflow, Team, Admin, Owner Panel). When collapsed it shows icon-only navigation; the Owner Panel items appear as a floating popover on hover.

The desktop top header shows a `Group › Page` breadcrumb aligned to the content area, a ⌘K command palette for quick section navigation, a settings shortcut, and an avatar dropdown.

Clicking a sidebar link, the header settings gear or a Settings tab for the page you are already on does not request it again. An Inertia `onBefore` guard (`resources/js/composables/sameUrl.js`, wired by `useSkipSameUrl`) cancels a GET to the current path and query; the hash is ignored. A same-page click during a slow nav visit cancels that visit, so the last click wins, but it never cancels a form submit. The ⌘K palette, notification items and the Upgrade link still request. Pages refresh through the Reverb live store (see above). New nav `<Link>`s must carry `:on-before="skipSameUrl"`, and `ConsoleNavLinksSkipSameUrlTest` fails without it.

### API (`/v1/*`)

Auth is per route group (`routes/api.php`). All use `Authorization: Bearer <token>`; the raw secret is never stored (only a hash). Wrong credentials 5 times from one IP lock that IP out for 15 minutes.

| Auth | Routes |
|------|--------|
| None (IP-throttled) | `GET /v1/health`, `POST /v1/licenses/activate`, `POST /v1/licenses/validate`, `POST /v1/reports` |
| CLI token (`auth.cli`) | `GET /v1/profiles`, `/v1/statuses`, `/v1/templates`; `POST /v1/triage/push`, `/v1/triage/share`, `GET /v1/triage/collisions`; `POST /v1/recall/push`, `GET /v1/recall/pull`; `POST/GET/DELETE /v1/schedule` (also needs the Schedules permission); `/v1/ai-providers` CRUD + `POST /v1/ai-providers/{id}/test` |
| CLI token + Pro tier | `POST /v1/summarize`, `POST /v1/recall/auto-capture`, `GET /v1/team/config`, `GET /v1/recall/settings`, `GET /v1/ai-provider-pool`, `GET /v1/ai-provider-roles`, `POST /v1/consensus` |
| License key + Pro tier (`auth.license`) | `POST /v1/digest/deliver` |

`/v1/summarize` runs the caller's own enabled AI providers (configured in Console, keys stored per user) and returns 503 if none is set up. Summarize, auto-capture and role-prompt calls write a `usage_logs` row with the real tokens consumed.

Attachment limits on Recall sync (`RecallAttachmentStorage`): Free 10 files per call, Pro/Team/Enterprise 50, and 12 MB total per sync request.

Throttles: global 120/min per IP; per route (per bearer token, else IP) summarize and auto-capture 10, schedule 5, digest 20, consensus 10, ai-test 5, triage 30, recall 30, profiles/statuses/templates 30, team-config 30, recall-settings 30; per IP: licenses 10, reports 10, health 60.

---

## Key services

| Service | Responsibility |
|---------|---------------|
| `TierService` | Maps tier → permission bitmask, syncs `users.permissions` |
| `PermissionService` | Computes effective permissions: tier bits OR active grant bits |
| `AuditService` | Append-only audit log for all admin writes |
| `AiService` | Summarization and text generation through the user's enabled providers (Anthropic, Groq, OpenAI-compatible), with fallback chain |
| `AiConsensusService` / `AiProviderPoolService` | Server-side consensus runs and group-shared provider pool |
| `SseEventService` | Publishes live-update events over Reverb |
| `LicenseValidationService` | LemonSqueezy license validation |

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
| Insights / Health / Activity | `/console/owner/{insights,health,activity}` | Platform usage and client health |
| Error reports | `/console/owner/error-reports` | Opt-in CLI error reports |
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

## Slack integration (Features 36–38)

Team managers (and the owner on behalf of any team) can connect a Slack workspace, route alert notifications to a specific channel, and configure needs-response / aging alert rules.

### OAuth flow

1. Manager visits `/console/admin/integrations` → clicks **Connect to Slack**
2. A popup window opens (`useOAuthPopup` composable) so the manager stays in the console
3. Slack OAuth completes in the popup; the callback (`/console/slack/callback`, public — no session needed, context in encrypted state) redirects the popup to `/console/oauth-close` (same origin as the opener) so `window.close()` works cross-origin
4. The popup posts a `postMessage` to the parent, which reloads the `integration` prop via Inertia partial reload — no full page navigation
5. Bot token + workspace metadata stored in `slack_integrations` (one row per group, bot token encrypted at rest)
6. Manager picks a channel from the bot-visible list; saved to `channel_id` / `channel_name`
7. **Test connection** button (`POST /console/admin/integrations/test`) posts a verification message; on failure an inline error banner explains the cause with actionable text (e.g. "invite @TicketLens to the channel")

> **Important:** The redirect actions (`saveChannel`, `disconnect`) use explicit redirects instead of `back()`. Laravel's `back()` reads from the PHP session's previous URL, which the popup window can poison by visiting `/console/oauth-close` in the shared session — explicit redirects prevent the oauth-popup Blade view from appearing in an Inertia modal.

### Alert rules (Features 37–38)

Configured at `/console/admin/alerts`:

- **Compliance-gap alert** — off by default; configurable cooldown (default 24 h) (`/console/admin/alerts/compliance-gap`)
- **Digest schedules** — recurring Slack digests (`/console/admin/alerts/digest-schedules`, run by `SendSlackDigestJob`)
- **Needs-response alert** — fires when a triage snapshot has unacknowledged tickets; configurable cooldown (default 4 h)
- **Aging ticket alert** — fires when tickets pass the aging threshold; configurable cooldown (default 24 h)
- **Custom rules** — per-rule DMs to individual Slack workspace members; scoped cooldown per rule

### Key files

| File | Purpose |
|------|---------|
| `app/Services/SlackService.php` | `buildAuthUrl`, `exchangeCode`, `fetchChannels`, `postMessage`, `postDm`, `fetchMembers` |
| `app/Models/SlackIntegration.php` | One row per team group |
| `app/Models/AlertSetting.php` | Per-group alert toggles + cooldowns |
| `app/Models/CustomAlertRule.php` | Per-rule Slack DM configuration |
| `app/Jobs/EvaluateAlertsJob.php` | Evaluates needs-response + aging flags, fires Slack messages |
| `app/Http/Controllers/Console/SlackOAuthController.php` | OAuth redirect + stateless callback + popup close redirect |
| `app/Http/Controllers/Console/Admin/IntegrationsController.php` | index, channels, saveChannel, sendTest, disconnect |
| `app/Http/Controllers/Console/Admin/AlertsController.php` | Alert settings + custom rule CRUD |
| `resources/js/composables/useOAuthPopup.js` | Reusable popup OAuth flow with postMessage + poll fallback |
| `resources/views/oauth-popup.blade.php` | Popup close page — sends postMessage then calls `window.close()` |
| `database/migrations/*_create_slack_integrations_table.php` | Schema |

### Slack app scopes required

`channels:read`, `groups:read`, `chat:write`, `users:read`, `im:write`

### Local setup

Add to `.env`:

```env
SLACK_CLIENT_ID=your_client_id
SLACK_CLIENT_SECRET=your_client_secret
SLACK_REDIRECT_URI=https://your-ngrok-url.ngrok-free.app/console/slack/callback
```

> The bot must be **invited to the target channel** (`/invite @YourApp` in Slack) before `postMessage` will succeed. The Test connection button will show an actionable error message if the bot lacks channel access.

---

## Scheduled jobs

| Job | Schedule | Purpose |
|-----|----------|---------|
| `SendDigestEmail` | On demand (queued, from `POST /v1/digest/deliver`) | Delivers triage digest emails |
| `EvaluateAlertsJob`, `EvaluateCustomNotifyRulesJob` | On demand (queued on each `POST /v1/triage/push`) | Slack alert evaluation |
| `RevokeExpiredGrantsJob` | Hourly (`routes/console.php`) | Marks expired feature grants as revoked, resyncs permissions |
| `WarmNpmDownloadsCacheJob` | Hourly | Warms the owner client-health cache |
| `SendSlackDigestJob` | Every minute | Self-selects due Slack digest schedules by timezone |

Scheduled jobs need `php artisan schedule:run` every minute (cron) or `schedule:work`. Sail does not start one by default, and `docker-compose.prod.yml` has no scheduler service yet.

The queue worker runs as its own `sail up -d` service (`compose.yaml`'s `worker`) and restarts automatically if it crashes — no manual terminal needed. Check it's up with `docker ps --filter name=ticketlens-api-worker`.

---

## Building frontend assets

The Vue/Inertia frontend is compiled with Vite (rolldown). Build on the host: `node_modules` ships the macOS native binding (`@rolldown/binding-darwin-arm64`), while the Sail container is Linux and has no matching binding, so `sail exec laravel.test npm run build` fails.

```bash
npm install
npm run build        # or: npm run dev (behind the docker/ proxy, see docker/README.md)
```

The production build outputs to `public/build/` (gitignored). After pulling changes that include new or modified Vue components, always rebuild.

---

## Landing page

`/` serves `public/landing.html`, which is generated. Edit `resources/landing/index.html`, never the output. Plan prices and the annual discount are placeholders (`{{pro_monthly}}`, `{{team_annual_total}}`, `{{annual_discount}}`, ...) filled from `config/tiers.php` by `LandingPageBuilder`.

```bash
php artisan landing:build    # rewrite public/landing.html
```

Run it after changing `prices` or `annual_discount_percent`, then commit the output. `LandingPageBuildTest` fails when the committed page is stale, and `scripts/deploy.sh` rebuilds it on every deploy. Static assets live in `public/assets/`.
