#!/usr/bin/env bash
# healthcheck.sh — Verify the app responds to GET /v1/health with HTTP 200
#
# Usage:
#   ./scripts/healthcheck.sh [URL]
#
# Arguments:
#   URL   Optional. Defaults to ${APP_URL}/v1/health if APP_URL is set,
#         otherwise http://localhost/v1/health.
#
# Exit codes:
#   0 — healthy
#   1 — unhealthy (non-200 response or no response within 30s)

set -euo pipefail

TARGET_URL="${1:-}"
if [ -z "$TARGET_URL" ]; then
  if [ -n "${APP_URL:-}" ]; then
    TARGET_URL="${APP_URL}/v1/health"
  else
    TARGET_URL="http://localhost/v1/health"
  fi
fi

TIMEOUT=30
INTERVAL=2
ELAPSED=0
HTTP_STATUS=""

echo "==> Health check: $TARGET_URL (timeout: ${TIMEOUT}s)"

while [ "$ELAPSED" -lt "$TIMEOUT" ]; do
  HTTP_STATUS=$(curl -s -o /dev/null -w "%{http_code}" --max-time 5 "$TARGET_URL" 2>/dev/null || echo "000")
  if [ "$HTTP_STATUS" -eq 200 ]; then
    echo "✓ Health check PASSED — HTTP $HTTP_STATUS"
    exit 0
  fi
  echo "  HTTP $HTTP_STATUS — retrying in ${INTERVAL}s (${ELAPSED}/${TIMEOUT}s elapsed)"
  sleep "$INTERVAL"
  ELAPSED=$((ELAPSED + INTERVAL))
done

echo "✗ Health check FAILED — last HTTP status: ${HTTP_STATUS:-none} after ${TIMEOUT}s"
exit 1
