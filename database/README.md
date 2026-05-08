# Database

V2 of MyVivarium uses a **mouse-as-entity** model: a mouse is a first-class
record with stable identity (`mouse_id`), and cages are containers it moves
through. Lineage (sire/dam) and cage moves (`mouse_cage_history`) are
tracked at the mouse level, not duplicated per cage.

## Files

| File | Purpose |
|---|---|
| `schema.sql` | Canonical V2 schema. Apply once to a new database. |
| `install.php` | CLI installer. Reads `.env`, connects to your configured database, applies `schema.sql`. Use `--reset` to drop existing tables first (dev only). |
| `reset_admin.php` | CLI helper to create or reset an admin user with a known email/password. Useful after a `--reset` if you don't remember the seeded admin password. |
| `import_from_v1.sql` | Alternative SQL-based import (cross-database INSERT/SELECT) for users who'd rather operate at the SQL level than the UI uploader. |
| `sync_from_v1.sh` | One-command shell wrapper around `import_from_v1.sql`. Handles same-server runs (just substitutes DB names) and cross-server runs (mysqldump V1 → load into a temp schema on V2 → import → drop temp schema). |
| `erd.png` | ER diagram. **Stale** — depicts the V1 schema and needs regeneration. |

The V1 *export* side (the page that produces the JSON file V2 imports)
lives in the V1 repo. See `admin_import.php` in this V2 repo for the
JSON shape it expects.

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

# 3. Apply the schema.
php database/install.php
```

The installer reports each table it creates. The default admin user is
seeded by `schema.sql` — log in and change the password before doing
anything else.

### Reset a dev database

`--reset` drops every table in the configured database before applying
the schema. Keeps the database itself, the user, and grants intact.

```bash
php database/install.php --reset
```

Don't run `--reset` against a database that has data you care about.

## Import data from a V1 production database

V2 is greenfield: it doesn't upgrade an existing V1 database in place.
There are two paths for moving V1 data across.

There are three flavors of import. Pick one:

- **JSON / UI** — admin clicks export in V1, uploads in V2. No shell access
  needed on either side. (Recommended for non-technical operators.)
- **`sync_from_v1.sh`** — one-command shell wrapper. Same-server or
  cross-server, handles dump/load + import in one shot.
- **`import_from_v1.sql`** — raw SQL you run yourself. Maximum control,
  minimum magic.

### Recommended: JSON export + admin upload

Portable, no shell access on the V2 host required. Works across MySQL
servers, hosting environments, and operating systems.

1. **In V1**: log in as admin → menu → **Administration → Export for V2
   Migration** → browser downloads `v1_export_YYYYMMDD_HHMMSS.json`.
   (The exporter is part of the V1 repo; V2 doesn't ship it.)
2. **In V2**: log in as admin → menu → **Administration → Import V1
   Data** → upload the JSON. The page validates, transforms (V1 holding
   + mice + breeding parents → V2 mice; seeds `mouse_cage_history`;
   slims `breeding`), and applies everything in a single transaction.

If the V2 database isn't empty, run `php database/install.php --reset`
first.

### Alternative: shell sync (`sync_from_v1.sh`)

One command, both same-server and cross-server runs:

```bash
# Same-server (V1 + V2 on the same MySQL host):
database/sync_from_v1.sh \
    --v1-db myvivarium_v1 \
    --v2-db myvivarium_v2

# Cross-server (V1 on a remote host):
database/sync_from_v1.sh \
    --v1-host db.lab.example --v1-user reader --v1-pass '...' --v1-db myvivarium_v1 \
    --v2-host localhost      --v2-user root   --v2-pass '...' --v2-db myvivarium_v2
```

The script: pre-flights that V2 is empty enough → dumps V1 (cross-server
only) → loads into a temp schema on V2 → runs `import_from_v1.sql` →
prints sanity counts → drops the temp schema. V1 is never written to.

### Alternative: raw SQL (`import_from_v1.sql`)

If you want full control over the import. Same transformations as the
shell wrapper, just executed by hand:

```bash
mysqldump -u root -p myvivarium_v1 > backup_v1_$(date +%Y%m%d).sql
# Edit database/import_from_v1.sql to point at your two DB names, then:
mysql -u root -p < database/import_from_v1.sql
```

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
| `users` | Accounts, roles (admin / vivarium_manager / user), email-verification & lockout fields. |
| `tasks` | Task tracking (Pending / In Progress / Completed). |
| `reminders` | Recurring reminders (daily / weekly / monthly). |
| `outbox` | Outgoing email queue. |
| `notifications` | In-app notifications. |
| `maintenance` | Per-cage maintenance log. |
| `notes` | Sticky notes per cage. |
| `files` | File attachments per cage. |
| `activity_log` | Audit trail (action, entity, IP, timestamp). |
| `settings` | System config (key-value pairs). |

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

-- Spot-check a mouse and its history
SELECT * FROM mice WHERE mouse_id = '<some_id>';
SELECT * FROM mouse_cage_history WHERE mouse_id = '<some_id>' ORDER BY moved_in_at DESC;

-- Schema parity
SHOW TABLES;
DESCRIBE mice;
DESCRIBE mouse_cage_history;
DESCRIBE breeding;
```
