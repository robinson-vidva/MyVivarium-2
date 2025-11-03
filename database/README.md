# Database Migrations and Schema

This folder contains the database schema and migration scripts for MyVivarium.

## Files

### `schema.sql`
The main database schema file. Use this for **new installations** to create all required tables and initial data.

**Usage:**
```bash
mysql -u your_username -p your_database_name < database/schema.sql
```

### Migration Files

Migration files are used to update **existing databases** without losing data. Run these on databases that were created with an earlier version of the schema.

#### `add_vivarium_manager_role.sql`
- **Purpose:** Adds the "vivarium_manager" role to the system
- **Date Added:** 2025-11-02
- **What it does:**
  - Automatically assigns vivarium_manager role to users with position "Vivarium Manager"
  - Includes verification queries
  - Provides optional manual assignment commands

**Usage:**
```bash
mysql -u your_username -p your_database_name < database/add_vivarium_manager_role.sql
```

#### `make_cage_fields_optional.sql`
- **Purpose:** Makes several cage creation fields optional for quick cage creation
- **Date Added:** 2025-11-03
- **What it does:**
  - Makes DOB and Parent Cage optional for holding cages
  - Makes Cross, Male ID, Female ID, Male DOB, and Female DOB optional for breeding cages
  - Enables users to create cages quickly with minimal information
  - Allows completing cage details later

**Fields Changed:**

**Holding Cages:**
- `dob` - Changed from NOT NULL to DEFAULT NULL
- `parent_cg` - Changed from NOT NULL to DEFAULT NULL

**Breeding Cages:**
- `cross` - Changed from NOT NULL to DEFAULT NULL
- `male_id` - Changed from NOT NULL to DEFAULT NULL
- `female_id` - Changed from NOT NULL to DEFAULT NULL
- `male_dob` - Changed from NOT NULL to DEFAULT NULL
- `female_dob` - Changed from NOT NULL to DEFAULT NULL

**Usage:**
```bash
mysql -u your_username -p your_database_name < database/make_cage_fields_optional.sql
```

**After Running This Migration:**
- Users can create cages with just a Cage ID
- The application will show information completeness indicators
- Users are encouraged to fill in missing details over time

## How to Apply Migrations

### For New Installations
1. Use only `schema.sql`
2. This creates all tables with the latest structure

### For Existing Databases
1. First, check which migrations you need to apply
2. Apply migrations in chronological order:
   - `add_vivarium_manager_role.sql` (if not already applied)
   - `make_cage_fields_optional.sql` (if not already applied)

### Checking Which Migrations Are Needed

**To check if vivarium_manager role migration is needed:**
```sql
SELECT DISTINCT role FROM users;
```
If you don't see 'vivarium_manager' in the results, you need to apply that migration.

**To check if cage fields are optional:**
```sql
SHOW COLUMNS FROM holding WHERE Field IN ('dob', 'parent_cg');
SHOW COLUMNS FROM breeding WHERE Field IN ('cross', 'male_id', 'female_id', 'male_dob', 'female_dob');
```
If you see 'NO' in the 'Null' column for these fields, you need to apply the migration.

## Best Practices

1. **Always backup your database before running migrations:**
   ```bash
   mysqldump -u your_username -p your_database_name > backup_$(date +%Y%m%d_%H%M%S).sql
   ```

2. **Test migrations on a development database first** before applying to production

3. **Run verification queries** (included in each migration file) after applying migrations

4. **Keep track of which migrations have been applied** to your database

## Rollback Instructions

Each migration file includes rollback instructions in the comments. Review the specific migration file for detailed rollback steps.

**WARNING:** Rollback may fail if the migration has been applied and data has been created that depends on the changes. Always backup before attempting a rollback.

## Support

For issues with database migrations, please:
1. Check the verification queries in the migration file
2. Review the error messages
3. Ensure you have proper database permissions
4. Create an issue at https://github.com/robinson-vidva/MyVivarium-2/issues
