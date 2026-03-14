# Database Schema and Migrations

This folder contains the database schema, migration scripts, and tools for MyVivarium v2.

## Files Overview

| File | Purpose |
|------|---------|
| `schema.sql` | Complete v2 schema (17 tables) for **new installations** |
| `migrate_v1_to_v2.sql` | **In-place upgrade** from MyVivarium v1 to v2 |
| `migrate_v1_to_v2.sh` | **Full migration** script (old DB -> new DB, with verification) |
| `migrate_add_parent_cage.sql` | Adds parent cage columns to breeding table |
| `erd.png` | Entity-Relationship Diagram |

## New Installation

Use `schema.sql` to create all tables with the latest v2 structure:

```bash
mysql -u root -p your_database_name < database/schema.sql
```

## Database Tables

### Core Tables
| Table | Description |
|-------|-------------|
| `users` | User accounts (name, email, role, password, login security fields) |
| `cages` | Base cage info (cage_id, PI, quantity, status, room, rack) |
| `cage_users` | Junction: cage-to-user assignments |
| `cage_iacuc` | Junction: cage-to-IACUC protocol associations |
| `strains` | Mouse strains (JAX ID, name, aliases, RRID, URL) |
| `settings` | System settings (key-value pairs) |

### Cage Data Tables
| Table | Description |
|-------|-------------|
| `holding` | Holding cage details (strain, DOB, sex, parent cage, genotype) |
| `breeding` | Breeding cage details (cross, male/female IDs, DOBs, genotypes, parent cages) |
| `litters` | Litter records (DOM, DOB, pup counts, sex counts) |
| `mice` | Individual mouse tracking (mouse ID, genotype, notes) |
| `files` | File attachments per cage |
| `notes` | Sticky notes per cage |
| `maintenance` | Maintenance log entries per cage |

### Task & Reminder Tables
| Table | Description |
|-------|-------------|
| `tasks` | Tasks with title, description, assignment, status (Pending/In Progress/Completed), due date |
| `reminders` | Recurring reminders (daily/weekly/monthly) with status (active/inactive for archiving) |
| `outbox` | Email queue for reminder notifications (pending/sent/failed) |

### Audit Tables
| Table | Description |
|-------|-------------|
| `activity_log` | Audit trail (user, action, entity, details, IP, timestamp) |
| `iacuc` | IACUC protocols (ID, title, file URL) |

## Key Schema Details

### Status Fields
- **Cages**: `status` ENUM('active', 'archived') -- supports cage archiving workflow
- **Reminders**: `status` ENUM('active', 'inactive') -- supports reminder archiving workflow
- **Tasks**: `status` ENUM('Pending', 'In Progress', 'Completed') -- task progress tracking
- **Outbox**: `status` ENUM('pending', 'sent', 'failed') -- email delivery tracking

### Foreign Keys
- Cage deletions cascade through `cage_users`, `cage_iacuc`, and related tables
- Cage ID renames propagate via `ON UPDATE CASCADE` on all foreign keys
- User deletions use `ON DELETE SET NULL` for ownership fields, `ON DELETE CASCADE` for assignments

## Upgrading from MyVivarium v1

### Option A: In-Place Upgrade (Recommended)

Run the SQL migration directly on your existing database:

```bash
# 1. Backup first!
mysqldump -u root -p myvivarium > backup_$(date +%Y%m%d).sql

# 2. Run migration
mysql -u root -p myvivarium < database/migrate_v1_to_v2.sql
```

### Option B: Full Migration (New Database)

Use the interactive shell script to migrate data to a fresh database:

```bash
# 1. Create new database and import v2 schema
mysql -u root -p -e "CREATE DATABASE myvivarium2;"
mysql -u root -p myvivarium2 < database/schema.sql

# 2. Run migration script
chmod +x database/migrate_v1_to_v2.sh
./database/migrate_v1_to_v2.sh
```

The script will:
- Back up the old database automatically
- Migrate all data table by table
- Map v1 columns to v2 schema (setting new columns to defaults)
- Verify row counts match between old and new databases

## What Changed in v2

### New Tables (v2 only)
| Table | Purpose |
|-------|---------|
| `tasks` | Task management with status workflow |
| `reminders` | Recurring reminder scheduling |
| `outbox` | Email notification queue |
| `maintenance` | Cage maintenance log |
| `activity_log` | Audit trail |
| `settings` | System configuration |
| `mice` | Individual mouse tracking |

### New Columns on Existing Tables

| Table | Column | Type | Default | Purpose |
|-------|--------|------|---------|---------|
| `cages` | `status` | ENUM('active','archived') | 'active' | Soft-delete / archive |
| `cages` | `room` | VARCHAR(255) | NULL | Physical location |
| `cages` | `rack` | VARCHAR(255) | NULL | Physical location |
| `holding` | `genotype` | VARCHAR(255) | NULL | Cage-level genotype |
| `breeding` | `male_genotype` | VARCHAR(255) | NULL | Male parent genotype |
| `breeding` | `female_genotype` | VARCHAR(255) | NULL | Female parent genotype |
| `breeding` | `male_parent_cage` | VARCHAR(255) | NULL | Male source cage |
| `breeding` | `female_parent_cage` | VARCHAR(255) | NULL | Female source cage |

### Fields Made Optional (Allow NULL)

**Holding**: `dob`, `parent_cg`
**Breeding**: `cross`, `male_id`, `female_id`, `male_dob`, `female_dob`

### New Role

`vivarium_manager` role auto-assigned to users with position "Vivarium Manager" or "Animal Care Technician".

## Verification

After migration, run these queries to verify:

```sql
-- Check v2 schema
DESCRIBE cages;
DESCRIBE holding;
DESCRIBE breeding;
DESCRIBE tasks;
DESCRIBE reminders;

-- Check cage counts
SELECT status, COUNT(*) FROM cages GROUP BY status;

-- Check roles
SELECT role, COUNT(*) FROM users GROUP BY role;

-- Check new tables exist
SHOW TABLES;
```

## Best Practices

1. **Always backup** before running any migration
2. **Test on dev** before applying to production
3. **Run verification queries** after migration
4. Update `.env` if you created a new database
