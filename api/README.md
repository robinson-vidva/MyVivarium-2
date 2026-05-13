# MyVivarium REST API v1

Versioned JSON API over the same database that powers the MyVivarium-2 web
UI. Designed as the single entry point for the upcoming AI chatbot, but
usable from any HTTP client.

## Setup

Run the schema migrations once:

```bash
php database/api_setup.php
```

This creates `api_keys`, `pending_operations`, `api_request_log`,
`rate_limit`, and adds three columns to `maintenance`. The script is
idempotent — safe to re-run.

To issue an API key, sign in as admin, open **Admin → API Keys**, pick a
user, set a label and scopes (`read` / `write`), and click **Generate Key**.
The raw value is shown once. Copy it immediately.

## Base URL

```
https://<your-host>/api/v1
```

Every request must be authenticated via the `X-API-Key` header. There is
no anonymous access.

## Authentication

```
X-API-Key: <raw 64-hex-char token>
```

The raw key is only ever stored as `sha256(key)` in the database. The
server hashes incoming headers and looks the hash up. Revoking a key sets
`revoked_at` and takes effect immediately on the next request.

- Missing/unknown key → `401`
- Revoked key → `401`
- Write request without `write` scope → `403`

## Response envelope

Every successful response:

```json
{
  "ok": true,
  "data": ...,
  "meta": { "count": N, "limit": L, "offset": O, "total": T }
}
```

`meta` is present on list endpoints. Errors:

```json
{
  "ok": false,
  "error": { "code": "STRING_CODE", "message": "human readable" }
}
```

HTTP statuses used: `200`, `201`, `202`, `400`, `401`, `403`, `404`,
`409`, `410` (expired pending op), `429`, `500`.

## Permissions

API keys act on behalf of the user who owns them. The same `cage_users`
membership rule the UI enforces applies here:

- **Read:** every approved user can list/read every cage and mouse.
- **Write:** require either `role=admin` or the user being in
  `cage_users` for the target cage. Mice inherit the rule from their
  `current_cage_id`.

## Confirm-before-execute

Destructive operations (DELETE, mouse move, sacrifice, mouse PATCH)
support a two-step confirm flow. First call with header
`X-Confirm-Token: pending`:

```
POST /api/v1/mice/M042/move
X-API-Key: ...
X-Confirm-Token: pending
Content-Type: application/json

{"to_cage_id": "HC-12", "reason": "weaning"}
```

→ `HTTP 202` with a diff and pending operation id:

```json
{
  "ok": true,
  "data": {
    "pending_operation_id": "0c2f9c10-...",
    "diff": {
      "before": {"current_cage_id": "HC-3"},
      "after":  {"current_cage_id": "HC-12"},
      "summary": "Move mouse M042 to HC-12"
    },
    "expires_at": "2026-05-13T12:34:56+00:00"
  }
}
```

Second call (within 5 minutes) with the id as the confirm token actually
executes:

```
POST /api/v1/mice/M042/move
X-API-Key: ...
X-Confirm-Token: 0c2f9c10-...
Content-Type: application/json

{"to_cage_id": "HC-12", "reason": "weaning"}
```

→ `HTTP 200` with the updated mouse. The body is replayed from the stored
pending row, so client-side and stored versions must match the operation.

If `X-Confirm-Token` is omitted, the operation executes immediately
(scripts can opt out of the two-step flow).

Expired tokens return `410 Gone`. Already-executed tokens return `409`.

## Rate limiting

120 requests per minute per API key, fixed minute window. Exceeding the
limit returns `429 Too Many Requests` with a `Retry-After: <seconds>`
header. The counter is stored per key in `rate_limit`.

## Endpoints

### Identity

| Method | Path  | Description |
|--------|-------|-------------|
| GET    | /me   | Authenticated user (id, name, email, role). |

### Mice

| Method | Path | Description |
|--------|------|-------------|
| GET    | /mice | List. Query: `status`, `sex`, `strain`, `cage_id`, `limit` (default 50, max 200), `offset`. |
| GET    | /mice/{id} | Full mouse with current cage and parents. |
| POST   | /mice | Create. Body: `mouse_id`, `cage_id`, `sex`, `dob`, `strain`, `genotype`, `notes`. |
| PATCH  | /mice/{id} | Update editable fields. Supports confirm flow. |
| POST   | /mice/{id}/move | Move mouse. Body: `to_cage_id`, `reason`. Supports confirm flow. |
| POST   | /mice/{id}/sacrifice | Sacrifice. Body: `date`, `reason`. Supports confirm flow. |
| DELETE | /mice/{id} | Soft-delete (status='archived'). Supports confirm flow. |

### Holding cages

| Method | Path | Description |
|--------|------|-------------|
| GET    | /cages/holding | List active holding cages. `limit`/`offset`. |
| GET    | /cages/holding/{id} | Detail with occupants and recent maintenance notes. |
| POST   | /cages/holding | Create. Body: `cage_id`, `pi_name`, `room`, `rack`, `remarks`. |
| PATCH  | /cages/holding/{id} | Update `pi_name`, `remarks`, `room`, `rack`. |

### Breeding cages

| Method | Path | Description |
|--------|------|-------------|
| GET    | /cages/breeding | List. |
| GET    | /cages/breeding/{id} | Detail with pairs, litters, and recent maintenance notes. |
| POST   | /cages/breeding | Create. Body: `cage_id`, `cross`, `male_id`, `female_id`, plus optional cage metadata. |
| PATCH  | /cages/breeding/{id} | Update editable fields (cage + breeding). |

### Maintenance notes

| Method | Path | Description |
|--------|------|-------------|
| GET    | /maintenance-notes | List. Query: `cage_id`, `from`, `to`, `limit`, `offset`. |
| POST   | /maintenance-notes | Create. Body: `cage_id`, `note_text`, `type`. |
| PATCH  | /maintenance-notes/{id} | Edit by author within 24h. |
| DELETE | /maintenance-notes/{id} | Soft-delete by author (or admin). Supports confirm flow. |

### Activity log

| Method | Path | Description |
|--------|------|-------------|
| GET    | /activity-log | List entries the caller can see. Query: `user_id`, `action`, `from`, `to`, `limit`, `offset`. Non-admins only see their own entries. |

## Error codes

| Code | Meaning |
|------|---------|
| `unauthorized` | Missing or invalid API key. |
| `forbidden` | Authenticated but lacks scope/permission. |
| `not_found` | Target entity does not exist. |
| `conflict` | State conflict (already exists, already archived, already executed pending op…). |
| `invalid_argument` | Bad request body or query parameter. |
| `expired` | Pending operation token is past its TTL. |
| `rate_limited` | 120 req/min limit hit. |
| `server_error` | Internal failure. Details in server logs. |

## Examples

```bash
# Identity
curl -H "X-API-Key: $KEY" https://host/api/v1/me

# List 5 mice
curl -H "X-API-Key: $KEY" "https://host/api/v1/mice?limit=5"

# Create a maintenance note
curl -H "X-API-Key: $KEY" -H "Content-Type: application/json" \
     -d '{"cage_id":"HC-3","note_text":"changed bedding","type":"husbandry"}' \
     https://host/api/v1/maintenance-notes

# Move a mouse — two-step
curl -H "X-API-Key: $KEY" -H "X-Confirm-Token: pending" \
     -H "Content-Type: application/json" \
     -d '{"to_cage_id":"HC-12","reason":"weaning"}' \
     -X POST https://host/api/v1/mice/M042/move
# → 202 with pending_operation_id

curl -H "X-API-Key: $KEY" -H "X-Confirm-Token: <pending_operation_id>" \
     -H "Content-Type: application/json" \
     -d '{"to_cage_id":"HC-12","reason":"weaning"}' \
     -X POST https://host/api/v1/mice/M042/move
# → 200 with updated mouse
```
