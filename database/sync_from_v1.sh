#!/usr/bin/env bash
#
# V1 → V2 sync (shell flavor).
#
# One-command alternative to the admin UI import. Wraps mysqldump + the
# existing database/import_from_v1.sql so you don't have to hand-edit DB
# names in the SQL file or copy schemas around manually.
#
# Two modes are supported, picked automatically based on the host args:
#
#   1. Same-server   : V1 and V2 on the same MySQL host. Runs
#                       import_from_v1.sql directly, with the placeholder
#                       database names rewritten to your actual ones.
#
#   2. Cross-server  : V1 and V2 on different hosts. Dumps V1's data
#                       tables, loads them into a temporary schema on the
#                       V2 host (myvivarium_v1_import), runs the import,
#                       then drops the temp schema.
#
# Usage examples (run from project root):
#
#   # Same-server, both DBs on localhost:
#   database/sync_from_v1.sh \
#       --v1-db myvivarium_v1 \
#       --v2-db myvivarium_v2
#
#   # Cross-server with a remote V1:
#   database/sync_from_v1.sh \
#       --v1-host db.lab.example --v1-user reader --v1-pass '...' --v1-db myvivarium_v1 \
#       --v2-host localhost      --v2-user root   --v2-pass '...' --v2-db myvivarium_v2
#
# Prereqs:
# - V2 schema applied (php database/install.php).
# - V2 database empty enough that the import can run (no mice/cages/breeding).
#
# The script never modifies the V1 database — it's read-only against V1.

set -euo pipefail

# ---------- defaults ----------
V1_HOST="localhost"
V1_USER="root"
V1_PASS=""
V1_DB=""
V2_HOST="localhost"
V2_USER="root"
V2_PASS=""
V2_DB=""
TMP_SCHEMA="myvivarium_v1_import"
KEEP_TMP=0

# ---------- arg parsing ----------
while [[ $# -gt 0 ]]; do
  case "$1" in
    --v1-host) V1_HOST="$2"; shift 2 ;;
    --v1-user) V1_USER="$2"; shift 2 ;;
    --v1-pass) V1_PASS="$2"; shift 2 ;;
    --v1-db)   V1_DB="$2";   shift 2 ;;
    --v2-host) V2_HOST="$2"; shift 2 ;;
    --v2-user) V2_USER="$2"; shift 2 ;;
    --v2-pass) V2_PASS="$2"; shift 2 ;;
    --v2-db)   V2_DB="$2";   shift 2 ;;
    --tmp-schema) TMP_SCHEMA="$2"; shift 2 ;;
    --keep-tmp) KEEP_TMP=1; shift ;;
    -h|--help)
      sed -n '2,40p' "$0" | sed 's/^# *//'; exit 0 ;;
    *) echo "Unknown flag: $1" >&2; exit 1 ;;
  esac
done

if [[ -z "$V1_DB" || -z "$V2_DB" ]]; then
  echo "Required: --v1-db and --v2-db. See --help." >&2
  exit 1
fi

# Default V2 creds to the V1 ones when not given. Convenient for
# same-server runs where one set of credentials covers both DBs.
[[ -z "$V2_USER" ]] && V2_USER="$V1_USER"
[[ -z "$V2_PASS" ]] && V2_PASS="$V1_PASS"

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
IMPORT_SQL="$SCRIPT_DIR/import_from_v1.sql"
if [[ ! -f "$IMPORT_SQL" ]]; then
  echo "Cannot find $IMPORT_SQL" >&2; exit 1
fi

# Helper: run mysql on V2 with the right creds.
v2_mysql() {
  MYSQL_PWD="$V2_PASS" mysql --protocol=TCP -h "$V2_HOST" -u "$V2_USER" "$@"
}

# Helper: run mysqldump on V1.
v1_dump() {
  MYSQL_PWD="$V1_PASS" mysqldump --protocol=TCP -h "$V1_HOST" -u "$V1_USER" "$@"
}

same_server=0
if [[ "$V1_HOST" == "$V2_HOST" ]]; then
  same_server=1
fi

echo "──────────────────────────────────────────"
echo "V1 → V2 sync"
echo "  V1: ${V1_USER}@${V1_HOST}/${V1_DB}"
echo "  V2: ${V2_USER}@${V2_HOST}/${V2_DB}"
echo "  Mode: $([[ $same_server -eq 1 ]] && echo same-server || echo cross-server)"
echo "──────────────────────────────────────────"

# Pre-flight: V2 reachable and the destination DB exists.
if ! v2_mysql -e "USE \`$V2_DB\`" >/dev/null 2>&1; then
  echo "Cannot USE $V2_DB on V2 host. Did you create it and run database/install.php?" >&2
  exit 1
fi

# Pre-flight: V2 destination is empty enough for an import.
NON_EMPTY=$(v2_mysql -N -B -e "USE \`$V2_DB\`;
  SELECT (SELECT COUNT(*) FROM mice) +
         (SELECT COUNT(*) FROM cages) +
         (SELECT COUNT(*) FROM breeding) +
         (SELECT COUNT(*) FROM users WHERE id > 1)")
if [[ "${NON_EMPTY:-0}" -gt 0 ]]; then
  echo "V2 database $V2_DB already contains data. Reset first:" >&2
  echo "    php database/install.php --reset" >&2
  exit 1
fi

if [[ $same_server -eq 1 ]]; then
  echo "Running import_from_v1.sql against the same server..."
  # Patch the placeholder DB names in import_from_v1.sql to the real ones
  # via sed without modifying the file on disk.
  sed -e "s/\`myvivarium_v1\`/\`$V1_DB\`/g" \
      -e "s/\`myvivarium_v2\`/\`$V2_DB\`/g" \
      "$IMPORT_SQL" \
    | v2_mysql
else
  echo "Cross-server: dumping V1 tables..."
  TMP_DUMP="$(mktemp -t v1_dump_XXXXXX.sql)"
  trap 'rm -f "$TMP_DUMP"' EXIT

  V1_TABLES=(
    users iacuc strains settings
    cages cage_users cage_iacuc
    holding mice breeding litters
    files notes tasks maintenance
    reminders notifications outbox
    activity_log
  )
  v1_dump --no-create-info "$V1_DB" "${V1_TABLES[@]}" > "$TMP_DUMP"
  echo "  dump size: $(wc -c < "$TMP_DUMP") bytes"

  echo "Loading dump into temporary schema $TMP_SCHEMA on V2 host..."
  v2_mysql -e "DROP DATABASE IF EXISTS \`$TMP_SCHEMA\`; CREATE DATABASE \`$TMP_SCHEMA\`"
  # The dump has no CREATE TABLE — pull the structure from V1 first.
  TMP_STRUCT="$(mktemp -t v1_struct_XXXXXX.sql)"
  trap 'rm -f "$TMP_DUMP" "$TMP_STRUCT"' EXIT
  v1_dump --no-data "$V1_DB" "${V1_TABLES[@]}" > "$TMP_STRUCT"
  v2_mysql "$TMP_SCHEMA" < "$TMP_STRUCT"
  v2_mysql "$TMP_SCHEMA" < "$TMP_DUMP"

  echo "Running import_from_v1.sql..."
  sed -e "s/\`myvivarium_v1\`/\`$TMP_SCHEMA\`/g" \
      -e "s/\`myvivarium_v2\`/\`$V2_DB\`/g" \
      "$IMPORT_SQL" \
    | v2_mysql

  if [[ $KEEP_TMP -eq 0 ]]; then
    echo "Dropping temporary schema $TMP_SCHEMA..."
    v2_mysql -e "DROP DATABASE \`$TMP_SCHEMA\`"
  else
    echo "Keeping temporary schema $TMP_SCHEMA (--keep-tmp)."
  fi
fi

echo "──────────────────────────────────────────"
echo "Sync complete. Sanity counts in V2:"
v2_mysql -e "USE \`$V2_DB\`;
  SELECT 'mice'                AS table_name, COUNT(*) AS rows FROM mice
  UNION ALL SELECT 'cages',                COUNT(*) FROM cages
  UNION ALL SELECT 'breeding',             COUNT(*) FROM breeding
  UNION ALL SELECT 'mouse_cage_history',   COUNT(*) FROM mouse_cage_history
  UNION ALL SELECT 'litters',              COUNT(*) FROM litters
  UNION ALL SELECT 'users',                COUNT(*) FROM users;"
echo "──────────────────────────────────────────"
