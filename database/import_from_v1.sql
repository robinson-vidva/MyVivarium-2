-- =============================================================================
-- MyVivarium V1 → V2 Data Import
-- =============================================================================
--
-- One-time import of V1 production data into a freshly-built V2 database.
-- V2's schema is cleanly applied first (database/schema.sql); then this
-- script copies V1 data across, transforming on the fly into the new
-- mouse-as-entity model.
--
-- Why a cross-database import (rather than in-place ALTERs):
--   V1 is in production. V2 is built greenfield from schema.sql, so we get
--   the right structure with no legacy ALTER TABLE residue. This script
--   reads from V1 and writes into V2 in a single transaction; if anything
--   goes wrong, V1 is untouched.
--
-- =============================================================================
-- USAGE
-- -----------------------------------------------------------------------------
-- 1. Make sure both V1 and V2 databases are on the same MySQL server.
--    (Cross-server imports require mysqldump → load instead; see notes
--    at the bottom.)
--
-- 2. The V1 source database should be at the latest V1 schema. If your V1
--    prod hasn't run database/migrate_v1_to_v2.sql yet, run that against
--    V1 FIRST so the columns this script reads (cages.status, cages.room,
--    cages.rack, holding.genotype, etc.) all exist.
--      mysql -u root -p myvivarium_v1 < database/migrate_v1_to_v2.sql
--
-- 3. Create the V2 database fresh and apply the V2 schema:
--      CREATE DATABASE myvivarium_v2;
--      mysql -u root -p myvivarium_v2 < database/schema.sql
--
-- 4. Edit the source/dest database names in this script (search for
--    `myvivarium_v1` and `myvivarium_v2` and replace with yours), then run:
--      mysql -u root -p < database/import_from_v1.sql
--
-- 5. Verify with the sanity-check queries at the bottom of the file.
--    Spot-check a handful of mice and their cage assignments before
--    pointing the new app at the V2 database.
--
-- =============================================================================
-- ASSUMPTIONS
-- -----------------------------------------------------------------------------
-- - Source V1 database is named `myvivarium_v1`. Edit if different.
-- - Destination V2 database is named `myvivarium_v2`. Edit if different.
-- - V2 has been initialized via schema.sql (so all tables/FKs already exist
--   and are empty). This script does INSERT IGNORE so it's safe to re-run,
--   but the cleanest path is single-shot against an empty V2.
-- - The MySQL user has SELECT on V1 and INSERT on V2.
-- =============================================================================

SET FOREIGN_KEY_CHECKS = 0;
SET UNIQUE_CHECKS = 0;

START TRANSACTION;

USE `myvivarium_v2`;

-- -----------------------------------------------------------------------------
-- 0. Drop the seed admin (id=1) so V1's user with id=1 doesn't collide
-- with the schema.sql Temporary Admin and get silently dropped by
-- INSERT IGNORE.
-- -----------------------------------------------------------------------------
DELETE FROM `users` WHERE id = 1;

-- -----------------------------------------------------------------------------
-- 1. Reference data with no FK dependencies — copy first.
-- -----------------------------------------------------------------------------

INSERT IGNORE INTO `users`
  (id, name, username, position, role, password, status,
   reset_token, reset_token_expiration, login_attempts, account_locked,
   email_verified, email_token, initials)
SELECT id, name, username, position, role, password, status,
       reset_token, reset_token_expiration, login_attempts, account_locked,
       email_verified, email_token, initials
FROM `myvivarium_v1`.`users`;

INSERT IGNORE INTO `iacuc` (iacuc_id, iacuc_title, file_url)
SELECT iacuc_id, iacuc_title, file_url FROM `myvivarium_v1`.`iacuc`;

INSERT IGNORE INTO `strains` (id, str_id, str_name, str_aka, str_url, str_rrid, str_notes)
SELECT id, str_id, str_name, str_aka, str_url, str_rrid, str_notes
FROM `myvivarium_v1`.`strains`;

INSERT IGNORE INTO `settings` (name, value)
SELECT name, value FROM `myvivarium_v1`.`settings`;

-- -----------------------------------------------------------------------------
-- 2. Cages (depends on users for pi_name FK).
-- -----------------------------------------------------------------------------

INSERT IGNORE INTO `cages`
  (cage_id, pi_name, quantity, remarks, status, room, rack, created_at)
SELECT cage_id, pi_name, quantity, remarks,
       COALESCE(status, 'active'),
       room, rack, created_at
FROM `myvivarium_v1`.`cages`;

-- Cage-side junctions
INSERT IGNORE INTO `cage_iacuc` (cage_id, iacuc_id)
SELECT cage_id, iacuc_id FROM `myvivarium_v1`.`cage_iacuc`;

INSERT IGNORE INTO `cage_users` (cage_id, user_id)
SELECT cage_id, user_id FROM `myvivarium_v1`.`cage_users`;

-- -----------------------------------------------------------------------------
-- 3. Mice. V1 stored cage-level defaults (strain/dob/sex/parent_cg) on
-- `holding` and per-mouse identity (mouse_id/genotype/notes) on `mice`. V2
-- has a single canonical mouse entity, so each V1 mouse picks up its
-- cage's holding-row defaults at import time. A defensive fallback then
-- handles any cage that has a holding row but no individual mice listed.
-- -----------------------------------------------------------------------------

-- 3a) Each V1 mouse merges in its cage's holding row (LEFT JOIN so mice
-- whose cage has no holding row still land, just without strain/dob/sex).
INSERT IGNORE INTO `mice`
  (mouse_id, sex, dob, current_cage_id, strain, genotype, status, notes, source_cage_label)
SELECT
  m.mouse_id                                       AS mouse_id,
  COALESCE(NULLIF(LOWER(h.sex), ''), 'unknown')    AS sex,
  h.dob                                            AS dob,
  m.cage_id                                        AS current_cage_id,
  h.strain                                         AS strain,
  m.genotype                                       AS genotype,
  'alive'                                          AS status,
  m.notes                                          AS notes,
  h.parent_cg                                      AS source_cage_label
FROM `myvivarium_v1`.`mice` m
LEFT JOIN `myvivarium_v1`.`holding` h ON h.cage_id = m.cage_id
WHERE m.mouse_id IS NOT NULL AND m.mouse_id != '';

-- 3b) Defensive fallback: cages with a holding row but no mice rows
-- (V1 cage-level data with no individual mice listed). Synthesize one
-- mouse so the holding-row data isn't lost.
INSERT IGNORE INTO `mice`
  (mouse_id, sex, dob, current_cage_id, strain, status, notes, source_cage_label)
SELECT
  CONCAT('H_', h.cage_id, '_', h.id)               AS mouse_id,
  COALESCE(NULLIF(LOWER(h.sex), ''), 'unknown')    AS sex,
  h.dob                                            AS dob,
  h.cage_id                                        AS current_cage_id,
  h.strain                                         AS strain,
  'alive'                                          AS status,
  'Synthesized from v1 holding row (no individual mice listed for this cage in v1).' AS notes,
  h.parent_cg                                      AS source_cage_label
FROM `myvivarium_v1`.`holding` h
WHERE h.cage_id IS NOT NULL
  AND NOT EXISTS (
    SELECT 1 FROM `myvivarium_v1`.`mice` m WHERE m.cage_id = h.cage_id
  );

-- 3c) Breeding parents — synthesize archived mouse rows for any
-- male_id/female_id values referenced by v1.breeding that we haven't
-- already imported. Status='archived' + current_cage_id NULL keeps them
-- out of live counts; they exist purely to receive the breeding FKs.
-- DOB / genotype get carried over from the breeding row.
INSERT IGNORE INTO `mice`
  (mouse_id, sex, dob, current_cage_id, genotype, status, notes)
SELECT DISTINCT
  b.male_id,
  'male',
  b.male_dob,
  NULL,
  b.male_genotype,
  'archived',
  CONCAT('Synthesized from v1 breeding parent; v1 source: ', COALESCE(b.male_parent_cage, 'unknown'))
FROM `myvivarium_v1`.`breeding` b
WHERE b.male_id IS NOT NULL AND b.male_id != ''
  AND NOT EXISTS (SELECT 1 FROM `mice` m WHERE m.mouse_id = b.male_id);

INSERT IGNORE INTO `mice`
  (mouse_id, sex, dob, current_cage_id, genotype, status, notes)
SELECT DISTINCT
  b.female_id,
  'female',
  b.female_dob,
  NULL,
  b.female_genotype,
  'archived',
  CONCAT('Synthesized from v1 breeding parent; v1 source: ', COALESCE(b.female_parent_cage, 'unknown'))
FROM `myvivarium_v1`.`breeding` b
WHERE b.female_id IS NOT NULL AND b.female_id != ''
  AND NOT EXISTS (SELECT 1 FROM `mice` m WHERE m.mouse_id = b.female_id);

-- -----------------------------------------------------------------------------
-- 4. Mouse cage history — seed each living mouse's "current location" as
-- one open interval. Subsequent moves append rows via the app.
-- -----------------------------------------------------------------------------

INSERT INTO `mouse_cage_history` (mouse_id, cage_id, moved_in_at, reason)
SELECT m.mouse_id, m.current_cage_id, m.created_at, 'v1 import: initial cage assignment'
FROM `mice` m
WHERE m.current_cage_id IS NOT NULL;

-- -----------------------------------------------------------------------------
-- 5. Breeding cages — only the slim v2 columns. Per-parent dob/genotype/
-- parent_cage from v1 are now properties of the mouse entity (joined at
-- read time in bc_view / prnt_crd).
-- -----------------------------------------------------------------------------

INSERT IGNORE INTO `breeding` (id, cage_id, `cross`, male_id, female_id)
SELECT
  b.id,
  b.cage_id,
  b.`cross`,
  -- NULL out parent IDs that don't resolve to a mouse row, so the FK holds
  CASE WHEN b.male_id IN (SELECT mouse_id FROM `mice`)   THEN b.male_id   ELSE NULL END,
  CASE WHEN b.female_id IN (SELECT mouse_id FROM `mice`) THEN b.female_id ELSE NULL END
FROM `myvivarium_v1`.`breeding` b;

-- -----------------------------------------------------------------------------
-- 6. Tail tables — straightforward copies, in FK-safe order.
-- -----------------------------------------------------------------------------

INSERT IGNORE INTO `litters`
  (id, cage_id, dom, litter_dob, pups_alive, pups_dead, pups_male, pups_female, remarks)
SELECT id, cage_id, dom, litter_dob, pups_alive, pups_dead, pups_male, pups_female, remarks
FROM `myvivarium_v1`.`litters`;

INSERT IGNORE INTO `files` (id, file_name, file_path, uploaded_at, cage_id)
SELECT id, file_name, file_path, uploaded_at, cage_id FROM `myvivarium_v1`.`files`;

INSERT IGNORE INTO `notes` (id, cage_id, note_text, created_at, user_id)
SELECT id, cage_id, note_text, created_at, user_id FROM `myvivarium_v1`.`notes`;

INSERT IGNORE INTO `tasks`
  (id, title, description, assigned_by, assigned_to, status, completion_date,
   cage_id, creation_date, updated_at)
SELECT id, title, description, assigned_by, assigned_to, status, completion_date,
       cage_id, creation_date, updated_at
FROM `myvivarium_v1`.`tasks`;

INSERT IGNORE INTO `maintenance` (id, cage_id, user_id, comments, timestamp)
SELECT id, cage_id, user_id, comments, timestamp FROM `myvivarium_v1`.`maintenance`;

INSERT IGNORE INTO `reminders`
  (id, title, description, assigned_by, assigned_to, recurrence_type,
   day_of_week, day_of_month, time_of_day, status, cage_id,
   creation_date, updated_at, last_task_created)
SELECT id, title, description, assigned_by, assigned_to, recurrence_type,
       day_of_week, day_of_month, time_of_day, status, cage_id,
       creation_date, updated_at, last_task_created
FROM `myvivarium_v1`.`reminders`;

INSERT IGNORE INTO `notifications`
  (id, user_id, title, message, link, type, is_read, created_at)
SELECT id, user_id, title, message, link, type, is_read, created_at
FROM `myvivarium_v1`.`notifications`;

INSERT IGNORE INTO `outbox`
  (id, recipient, subject, body, status, created_at, scheduled_at, sent_at, error_message, task_id)
SELECT id, recipient, subject, body, status, created_at, scheduled_at, sent_at, error_message, task_id
FROM `myvivarium_v1`.`outbox`;

INSERT IGNORE INTO `activity_log`
  (id, user_id, action, entity_type, entity_id, details, ip_address, created_at)
SELECT id, user_id, action, entity_type, entity_id, details, ip_address, created_at
FROM `myvivarium_v1`.`activity_log`;

COMMIT;

SET FOREIGN_KEY_CHECKS = 1;
SET UNIQUE_CHECKS = 1;

-- =============================================================================
-- Sanity-check queries (run manually to verify)
-- =============================================================================
-- SELECT COUNT(*) AS users   FROM users;
-- SELECT COUNT(*) AS cages   FROM cages;
-- SELECT COUNT(*) AS mice_total,
--        SUM(status = 'alive')      AS alive,
--        SUM(status = 'archived')   AS archived,
--        SUM(status = 'sacrificed') AS sacrificed
--   FROM mice;
-- SELECT COUNT(*) AS history_rows,
--        SUM(moved_out_at IS NULL) AS open_intervals
--   FROM mouse_cage_history;
-- SELECT COUNT(*) AS breeding   FROM breeding;
-- SELECT COUNT(*) AS litters    FROM litters;
--
-- Spot-check: pick a v1 cage, see that its v1.holding row(s) became mice rows
-- with current_cage_id matching:
-- SELECT m.mouse_id, m.current_cage_id, m.dob, m.sex, m.notes
--   FROM mice m WHERE m.current_cage_id = '<some_v1_cage_id>';

-- =============================================================================
-- Cross-server option (if V1 and V2 are on different MySQL hosts)
-- =============================================================================
-- 1. Dump only the data tables we read from on the V1 host:
--      mysqldump -u root -p --no-create-info \
--        myvivarium_v1 users iacuc strains settings cages cage_iacuc \
--        cage_users holding mice breeding litters files notes tasks \
--        maintenance reminders notifications outbox activity_log \
--        > v1_data_dump.sql
-- 2. Load that dump into a temporary `myvivarium_v1` schema on the V2 host.
-- 3. Run THIS script against the V2 host as documented above.
-- 4. Drop the temporary V1 schema once import is verified.
-- =============================================================================
