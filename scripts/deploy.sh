#!/usr/bin/env bash
# deploy.sh — Idempotent production deploy for TicketLens backend
#
# Usage:
#   ./scripts/deploy.sh
#
# Prerequisites:
#   - Docker and docker compose v2 installed
#   - .env file present and filled in (cp .env.production.example .env)
#   - SSL cert and key at the paths specified by SSL_CERT_PATH / SSL_KEY_PATH in .env
#
# Idempotency guarantees:
#   - `docker compose up -d` only restarts containers whose image changed
#   - `php artisan migrate --force` is a no-op when schema is current
#   - `config:cache` and `route:cache` are always safe to re-run
#   - Running this script on an already-healthy deployment is harmless

set -euo pipefail

COMPOSE_FILE="docker-compose.prod.yml"

echo "==> Pulling latest code"
git pull origin main

echo "==> Building app image (no cache to pick up PHP/composer changes)"
docker compose -f "$COMPOSE_FILE" build --no-cache app

echo "==> Starting / restarting services"
docker compose -f "$COMPOSE_FILE" up -d

echo "==> Waiting for app container to be healthy"
# docker compose wait exits once all named services reach healthy/exited state.
# The app service depends_on mysql/redis with condition: service_healthy, so
# this implicitly waits for the full dependency chain to be ready.
timeout 120 docker compose -f "$COMPOSE_FILE" wait app || {
  echo "✗ App container did not become healthy within 120s — aborting"
  docker compose -f "$COMPOSE_FILE" logs --tail=50 app
  exit 1
}

echo "==> Running database migrations"
docker compose -f "$COMPOSE_FILE" exec app php artisan migrate --force

echo "==> Caching config and routes"
docker compose -f "$COMPOSE_FILE" exec app php artisan config:cache
docker compose -f "$COMPOSE_FILE" exec app php artisan route:cache

echo "==> Running health check"
./scripts/healthcheck.sh

echo ""
echo "✓ Deploy complete."
