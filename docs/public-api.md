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

## Versioning and deprecation

- The version is in the path. Everything here is `v1`.
- Additive changes (new fields, endpoints, optional parameters) ship in `v1` without notice.
- Breaking changes ship as a new path version. The old version keeps working for at least 6 months after the deprecation is announced.
- Announcements go in the changelog below. Deprecated responses will carry a `Deprecation` header.

## Changelog

### 2026-10-22: v1
- Added `GET /v1/public/triage/snapshots`, `GET` and `POST /v1/public/recall/notes`, `POST /v1/public/digest/send`.
- Added per-integrator API keys with scopes.
