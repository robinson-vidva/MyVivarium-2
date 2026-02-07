#!/bin/bash
# =============================================================================
# MyVivarium to MyVivarium-2 Data Migration Script
# =============================================================================
#
# This script migrates data from an existing MyVivarium (v1) database to the
# MyVivarium-2 database schema. It handles all schema changes introduced in v2.
#
# USAGE:
#   chmod +x migrate_v1_to_v2.sh
#   ./migrate_v1_to_v2.sh
#
# PREREQUISITES:
#   - MySQL/MariaDB client installed
#   - Access to both old and new databases
#   - MyVivarium-2 schema.sql already imported into the new database
#
# WHAT THIS SCRIPT DOES:
#   1. Exports data from the old MyVivarium database
#   2. Transforms data to match the new schema (adds new columns with defaults)
#   3. Imports data into the new MyVivarium-2 database
#   4. Verifies the migration with row counts
#
# =============================================================================

set -euo pipefail

# Color output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

echo -e "${GREEN}=========================================${NC}"
echo -e "${GREEN} MyVivarium v1 -> v2 Migration Script   ${NC}"
echo -e "${GREEN}=========================================${NC}"
echo ""

# Configuration - Update these values
read -p "Old database host [localhost]: " OLD_DB_HOST
OLD_DB_HOST=${OLD_DB_HOST:-localhost}

read -p "Old database name [myvivarium]: " OLD_DB_NAME
OLD_DB_NAME=${OLD_DB_NAME:-myvivarium}

read -p "Old database username [root]: " OLD_DB_USER
OLD_DB_USER=${OLD_DB_USER:-root}

read -sp "Old database password: " OLD_DB_PASS
echo ""

read -p "New database host [localhost]: " NEW_DB_HOST
NEW_DB_HOST=${NEW_DB_HOST:-localhost}

read -p "New database name [myvivarium2]: " NEW_DB_NAME
NEW_DB_NAME=${NEW_DB_NAME:-myvivarium2}

read -p "New database username [root]: " NEW_DB_USER
NEW_DB_USER=${NEW_DB_USER:-root}

read -sp "New database password: " NEW_DB_PASS
echo ""

# Helper functions
old_mysql() {
    mysql -h "$OLD_DB_HOST" -u "$OLD_DB_USER" -p"$OLD_DB_PASS" "$OLD_DB_NAME" "$@" 2>/dev/null
}

new_mysql() {
    mysql -h "$NEW_DB_HOST" -u "$NEW_DB_USER" -p"$NEW_DB_PASS" "$NEW_DB_NAME" "$@" 2>/dev/null
}

count_rows() {
    local db_func=$1
    local table=$2
    $db_func -N -e "SELECT COUNT(*) FROM \`$table\`" 2>/dev/null || echo "0"
}

echo ""
echo -e "${YELLOW}Step 1: Verifying connections...${NC}"

# Test connections
if ! old_mysql -e "SELECT 1" > /dev/null 2>&1; then
    echo -e "${RED}ERROR: Cannot connect to old database.${NC}"
    exit 1
fi
echo -e "${GREEN}  Old database: OK${NC}"

if ! new_mysql -e "SELECT 1" > /dev/null 2>&1; then
    echo -e "${RED}ERROR: Cannot connect to new database.${NC}"
    exit 1
fi
echo -e "${GREEN}  New database: OK${NC}"

# Check if new database has the v2 schema
if ! new_mysql -N -e "SHOW COLUMNS FROM cages LIKE 'status'" 2>/dev/null | grep -q "status"; then
    echo -e "${RED}ERROR: New database doesn't have v2 schema. Run schema.sql first.${NC}"
    exit 1
fi
echo -e "${GREEN}  New database schema: v2 verified${NC}"

echo ""
echo -e "${YELLOW}Step 2: Creating backup of old database...${NC}"
BACKUP_FILE="myvivarium_backup_$(date +%Y%m%d_%H%M%S).sql"
mysqldump -h "$OLD_DB_HOST" -u "$OLD_DB_USER" -p"$OLD_DB_PASS" "$OLD_DB_NAME" > "$BACKUP_FILE" 2>/dev/null
echo -e "${GREEN}  Backup saved to: $BACKUP_FILE${NC}"

echo ""
echo -e "${YELLOW}Step 3: Disabling foreign key checks...${NC}"
new_mysql -e "SET FOREIGN_KEY_CHECKS = 0;"

echo ""
echo -e "${YELLOW}Step 4: Migrating data table by table...${NC}"

# --- IACUC table (no schema changes) ---
echo -n "  Migrating iacuc... "
old_mysql -N -e "SELECT iacuc_id, iacuc_title, IFNULL(file_url, '') FROM iacuc" | \
while IFS=$'\t' read -r iacuc_id iacuc_title file_url; do
    new_mysql -e "INSERT IGNORE INTO iacuc (iacuc_id, iacuc_title, file_url) VALUES ('$(echo "$iacuc_id" | sed "s/'/\\\\'/g")', '$(echo "$iacuc_title" | sed "s/'/\\\\'/g")', NULLIF('$(echo "$file_url" | sed "s/'/\\\\'/g")', ''));"
done
echo -e "${GREEN}done ($(count_rows old_mysql iacuc) -> $(count_rows new_mysql iacuc))${NC}"

# --- Users table (no schema changes) ---
echo -n "  Migrating users... "
mysqldump -h "$OLD_DB_HOST" -u "$OLD_DB_USER" -p"$OLD_DB_PASS" --no-create-info --replace "$OLD_DB_NAME" users 2>/dev/null | \
new_mysql 2>/dev/null
echo -e "${GREEN}done ($(count_rows old_mysql users) -> $(count_rows new_mysql users))${NC}"

# --- Settings table (no schema changes) ---
echo -n "  Migrating settings... "
mysqldump -h "$OLD_DB_HOST" -u "$OLD_DB_USER" -p"$OLD_DB_PASS" --no-create-info --replace "$OLD_DB_NAME" settings 2>/dev/null | \
new_mysql 2>/dev/null
echo -e "${GREEN}done ($(count_rows old_mysql settings) -> $(count_rows new_mysql settings))${NC}"

# --- Strains table (no schema changes) ---
echo -n "  Migrating strains... "
mysqldump -h "$OLD_DB_HOST" -u "$OLD_DB_USER" -p"$OLD_DB_PASS" --no-create-info --replace "$OLD_DB_NAME" strains 2>/dev/null | \
new_mysql 2>/dev/null
echo -e "${GREEN}done ($(count_rows old_mysql strains) -> $(count_rows new_mysql strains))${NC}"

# --- Cages table (NEW: status, room, rack columns) ---
echo -n "  Migrating cages (+ new columns: status, room, rack)... "
old_mysql -N -e "SELECT cage_id, pi_name, quantity, remarks FROM cages" | \
while IFS=$'\t' read -r cage_id pi_name quantity remarks; do
    pi_name_val=$([ "$pi_name" = "NULL" ] || [ -z "$pi_name" ] && echo "NULL" || echo "$pi_name")
    qty_val=$([ "$quantity" = "NULL" ] || [ -z "$quantity" ] && echo "NULL" || echo "$quantity")
    remarks_val=$([ "$remarks" = "NULL" ] || [ -z "$remarks" ] && echo "NULL" || echo "'$(echo "$remarks" | sed "s/'/\\\\'/g")'")
    new_mysql -e "INSERT IGNORE INTO cages (cage_id, pi_name, quantity, remarks, status, room, rack) VALUES ('$(echo "$cage_id" | sed "s/'/\\\\'/g")', $pi_name_val, $qty_val, $remarks_val, 'active', NULL, NULL);"
done
echo -e "${GREEN}done ($(count_rows old_mysql cages) -> $(count_rows new_mysql cages))${NC}"

# --- Junction tables (no schema changes) ---
for table in cage_iacuc cage_users; do
    echo -n "  Migrating $table... "
    mysqldump -h "$OLD_DB_HOST" -u "$OLD_DB_USER" -p"$OLD_DB_PASS" --no-create-info --replace "$OLD_DB_NAME" "$table" 2>/dev/null | \
    new_mysql 2>/dev/null
    echo -e "${GREEN}done ($(count_rows old_mysql $table) -> $(count_rows new_mysql $table))${NC}"
done

# --- Holding table (NEW: genotype column) ---
echo -n "  Migrating holding (+ new column: genotype)... "
old_mysql -N -e "SELECT id, cage_id, strain, dob, sex, parent_cg FROM holding" | \
while IFS=$'\t' read -r id cage_id strain dob sex parent_cg; do
    strain_val=$([ "$strain" = "NULL" ] || [ -z "$strain" ] && echo "NULL" || echo "'$(echo "$strain" | sed "s/'/\\\\'/g")'")
    dob_val=$([ "$dob" = "NULL" ] || [ -z "$dob" ] || [ "$dob" = "0000-00-00" ] && echo "NULL" || echo "'$dob'")
    sex_val=$([ "$sex" = "NULL" ] || [ -z "$sex" ] && echo "NULL" || echo "'$sex'")
    parent_val=$([ "$parent_cg" = "NULL" ] || [ -z "$parent_cg" ] && echo "NULL" || echo "'$(echo "$parent_cg" | sed "s/'/\\\\'/g")'")
    new_mysql -e "INSERT IGNORE INTO holding (id, cage_id, strain, dob, sex, parent_cg, genotype) VALUES ($id, '$(echo "$cage_id" | sed "s/'/\\\\'/g")', $strain_val, $dob_val, $sex_val, $parent_val, NULL);"
done
echo -e "${GREEN}done ($(count_rows old_mysql holding) -> $(count_rows new_mysql holding))${NC}"

# --- Breeding table (NEW: male_genotype, female_genotype columns) ---
echo -n "  Migrating breeding (+ new columns: male_genotype, female_genotype)... "
old_mysql -N -e "SELECT id, cage_id, \`cross\`, male_id, female_id, male_dob, female_dob FROM breeding" | \
while IFS=$'\t' read -r id cage_id cross male_id female_id male_dob female_dob; do
    cross_val=$([ "$cross" = "NULL" ] || [ -z "$cross" ] && echo "NULL" || echo "'$(echo "$cross" | sed "s/'/\\\\'/g")'")
    male_id_val=$([ "$male_id" = "NULL" ] || [ -z "$male_id" ] && echo "NULL" || echo "'$(echo "$male_id" | sed "s/'/\\\\'/g")'")
    female_id_val=$([ "$female_id" = "NULL" ] || [ -z "$female_id" ] && echo "NULL" || echo "'$(echo "$female_id" | sed "s/'/\\\\'/g")'")
    male_dob_val=$([ "$male_dob" = "NULL" ] || [ -z "$male_dob" ] || [ "$male_dob" = "0000-00-00" ] && echo "NULL" || echo "'$male_dob'")
    female_dob_val=$([ "$female_dob" = "NULL" ] || [ -z "$female_dob" ] || [ "$female_dob" = "0000-00-00" ] && echo "NULL" || echo "'$female_dob'")
    new_mysql -e "INSERT IGNORE INTO breeding (id, cage_id, \`cross\`, male_id, female_id, male_dob, female_dob, male_genotype, female_genotype) VALUES ($id, '$(echo "$cage_id" | sed "s/'/\\\\'/g")', $cross_val, $male_id_val, $female_id_val, $male_dob_val, $female_dob_val, NULL, NULL);"
done
echo -e "${GREEN}done ($(count_rows old_mysql breeding) -> $(count_rows new_mysql breeding))${NC}"

# --- Remaining tables with no schema changes ---
for table in litters files notes mice tasks outbox reminders maintenance; do
    echo -n "  Migrating $table... "
    if old_mysql -e "SELECT 1 FROM \`$table\` LIMIT 1" > /dev/null 2>&1; then
        mysqldump -h "$OLD_DB_HOST" -u "$OLD_DB_USER" -p"$OLD_DB_PASS" --no-create-info --replace "$OLD_DB_NAME" "$table" 2>/dev/null | \
        new_mysql 2>/dev/null
        echo -e "${GREEN}done ($(count_rows old_mysql $table) -> $(count_rows new_mysql $table))${NC}"
    else
        echo -e "${YELLOW}skipped (table not found in old database)${NC}"
    fi
done

echo ""
echo -e "${YELLOW}Step 5: Re-enabling foreign key checks...${NC}"
new_mysql -e "SET FOREIGN_KEY_CHECKS = 1;"

echo ""
echo -e "${YELLOW}Step 6: Running verification...${NC}"
echo ""
echo "  Table               | Old DB  | New DB  | Status"
echo "  --------------------|---------|---------|-------"

MIGRATION_OK=true
for table in iacuc users settings strains cages cage_iacuc cage_users holding breeding litters files notes mice tasks outbox reminders maintenance; do
    old_count=$(count_rows old_mysql "$table" 2>/dev/null || echo "N/A")
    new_count=$(count_rows new_mysql "$table" 2>/dev/null || echo "N/A")
    if [ "$old_count" = "$new_count" ]; then
        status="${GREEN}OK${NC}"
    elif [ "$old_count" = "N/A" ]; then
        status="${YELLOW}NEW${NC}"
    else
        status="${RED}MISMATCH${NC}"
        MIGRATION_OK=false
    fi
    printf "  %-19s | %7s | %7s | " "$table" "$old_count" "$new_count"
    echo -e "$status"
done

echo ""
if [ "$MIGRATION_OK" = true ]; then
    echo -e "${GREEN}=========================================${NC}"
    echo -e "${GREEN} Migration completed successfully!       ${NC}"
    echo -e "${GREEN}=========================================${NC}"
else
    echo -e "${RED}=========================================${NC}"
    echo -e "${RED} Migration completed with mismatches.    ${NC}"
    echo -e "${RED} Please review the table counts above.   ${NC}"
    echo -e "${RED}=========================================${NC}"
fi

echo ""
echo -e "Backup file: ${YELLOW}$BACKUP_FILE${NC}"
echo -e "New v2 columns set to defaults:"
echo "  - cages.status = 'active' (all migrated cages are active)"
echo "  - cages.room = NULL"
echo "  - cages.rack = NULL"
echo "  - holding.genotype = NULL"
echo "  - breeding.male_genotype = NULL"
echo "  - breeding.female_genotype = NULL"
echo ""
echo "You can now update .env to point to the new database."
