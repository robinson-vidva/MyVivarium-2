# Database Schema and Migrations

This folder contains the database schema, migration scripts, and tools for MyVivarium-2.

## Files Overview

| File | Purpose |
|------|---------|
| `schema.sql` | Complete v2 schema for **new installations** |
| `migrate_v1_to_v2.sql` | **In-place upgrade** from MyVivarium v1 to v2 |
| `migrate_v1_to_v2.sh` | **Full migration** script (old DB -> new DB, with verification) |
| `add_vivarium_manager_role.sql` | Legacy: adds vivarium_manager role (included in v2 schema) |
| `add_features_v2.sql` | Legacy: adds v2 columns (included in migrate_v1_to_v2.sql) |
| `make_cage_fields_optional.sql` | Legacy: optional fields (included in migrate_v1_to_v2.sql) |
| `erd.png` | Entity-Relationship Diagram |

## New Installation

Use `schema.sql` to create all tables with the latest v2 structure:

```bash
mysql -u root -p your_database_name < database/schema.sql
```

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

### New Columns

| Table | Column | Type | Default | Purpose |
|-------|--------|------|---------|---------|
| `cages` | `status` | ENUM('active','archived') | 'active' | Soft-delete / archive |
| `cages` | `room` | VARCHAR(255) | NULL | Physical location |
| `cages` | `rack` | VARCHAR(255) | NULL | Physical location |
| `holding` | `genotype` | VARCHAR(255) | NULL | Cage-level genotype |
| `breeding` | `male_genotype` | VARCHAR(255) | NULL | Male parent genotype |
| `breeding` | `female_genotype` | VARCHAR(255) | NULL | Female parent genotype |

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

-- Check cage counts
SELECT status, COUNT(*) FROM cages GROUP BY status;

-- Check roles
SELECT role, COUNT(*) FROM users GROUP BY role;
```

## Best Practices

1. **Always backup** before running any migration
2. **Test on dev** before applying to production
3. **Run verification queries** after migration
4. Update `.env` if you created a new database
