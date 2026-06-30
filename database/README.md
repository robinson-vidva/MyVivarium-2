# Database

V2 of MyVivarium uses a **mouse-as-entity** model: a mouse is a first-class
record with stable identity (`mouse_id`), and cages are containers it moves
through. Lineage (sire/dam) and cage moves (`mouse_cage_history`) are
tracked at the mouse level, not duplicated per cage.

## Files

| File | Purpose |
|---|---|
| `schema.sql` | Canonical, **complete** V2 schema — core tables **plus** the REST API and AI-chatbot tables. Apply once to a new database and everything works (no follow-up scripts needed). |
| `install.php` | CLI installer. Reads `.env`, connects to your configured database, applies `schema.sql`. Use `--reset` to drop existing tables first (dev only). |
| `reset_admin.php` | CLI helper to create or reset an admin user with a known email/password. Useful after a `--reset` if you don't remember the seeded admin password. |
| `api_setup.php` | Idempotent upgrader for **existing** V2 databases created before the API/AI tables were folded into `schema.sql`. Adds any missing API/AI tables and columns; a no-op on a database built from the current `schema.sql`. Not needed for fresh installs. |
| `api_schema.sql` | Raw `CREATE TABLE IF NOT EXISTS` form of the API/AI additions, for reference or manual application. Superseded by `schema.sql` for fresh installs; prefer `api_setup.php` for upgrading an old DB (it guards the `ALTER`s). |
| `migrations/` | Dated, idempotent SQL migrations for upgrading older deployments in place. New installs already include these changes via `schema.sql`. |
| `erd.png` | ER diagram. **Stale** — depicts the V1 schema and needs regeneration. |

## Set up a fresh V2 database

The installer pulls credentials from `.env` (the same file `dbcon.php` reads),
so you only need to configure the connection in one place.

```bash
# 1. Configure .env (copy from .env.example if you haven't already).
cp .env.example .env
$EDITOR .env   # set DB_HOST, DB_USERNAME, DB_PASSWORD, DB_DATABASE

# 2. Create the empty database matching DB_DATABASE in your .env.
#    (The installer doesn't CREATE DATABASE itself — it expects you to
#    have created it and granted privileges to DB_USERNAME.)
mysql -e "CREATE DATABASE myvivarium;"

# 3. Apply the schema (installs core + API + AI tables in one shot).
php database/install.php
```

The installer reports each table it creates (31 in total). The default admin
user is seeded by `schema.sql` — log in and change the password before doing
anything else.

> **AI / email encryption key.** The AI-config and email-settings tables store
> secrets as AES-256-CBC ciphertext keyed by `AI_SETTINGS_ENCRYPTION_KEY` in
> `.env`. Set a stable value before saving any provider keys or SMTP
> credentials, or you won't be able to decrypt them later.

### Reset a dev database

`--reset` drops every table in the configured database before applying
the schema. Keeps the database itself, the user, and grants intact.

```bash
php database/install.php --reset
```

Don't run `--reset` against a database that has data you care about.

### Upgrading an older V2 database

If you have a V2 database that predates the API/AI tables (i.e. it was built
from an earlier `schema.sql` that only had the core tables), bring it up to
date without losing data:

```bash
php database/api_setup.php
```

It checks `INFORMATION_SCHEMA` before each change, so it's safe to re-run and
is a no-op once the database is current.

## Import data from a V1 production database

V2 is greenfield: it doesn't upgrade a V1 database in place. To move V1 data
across, use the **JSON export → admin upload** flow. It's portable (no shell
access on either host), works across MySQL servers and operating systems, and
handles the V1→V2 transformation — including the schema differences between a
stock V1 database and V2 — internally.

1. **In V1** ([myvivarium/MyVivarium](https://github.com/myvivarium/MyVivarium)):
   log in as admin → **Administration → Export for V2 Migration**
   (`export_for_v2.php`) → your browser downloads
   `v1_export_YYYYMMDD_HHMMSS.json`.
2. **In V2**: log in as admin → **Administration → Import V1 Data**
   (`admin_import.php`) → upload the JSON. The importer validates the file,
   transforms it (V1 `holding` + `mice` + breeding parents → V2 `mice`; seeds
   `mouse_cage_history`; slims `breeding`), and applies everything in a single
   transaction. If the destination already has data, it stops and asks you to
   confirm an overwrite first.

If you'd rather start from a clean slate, run `php database/install.php --reset`
before importing.

### What the importer handles for you

A stock V1 database is **thinner** than V2 — it has no `status`/`room`/`rack`
columns on `cages`, no genotype/parent-cage columns on `breeding`, and no
`notifications`/`activity_log` tables at all. The JSON importer is written to
absorb these differences:

- Missing columns default sensibly (e.g. `cages.status` → `active`).
- Missing tables are simply skipped (the V1 exporter emits an empty array for
  any table it doesn't have).
- A V1 `mouse_id` reused across cages (V1 only required it unique *per cage*)
  is **re-homed under a disambiguated id** rather than dropped, so no mouse is
  lost to V2's global `mouse_id` primary key. The import report counts these as
  `mice_duplicate_ids_rehomed`.
- Breeding parents referenced by V1 `breeding` but not present as mice are
  synthesized as `archived` mouse rows so the FK resolves.

The import engine itself lives in `includes/v1_import.php` (shared by the admin
page and the test harness); the JSON shape it expects is whatever V1's
`export_for_v2.php` emits.

## Schema overview

### Mouse-as-entity tables (the V2 change)

| Table | Description |
|---|---|
| `mice` | Canonical mouse record. PK `mouse_id` is user-supplied, globally unique, and editable (`ON UPDATE CASCADE` propagates to every FK). Columns: sex, dob, current_cage_id, strain, genotype, ear_code, sire_id/dam_id (self-FK), sire_external_ref/dam_external_ref (text fallback for founders), status enum, sacrificed_at, audit fields. |
| `mouse_cage_history` | Append-only log of every cage assignment. The "current" cage is the row where `moved_out_at IS NULL`. `mice.current_cage_id` is a denormalized pointer kept in sync by the app. |

### Cage and reference tables

| Table | Description |
|---|---|
| `cages` | Cage container: cage_id, PI, room, rack, status (active/archived), remarks. |
| `cage_users` / `cage_iacuc` | Junctions: cage ↔ user, cage ↔ IACUC protocol. |
| `breeding` | Breeding-cage label + parent FKs (`male_id`/`female_id` → `mice.mouse_id`). Per-parent dob/genotype/parent-cage are read via JOIN from `mice` — they no longer live on this row. |
| `litters` | Litter counts (alive/dead/male/female) per breeding cage. |
| `strains` | JAX strain catalog. |
| `iacuc` | IACUC protocols (id, title, file URL). |

### App data tables

| Table | Description |
|---|---|
| `users` | Accounts, roles (admin / vivarium_manager / veterinarian / iacuc_member / user), email-verification & lockout fields. |
| `tasks` | Task tracking (Pending / In Progress / Completed). |
| `reminders` | Recurring reminders (daily / weekly / monthly). |
| `outbox` | Outgoing email queue. |
| `notifications` | In-app notifications. |
| `maintenance` | Per-cage maintenance log (with API note metadata: note_type, deleted_at, updated_at). |
| `notes` | Sticky notes per cage. |
| `files` | File attachments per cage. |
| `activity_log` | Audit trail (action, entity, IP, timestamp). |
| `settings` | System config (key-value pairs). |
| `email_settings` | SMTP transport config managed from the admin Email Settings page (secret rows AES-256-CBC encrypted). |

### REST API tables

| Table | Description |
|---|---|
| `api_keys` | Hashed API tokens with scopes (read/write), expiry, and revocation. |
| `rate_limit` | Per-key request-count window. |
| `api_request_log` | Per-request audit (endpoint, method, status, latency). |
| `pending_operations` | Two-phase confirm records for destructive API writes (body + diff, with expiry). |

### AI chatbot tables

| Table | Description |
|---|---|
| `ai_conversations` / `ai_messages` | Chat history: one row per conversation, one per message (with tool-call/result, suggestions, and token JSON). |
| `ai_usage_log` | Per-response token accounting (prompt/completion/estimated), attributed by user, conversation, provider, and config. |
| `ai_chat_rate` | Per-user chat rate-limit counters (minute/day windows). |
| `ai_settings` | Legacy single-slot AI config (retained; migrated lazily into `ai_configs`). |
| `ai_configs` / `ai_config_settings` | Multi-configuration provider profiles + free-form per-config params/headers. Secret columns AES-256-CBC encrypted. |

## Foreign key behavior

- **Renaming a mouse** (`UPDATE mice SET mouse_id = ?`) cascades to
  `mouse_cage_history`, `breeding.male_id`/`female_id`, and self-FKs in
  `mice.sire_id`/`dam_id` automatically — every reference follows.
- **Renaming a cage** cascades to `mice.current_cage_id`,
  `mouse_cage_history.cage_id`, `breeding.cage_id`, and every other
  cage_id FK.
- **Deleting a cage** sets `mice.current_cage_id` and
  `mouse_cage_history.cage_id` to NULL (history rows survive). The app
  layer additionally marks affected mice as `transferred_out` and closes
  their open history intervals — see `hc_drop.php`.
- **Deleting a mouse** (admin hard delete) cascades through
  `mouse_cage_history`. Offspring's `sire_id`/`dam_id` get set to NULL,
  preserving the offspring rows. The audit-log entry is written *before*
  the delete so the trail survives. See `mouse_drop.php`.

## Sanity checks

```sql
-- Counts after import
SELECT COUNT(*) AS n FROM mice;
SELECT status, COUNT(*) FROM mice GROUP BY status;
SELECT COUNT(*) AS open_intervals FROM mouse_cage_history WHERE moved_out_at IS NULL;

-- Referential integrity (both should be 0)
SELECT COUNT(*) FROM mouse_cage_history h LEFT JOIN mice m ON m.mouse_id = h.mouse_id WHERE m.mouse_id IS NULL;
SELECT COUNT(*) FROM breeding b LEFT JOIN mice m ON m.mouse_id = b.male_id WHERE b.male_id IS NOT NULL AND m.mouse_id IS NULL;

-- Spot-check a mouse and its history
SELECT * FROM mice WHERE mouse_id = '<some_id>';
SELECT * FROM mouse_cage_history WHERE mouse_id = '<some_id>' ORDER BY moved_in_at DESC;
```
