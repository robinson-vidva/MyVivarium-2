# MyVivarium REST API v1

Versioned JSON API over the same database that powers the MyVivarium-2 web
UI. Designed as the single entry point for the upcoming AI chatbot, but
usable from any HTTP client.

## Specification

The OpenAPI 3.1 spec at [`api/openapi.yaml`](openapi.yaml) is the single
source of truth for the API surface. Three audiences read from it:

- **Humans** — interactive Swagger UI at `/api/docs/` (admin only). Linked
  from the admin menu next to "API Keys" and "AI Configuration".
- **Developers** — the spec itself is served at `/api/v1/openapi.yaml` and
  `/api/v1/openapi.json` (no auth required; the spec describes the surface
  and contains no secrets).
- **Chatbot** — `ai_chat.php` loads the spec on every turn and emits the
  Groq / OpenAI tool array directly from the operations it finds. There
  is no hardcoded tool list. `operationId` becomes the tool name,
  `summary` + `description` becomes the tool description, and the
  `x-mv-destructive` extension marks ops that need confirm-before-execute.

### Workflow for adding a new endpoint

1. Implement the route handler in `api/index.php` (delegating real work to
   a function in `services/`).
2. Add a matching path entry to `api/openapi.yaml`. Include `operationId`,
   `summary`, `description`, `tags`, `parameters`, `requestBody`, and at
   least the success response. Mark the op with `x-mv-destructive: true`
   (PATCH/DELETE/move/sacrifice) or `x-mv-safe-write: true` (creates) or
   `x-mv-read: true` (GETs) so the chatbot routes it correctly.
3. Run `php tests/openapi_validate.php` to confirm router and spec agree.
4. Run `php tests/chatbot_unit_test.php` to confirm the chatbot still
   resolves every operationId.
5. The chatbot picks up the new endpoint on the next request — no code
   change needed in `ai_chat.php` or `includes/chatbot_helpers.php`.

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
(scripts can opt out of the two-step flow). In other words, **scripts can
bypass confirmation by omitting the header entirely** — the confirm flow
is opt-in, not enforced. Send `X-Confirm-Token: pending` only when you
want a preview-then-commit handshake.

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
| GET    | /mice | List. Query: `status`, `sex`, `strain`, `cage_id`, `limit` (default 50, max 200), `offset`, `include_deleted` (admin-only opt-in). |
| GET    | /mice/{id} | Full mouse with current cage and parents. `include_deleted=true` lets admins fetch archived mice. |
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
| GET    | /maintenance-notes | List. Query: `cage_id`, `from`, `to`, `limit`, `offset`, `include_deleted` (admin-only). |
| GET    | /maintenance-notes/{id} | Read one note. `include_deleted=true` lets admins read soft-deleted ones. |
| POST   | /maintenance-notes | Create. Body: `cage_id`, `note_text`, `type`. |
| PATCH  | /maintenance-notes/{id} | Edit by author within 24h. |
| DELETE | /maintenance-notes/{id} | Soft-delete by author (or admin). Supports confirm flow. |

### Activity log

| Method | Path | Description |
|--------|------|-------------|
| GET    | /activity-log | List entries the caller can see. Query: `user_id`, `action`, `from`, `to`, `limit`, `offset`. Non-admins only see their own entries. |

### Tasks (read-only — write ships in batch 2)

| Method | Path | Description |
|--------|------|-------------|
| GET    | /tasks | List visible tasks. Query: `status` (open/done/all, default open), `assigned_to_me`, `cage_id`, `limit`, `offset`. |
| GET    | /tasks/{id} | One task. Non-admins must be the author or an assignee. |

### Reminders (read-only — write ships in batch 2)

| Method | Path | Description |
|--------|------|-------------|
| GET    | /reminders | List visible reminders, each with an estimated `next_due`. Query: `upcoming` (bool, default true), `cage_id`, `limit`, `offset`. |
| GET    | /reminders/{id} | One reminder. Same visibility rules as tasks. |

### Calendar feed

| Method | Path | Description |
|--------|------|-------------|
| GET    | /calendar | Unified, time-ordered feed of upcoming tasks and reminders. Each event carries `source_type` (`task` or `reminder`) and `source_id`. Query: `from`, `to`, `limit`. |

### Notifications

| Method | Path | Description |
|--------|------|-------------|
| GET    | /notifications | List the caller's notifications. Query: `read` (`all`/`unread`/`read`, default `unread`), `limit`, `offset`. Strictly per-user — never returns another user's rows. |
| GET    | /notifications/unread-count | Single `{unread_count}` payload. |

### Mice — history & offspring

| Method | Path | Description |
|--------|------|-------------|
| GET    | /mice/{id}/history | Chronological cage-move history. |
| GET    | /mice/{id}/offspring | Mice whose sire_id or dam_id matches this mouse. |

### Strains

| Method | Path | Description |
|--------|------|-------------|
| GET    | /strains | List strains. Query: `search`, `limit`, `offset`. Visible to every authenticated user. |
| GET    | /strains/{id} | One strain by str_id. |

### IACUC protocols

| Method | Path | Description |
|--------|------|-------------|
| GET    | /iacuc | List protocols visible to the caller. Non-admins only see protocols linked to at least one cage they have access to. |
| GET    | /iacuc/{id} | One protocol by iacuc_id. |

### Dashboard

| Method | Path | Description |
|--------|------|-------------|
| GET    | /dashboard/summary | Single permission-filtered counts object (mice, cages, tasks, notifications, recent activity, upcoming reminders). |

### Cage sidecars (read-only)

| Method | Path | Description |
|--------|------|-------------|
| GET    | /cages/holding/{id}/users   | List assigned users for a holding cage. |
| GET    | /cages/breeding/{id}/users  | List assigned users for a breeding cage. |
| GET    | /cages/holding/{id}/iacuc   | List IACUC protocols attached to a holding cage. |
| GET    | /cages/breeding/{id}/iacuc  | List IACUC protocols attached to a breeding cage. |
| GET    | /cages/breeding/{id}/lineage | Sire, dam, parent (origin) cages, litters, and currently-alive offspring. |
| GET    | /cages/holding/{id}/card-data  | Structured card-rendering payload (PI, IACUC, users, occupants). |
| GET    | /cages/breeding/{id}/card-data | Structured card-rendering payload (PI, IACUC, users, pairs, latest litter). |

### Account (self-service)

| Method | Path | Description |
|--------|------|-------------|
| GET    | /me/profile | Current user's editable profile. Whitelisted columns only: `id`, `name`, `email`, `position`, `role`, `status`, `email_verified`, `initials`. **Never** returns password hashes, reset tokens, or any third-party / session tokens. |

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

## AI Configuration

MyVivarium supports three LLM provider options: **Groq** (free tier
available, fast, open models), **OpenAI** (paid, most reliable tool
calling), and **Custom** — a preset-driven third option that lets admins
point the chatbot at an institutional gateway (Azure APIM, Azure OpenAI
direct, OpenRouter, DeepSeek, vLLM, or anything else that speaks an
OpenAI-compatible or Anthropic Messages shape). Switch between them in
**Admin → AI Configuration**. All three are dispatched through a single
function in `includes/llm_provider.php`; the chatbot itself does not know
which one is active — translation between OpenAI and Anthropic shapes (for
the Azure Anthropic preset) happens entirely inside `llm_chat_completions()`.

Admins manage chatbot settings under **Admin → AI Configuration**
(`manage_ai_config.php`). Values are stored in the `ai_settings` table:

| `setting_key`     | Meaning |
|-------------------|---------|
| `llm_provider`    | `"groq"` (default), `"openai"`, or `"custom"` — which provider the chatbot uses. |
| `groq_api_key`    | Groq Cloud API key (encrypted). |
| `groq_model`      | One of `llama-3.3-70b-versatile` (default), `llama-3.1-8b-instant`, `openai/gpt-oss-120b`, `openai/gpt-oss-20b`. |
| `openai_api_key`  | OpenAI API key (encrypted). |
| `openai_model`    | One of `gpt-5.4-mini` (default), `gpt-5.4-nano`, `gpt-5.4`, `gpt-4.1-nano`, `gpt-4.1-mini`. |
| `custom_preset`        | One of `"azure_openai"`, `"azure_anthropic"`, `"openai_compatible"`. Selects which preset to use under the Custom provider. |
| `custom_api_key`       | API key for the configured custom provider (encrypted). |
| `custom_resource_url`  | (azure_openai) Azure OpenAI resource endpoint or APIM gateway URL, e.g. `https://my-resource.openai.azure.com`. |
| `custom_deployment`    | (azure_openai) The Azure deployment name, e.g. `gpt-4o-mini`. |
| `custom_api_version`   | (azure_openai) Azure API version string. Defaults to `2024-10-21`. |
| `custom_base_url`      | (azure_anthropic / openai_compatible) Provider base URL. Chat completions URL is appended internally. |
| `custom_model`         | (azure_anthropic / openai_compatible) Exact model id the provider expects. |
| `custom_token_field`   | (openai_compatible) `"max_tokens"` (default) or `"max_completion_tokens"` for newer reasoning models. |
| `system_prompt`   | System prompt prepended to every chatbot turn. A hardcoded security block, a hardcoded response-formatting block, and a hardcoded follow-up-suggestion block are also prepended, ahead of this value, and cannot be edited away by admins. See [`docs/chatbot.md`](../docs/chatbot.md) for formatting + suggestion details. |
| `chatbot_enabled` | `"1"` or `"0"`. |
| `ai_rate_limit_messages_per_minute` | Per-user message cap, fixed 60-second window. Default `10`, min `1`, max `60`. Counts user-typed messages only — tool calls and LLM round-trips do not count. |
| `ai_rate_limit_messages_per_day`    | Per-user daily cap, resets at midnight UTC. Default `200`, min `10`, max `5000`. |

All three providers can be configured side by side — the admin can prepare
OpenAI and Custom credentials in advance and switch over later. Switching
to a provider whose key is empty (or, for Custom, whose preset has missing
required fields) is rejected with an inline error.

### Custom LLM Provider

The Custom option exists so that admins can wire MyVivarium up to whatever
their institution provisions — Azure OpenAI behind APIM, an Anthropic
deployment routed through APIM, a self-hosted vLLM, OpenRouter, DeepSeek,
Cerebras, Together, or anything else that speaks an OpenAI-compatible or
Anthropic Messages API. The admin UI is preset-driven: pick the preset
and the form asks for the few fields that preset needs and nothing else.

Three presets ship today:

**Azure OpenAI (GPT models)** — `custom_preset = "azure_openai"`. For
Azure OpenAI service or an Azure APIM gateway routing to a GPT-family
deployment.

Required fields: Resource URL, Deployment name, API version (default
`2024-10-21`), API key.

Internal behavior: the request URL is built as
`{resource_url}/openai/deployments/{deployment}/chat/completions?api-version={version}`.
The auth header is `api-key:` with NO `Bearer ` prefix. The model name is
NOT included in the request body (the deployment URL identifies the
model). The token field is `max_tokens` for most deployments, or
`max_completion_tokens` when the deployment name starts with `gpt-5`,
`o1`, or `o3` (newer reasoning models reject the legacy field).

Azure's GPT-5 family (`gpt-5`, `gpt-5.5`, …) and the reasoning models
(`o1`, `o3`) reject any custom `temperature` value — only the model
default (1) is accepted. MyVivarium handles this automatically: when the
deployment name matches the same `gpt-5` / `o1` / `o3` case-insensitive
prefix rule, the `temperature` field is omitted from the request body
entirely (for both the chatbot path and the Test Connection probe). All
other deployments continue to send `temperature: 0.2` as before. The
Anthropic Messages translation (`azure_anthropic` preset) does not send
`temperature` at all, so the rule does not apply there.

**Azure Anthropic / Claude via APIM** — `custom_preset = "azure_anthropic"`.
For Anthropic Messages format routed through Azure APIM or a similar gateway.

Required fields: Base URL, Model (a dropdown of `claude-opus-4-5`,
`claude-sonnet-4-6`, `claude-haiku-4-5`, plus a Custom… escape hatch),
API key.

Internal behavior: the request URL is built as
`{base_url}/anthropic/v1/messages`. The auth header is `x-api-key:` with no
prefix, plus an `anthropic-version: 2023-06-01` extra header. The request
body is in Anthropic Messages format — the `system` prompt is hoisted to a
top-level field, the messages array uses `{type:'text'}` / `{type:'tool_use'}`
content blocks, and tool definitions become a top-level `tools` array with
`input_schema`. The Anthropic response is translated BACK to OpenAI shape
inside `llm_chat_completions()`, so `ai_chat.php` sees the same
`choices[0].message` shape it always sees. Token field is `max_tokens`,
which Anthropic requires (default of 1024 when not otherwise set).

**OpenAI-compatible (generic)** — `custom_preset = "openai_compatible"`.
For any provider whose API matches OpenAI chat completions: OpenRouter,
DeepSeek, Cerebras, Together, vLLM, LM Studio, custom proxies, etc.

Required fields: Base URL, Model, API key. Optional: token field override
(`max_tokens` vs `max_completion_tokens`).

Internal behavior: the request URL is built as `{base_url}/chat/completions`,
the auth header is `Authorization: Bearer <key>`, the body is standard
OpenAI chat completions with the model name in the body.

#### Example configuration

A representative Azure OpenAI deployment pattern (generic — substitute
your own values, do not paste real credentials into the README):

```
Provider           = Custom
Preset             = Azure OpenAI (GPT models)
Resource URL       = https://apim-my-org.azure-api.net
Deployment name    = gpt-4o-mini
API version        = 2024-10-21
API key            = <subscription key issued by APIM>
```

When saved, the chatbot will POST to
`https://apim-my-org.azure-api.net/openai/deployments/gpt-4o-mini/chat/completions?api-version=2024-10-21`
with `api-key: <key>` and the standard OpenAI message/tool body.

#### Test Connection per preset

The "Test Connection" button works for Custom too. Each preset uses a
different probe so that the test succeeds even on gateways that don't
expose `/models`:

- **azure_openai** — minimal POST to the same chat completions URL with
  `max_tokens: 1` and a one-word user message.
- **azure_anthropic** — minimal POST to `/anthropic/v1/messages` with
  `max_tokens: 1` and a one-word user message. Success = HTTP 200 with a
  content array.
- **openai_compatible** — GET `{base_url}/models` first; if the endpoint
  returns 404 or isn't supported, fall back to the minimal chat completions
  probe.

Errors from the upstream provider are surfaced verbatim. The API key is
never echoed back to the browser, the HTML, or any log line.

#### Validation when switching to Custom

Saving with `provider = custom` requires:

- a preset selected,
- all of the selected preset's required fields populated,
- a Custom API key configured.

Any missing field blocks the Save with an inline error like
`Cannot switch to Custom — Resource URL, API key is required. Configure
the custom provider first, then switch.` Per-field values from other
presets remain stored, so switching presets back and forth never loses
configuration.

#### Adding a fourth preset later

The preset system is intentionally narrow — add a new preset by:

1. Adding a new constant + entry to the preset switch in
   `llm_get_custom_config()` in `includes/llm_provider.php`. Build the
   `request_url`, `auth_header`, `auth_prefix`, `body_format`, and
   `token_field` for the new provider. If the body shape isn't OpenAI or
   Anthropic, add a translation pair (input → provider, response → OpenAI).
2. Adding the corresponding `custom-preset-group` block to
   `manage_ai_config.php` with the fields the preset needs.
3. Adding a unit test in `tests/llm_provider_custom_test.php`.

Test connection and chatbot dispatch will pick up the new preset
automatically as long as `llm_get_custom_config()` returns the same shape
as the existing presets.

#### Note on rate limits

Custom providers often have lower rate limits than the big commercial
providers — Azure APIM gateways in particular are commonly capped to a few
hundred or a few thousand tokens per minute. For example, the **FlyerGPT
gateway at FlyerLab** runs on a 1000 TPM (tokens-per-minute) quota.
MyVivarium's existing per-user message rate limit and conversation
trimming in `ai_chat.php` work identically regardless of provider, but
admins running against a low-budget gateway should expect "rate limit
exceeded" errors more often than they would on Groq or OpenAI directly.
No code change is needed to support such gateways — the constraint is
documented here purely so deployers know what to expect.

### Encryption model

- Algorithm: AES-256-CBC via `openssl_encrypt` / `openssl_decrypt`.
- Storage format in `ai_settings.setting_value`:
  `base64(iv) ":" base64(ciphertext)`.
- A fresh random 16-byte IV is generated per `ai_settings_set()` call —
  values never share an IV.
- The encryption key lives in the `.env` file as
  `AI_SETTINGS_ENCRYPTION_KEY` (64 hex chars = 32 random bytes). The
  first time an admin opens the AI Configuration page, the application
  auto-generates one and writes it back to `.env` atomically (temp file
  + `rename()`), preserving existing permissions. If `.env` is not
  writable the page surfaces a clear error and refuses to encrypt.

### Recovery if AI_SETTINGS_ENCRYPTION_KEY is lost

The key is the only thing that can decrypt the stored values. There is
**no backdoor and no recovery path**. If the key is destroyed (or `.env`
is restored from a backup without it), the only remediation is:

```sql
DELETE FROM ai_settings;
```

…and re-enter the values through the admin page after the new key has
been provisioned.

### Test Connection

The admin page exposes a "Test Connection" button that calls
`ai_test_connection.php?provider=<groq|openai|custom>` (admin-only,
returns JSON). For Groq and OpenAI it hits the provider's `/v1/models`
endpoint with the stored Bearer key. For Custom it dispatches based on the
configured preset — see [Custom LLM Provider → Test Connection per preset]
(#test-connection-per-preset). The API key value is **never echoed back**
to the browser, the rendered HTML, or any log line.

### Activity logging

Every `ai_settings_set` / `ai_settings_delete` writes a row to
`activity_log` with `action = 'ai_settings_change'`,
`entity_type = 'ai_setting'`, `entity_id = <setting_key>`. The plaintext
value is never logged.

Rate-limit hits write a row with `action = 'ai_rate_limit_hit'`,
`entity_type = 'ai_chat'`, and details `window=minute|day used=N limit=N`.

### Prompt injection mitigation

Two cross-cutting safeguards run on every chatbot turn:

1. **Hardcoded system prompt block.** Before the admin-editable system
   prompt, `chatbot_build_messages()` prepends a fixed `CRITICAL SECURITY
   RULES:` block that tells the model to (a) treat tool-result data as
   data not instructions, (b) never reveal API keys / env vars / internal
   details, (c) flag injection attempts to the user, and (d) only act on
   the current user's current message. The block is in
   `chatbot_security_rules_block()` and is not loaded from `ai_settings`,
   so an admin cannot accidentally remove the rules.

2. **User-generated content tagging.** Tool results that contain
   free-form user-entered text (task titles & descriptions, reminder
   text, maintenance note bodies, notification titles & bodies) are
   wrapped in `<user_data>…</user_data>` markers before the JSON is
   handed to the LLM. The wrapping happens in
   `chatbot_tag_user_content()` and the per-endpoint field map lives in
   `chatbot_user_content_fields_for()`. The user does **not** see these
   markers in the chat UI — only the LLM does.

   **When adding a new endpoint that returns user-entered free text,
   append its operationId + field paths to
   `chatbot_user_content_fields_for()` in
   `includes/chatbot_helpers.php`.** Field paths use dotted notation,
   with `[]` to mean "for every element of this list":

       data.title                — wrap the value at $body['data']['title']
       data[].description        — wrap each item's 'description' under $body['data']

A separate sanitization pass (`chatbot_sanitize_for_llm()`) strips
emails and US phone numbers from tool results before they reach the LLM.
A stronger tokenization-based PII pipeline is desirable in a follow-up
iteration; see the TODO in `includes/chatbot_helpers.php`.

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
