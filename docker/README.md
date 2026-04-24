# Local Dev Stack (Sail + nginx reverse proxy)

This directory holds the local-dev nginx reverse proxy that sits in front of
Sail's `laravel.test` container, enabling bare-hostname access to both the
Console and the static landing page.

## What it does

| Hostname (host machine) | Routed to |
|-------------------------|-----------|
| `http://ticketlens.test/`         | static landing (`../../ticket-lens/site/`) |
| `http://ticketlens.test/console/*`| Laravel Console (`laravel.test:80`)        |
| `http://ticketlens.test/@vite/`, `/resources/`, `/node_modules/` | Vite dev server (`laravel.test:5173`) |
| `http://api.ticketlens.test/v1/*`, `/webhooks/*`, `/up` | Laravel API |
| `http://api.ticketlens.test/`     | redirect to `http://ticketlens.test/` |

## One-time host setup

1. Add to `/etc/hosts`:
   ```
   127.0.0.1 ticketlens.test
   127.0.0.1 api.ticketlens.test
   ```
2. Install [OrbStack](https://orbstack.dev) (or Docker Desktop).
3. From `ticketlens-api/`:
   ```
   cp .env.example .env
   # edit .env: APP_URL=http://ticketlens.test, DB_CONNECTION=mysql, ...,
   #           INERTIA_SSR_ENABLED=false   (avoid 30s SSR timeout in dev)
   composer install
   ./vendor/bin/sail up -d
   ./vendor/bin/sail artisan key:generate
   ./vendor/bin/sail artisan migrate --seed
   ./vendor/bin/sail artisan db:seed --class=DevSeeder
   ./vendor/bin/sail npm install
   ./vendor/bin/sail npm run dev
   ```

## Files

- `nginx-proxy.conf` — nginx server blocks routing by `Host` header.
- `_proxy-headers.conf` — shared proxy headers (Host, X-Forwarded-*, WS upgrade).
- `../docker-compose.override.yml` — adds the `proxy` service to Sail's stack
  and forces same-origin Vite asset URLs via env vars.

## Why a reverse proxy at all?

Two hostnames pointing at `127.0.0.1` can't both bind port 80. The proxy
fans them out to separate upstream services (Laravel, static site, Vite).
Same-origin Vite avoids Laravel's default CSP blocking cross-origin asset
fetches from `localhost:5173`.

## Disabling Inertia SSR locally

Inertia's default config tries to render via a Node SSR server on port 13714.
If that service isn't running, every Inertia render hangs ~30 seconds before
falling back to client-render. Set `INERTIA_SSR_ENABLED=false` in `.env` for
local dev. Production can opt back in once an SSR service is provisioned.
