# Database

V2 of MyVivarium uses a **mouse-as-entity** model: a mouse is a first-class
record with stable identity (`mouse_id`), and cages are containers it moves
through. Lineage (sire/dam) and cage moves (`mouse_cage_history`) are
tracked at the mouse level, not duplicated per cage.

## Files

| File | Purpose |
|---|---|
| `schema.sql` | Canonical V2 schema. Apply once to a new database. |
| `import_from_v1.sql` | One-shot import: copies V1 production data into a freshly-initialized V2 database. |
| `erd.png` | ER diagram. **Stale** — depicts the V1 schema and needs regeneration. |

## Set up a fresh V2 database

```bash
mysql -u root -p -e "CREATE DATABASE myvivarium;"
mysql -u root -p myvivarium < database/schema.sql
```

This creates 19 tables. The default admin user is seeded by `schema.sql` —
log in and change the password before doing anything else.

## Import data from a V1 production database

V2 is greenfield: it doesn't upgrade an existing V1 database in place. To
move users from V1, point the import script at a V1 source DB and a fresh
V2 destination DB on the same MySQL server:

```bash
# 1. Back up V1 first.
mysqldump -u root -p myvivarium_v1 > backup_v1_$(date +%Y%m%d).sql

# 2. Apply the V2 schema to a new database (see "Set up" above), naming
#    it something like `myvivarium_v2`.

# 3. Edit database/import_from_v1.sql and replace the placeholder DB names
#    `myvivarium_v1` and `myvivarium_v2` with your actual DB names.

# 4. Run the import.
mysql -u root -p < database/import_from_v1.sql

# 5. Spot-check counts; the file has sanity-check queries at the bottom.
```

If V1 and V2 are on **different MySQL servers**, dump V1's data tables,
restore them into a temporary `myvivarium_v1` schema on the V2 host, then
run the import. The script's footer has the exact `mysqldump` command.

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
