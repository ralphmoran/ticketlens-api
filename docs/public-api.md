# Public API

Third-party access to TicketLens triage, Recall and digest data. Contract: `GET /v1/public/openapi.json` (OpenAPI 3.1, no key needed).

## Access

- Requires a Pro or Team account. Owners are exempt. Tier and suspension are re-checked on every call.
- Create keys in Console under Settings > API Keys. Five active keys per account. The key is shown once and stored hashed.
- Send `Authorization: Bearer tl_pk_...`. Five wrong keys from one IP lock that IP out for 15 minutes.
- Ticket content never leaves the user's machine. Only data the CLI already pushed is readable.

## Scopes

| Scope | Allows |
|-------|--------|
| `triage:read` | `GET /v1/public/triage/snapshots` |
| `recall:read` | `GET /v1/public/recall/notes` |
| `recall:write` | `POST /v1/public/recall/notes` |
| `digest:send` | `POST /v1/public/digest/send` |

A key acts as its owner. Scopes only narrow what the owner can already do, including the owner's Recall permission and team membership.

## Limits

- 60 requests per minute per key. Team and owner accounts get 120. Exceeding returns 429 with `Retry-After`.
- A digest is sent to each active schedule at most once per 5 minutes.
- Write calls (`POST`) are recorded in the audit log as `api_key.call` with the key id.

## Webhooks

Register up to 5 HTTPS endpoints in Console under Settings > API Keys > Webhooks. Each gets a signing secret, shown once.

| Event | Sent when |
|-------|-----------|
| `triage.pushed` | The CLI pushes a triage snapshot |

- Registration handshake: before saving a URL we POST a signed `webhook.verify` event. Answer 2xx with JSON `{"challenge": "<data.challenge>"}` or the URL is rejected.
- Disabled endpoints (10 failed deliveries) can be re-enabled in Console.
- Payload is metadata only: `snapshot_id`, `profile`, `ticket_count`, `captured_at`. Fetch tickets with `GET /v1/public/triage/snapshots` (`triage:read`).
- Headers: `TicketLens-Signature` (`sha256=` + HMAC-SHA256 of `{timestamp}.{raw body}`), `TicketLens-Timestamp`, `TicketLens-Event`, `TicketLens-Delivery`.
- Reject deliveries whose timestamp is older than 5 minutes. Deduplicate on `TicketLens-Delivery`; retries reuse it.
- Reply with any 2xx within 5 seconds. Other answers retry 5 times (10 s, 1 min, 5 min, 30 min). After 10 consecutive failed deliveries the endpoint is disabled; re-enable it in Console after fixing the receiver.
- URLs must be https on port 443 with a hostname that resolves to public addresses. Redirects are not followed.
- Pro or Team only; tier and suspension are re-checked at send time.

## Versioning and deprecation

- The version is in the path. Everything here is `v1`.
- Additive changes (new fields, endpoints, optional parameters) ship in `v1` without notice.
- Breaking changes ship as a new path version. The old version keeps working for at least 6 months after the deprecation is announced.
- Announcements go in the changelog below. Deprecated responses will carry a `Deprecation` header.

## Changelog

### 2026-10-25: webhooks
- Added the `triage.pushed` webhook, the `webhook.verify` registration handshake, and Console endpoint management.

### 2026-10-22: v1
- Added `GET /v1/public/triage/snapshots`, `GET` and `POST /v1/public/recall/notes`, `POST /v1/public/digest/send`.
- Added per-integrator API keys with scopes.
