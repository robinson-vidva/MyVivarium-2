-- =============================================================================
-- MyVivarium: Mouse-as-Entity Migration
-- =============================================================================
--
-- Upgrades the v1 cage-scoped mouse model to the v2 canonical mouse entity.
-- Promotes mice into first-class records with stable identity across cage
-- moves, hybrid (FK + external-ref) parent linking, and a full cage-history
-- log.
--
-- Replaces the v1 tables:
--   `holding` (cage-scoped mouse rows)            -> rows migrate into `mice`
--   `mice`    (sparse cage-scoped mouse rows)     -> renamed to `mice_v1_archive`
--                                                    then merged into new `mice`
-- Modifies:
--   `breeding`  -> male_id / female_id become FKs into `mice`;
--                  derived columns (male_dob, female_dob, *_genotype,
--                  *_parent_cage) are dropped and reconstituted on the mouse
--                  entity instead.
--
-- IMPORTANT: Back up before running.
--   mysqldump -u root -p myvivarium > backup_before_mouse_entity.sql
--
-- USAGE:
--   mysql -u root -p myvivarium < migrate_mouse_entity.sql
--
-- The script is idempotent where possible (CREATE/ALTER ... IF NOT EXISTS,
-- INSERT IGNORE on PK collisions). Re-running on an already-migrated db is a
-- no-op for the structural steps; data steps are guarded by EXISTS checks on
-- the source tables.
-- =============================================================================

START TRANSACTION;

-- -----------------------------------------------------------------------------
-- Step 1: Park the v1 `mice` table (the sparse one) out of the way so we can
-- redefine `mice` as the canonical entity. We keep the v1 data in
-- `mice_v1_archive` until verification, then it can be dropped manually.
-- -----------------------------------------------------------------------------
SET @mice_is_v1 := (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE()
    AND table_name = 'mice'
    AND column_name = 'cage_id'
);

SET @sql := IF(@mice_is_v1 > 0,
  'RENAME TABLE `mice` TO `mice_v1_archive`',
  'SELECT "skip rename: mice already migrated" AS note');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- -----------------------------------------------------------------------------
-- Step 2: Create canonical `mice` table.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `mice` (
  `mouse_id` varchar(255) NOT NULL,
  `sex` enum('male','female','unknown') NOT NULL DEFAULT 'unknown',
  `dob` date DEFAULT NULL,
  `current_cage_id` varchar(255) DEFAULT NULL,
  `strain` varchar(255) DEFAULT NULL,
  `genotype` varchar(255) DEFAULT NULL,
  `ear_code` varchar(64) DEFAULT NULL,
  `sire_id` varchar(255) DEFAULT NULL,
  `dam_id` varchar(255) DEFAULT NULL,
  `sire_external_ref` varchar(255) DEFAULT NULL,
  `dam_external_ref` varchar(255) DEFAULT NULL,
  `status` enum('alive','sacrificed','transferred_out','archived') NOT NULL DEFAULT 'alive',
  `sacrificed_at` date DEFAULT NULL,
  `sacrifice_reason` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`mouse_id`),
  KEY `idx_mice_cage` (`current_cage_id`),
  KEY `idx_mice_status` (`status`),
  KEY `idx_mice_sire` (`sire_id`),
  KEY `idx_mice_dam` (`dam_id`),
  KEY `idx_mice_strain` (`strain`),
  CONSTRAINT `fk_mice_cage`    FOREIGN KEY (`current_cage_id`) REFERENCES `cages`   (`cage_id`)  ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT `fk_mice_strain`  FOREIGN KEY (`strain`)          REFERENCES `strains` (`str_id`)   ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT `fk_mice_sire`    FOREIGN KEY (`sire_id`)         REFERENCES `mice`    (`mouse_id`) ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT `fk_mice_dam`     FOREIGN KEY (`dam_id`)          REFERENCES `mice`    (`mouse_id`) ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT `fk_mice_creator` FOREIGN KEY (`created_by`)      REFERENCES `users`   (`id`)       ON DELETE SET NULL
);

-- -----------------------------------------------------------------------------
-- Step 3: Create cage-move history table.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `mouse_cage_history` (
  `id` int NOT NULL AUTO_INCREMENT,
  `mouse_id` varchar(255) NOT NULL,
  `cage_id` varchar(255) DEFAULT NULL,
  `moved_in_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `moved_out_at` timestamp NULL DEFAULT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `moved_by` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_mch_mouse` (`mouse_id`, `moved_in_at`),
  KEY `idx_mch_cage` (`cage_id`),
  KEY `idx_mch_open` (`mouse_id`, `moved_out_at`),
  CONSTRAINT `fk_mch_mouse` FOREIGN KEY (`mouse_id`) REFERENCES `mice`  (`mouse_id`) ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT `fk_mch_cage`  FOREIGN KEY (`cage_id`)  REFERENCES `cages` (`cage_id`)  ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT `fk_mch_user`  FOREIGN KEY (`moved_by`) REFERENCES `users` (`id`)       ON DELETE SET NULL
);

-- -----------------------------------------------------------------------------
-- Step 4: Migrate v1 `holding` rows into canonical `mice`.
-- v1 `holding` had no mouse_id at all (only an auto-increment id), so we
-- synthesize one as `H{cage_id}_{id}`. Users will rename to their preferred
-- ear-code naming after migration; the synthesized id is just a placeholder
-- guaranteed to be unique and traceable back to the v1 row.
-- -----------------------------------------------------------------------------
SET @holding_exists := (
  SELECT COUNT(*) FROM information_schema.tables
  WHERE table_schema = DATABASE() AND table_name = 'holding'
);

SET @sql := IF(@holding_exists > 0, '
  INSERT IGNORE INTO `mice`
    (mouse_id, sex, dob, current_cage_id, strain, genotype, status, notes)
  SELECT
    CONCAT("H", h.cage_id, "_", h.id) AS mouse_id,
    COALESCE(h.sex, "unknown")        AS sex,
    h.dob                              AS dob,
    h.cage_id                          AS current_cage_id,
    h.strain                           AS strain,
    h.genotype                         AS genotype,
    "alive"                            AS status,
    CASE WHEN h.parent_cg IS NOT NULL AND h.parent_cg != ""
         THEN CONCAT("Migrated from v1 holding; parent cage: ", h.parent_cg)
         ELSE "Migrated from v1 holding"
    END                                AS notes
  FROM `holding` h
  WHERE h.cage_id IS NOT NULL', 'SELECT "skip: no holding table" AS note');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- -----------------------------------------------------------------------------
-- Step 5: Merge v1 `mice_v1_archive` rows that aren't already represented.
-- v1 `mice` had a real (cage-scoped, possibly user-supplied) mouse_id, so we
-- prefer those over synthesized holding ids when they don't collide.
-- -----------------------------------------------------------------------------
SET @archive_exists := (
  SELECT COUNT(*) FROM information_schema.tables
  WHERE table_schema = DATABASE() AND table_name = 'mice_v1_archive'
);

SET @sql := IF(@archive_exists > 0, '
  INSERT IGNORE INTO `mice`
    (mouse_id, sex, dob, current_cage_id, genotype, status, notes)
  SELECT
    m.mouse_id                         AS mouse_id,
    "unknown"                          AS sex,
    NULL                                AS dob,
    m.cage_id                          AS current_cage_id,
    m.genotype                         AS genotype,
    "alive"                            AS status,
    CONCAT("Migrated from v1 mice; ",
           COALESCE(m.notes, ""))      AS notes
  FROM `mice_v1_archive` m
  WHERE m.mouse_id IS NOT NULL AND m.mouse_id != ""', 'SELECT "skip: no v1 archive" AS note');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- -----------------------------------------------------------------------------
-- Step 6: Synthesize mouse entities for breeding parents we haven't already
-- seen. Preserves dob/genotype/parent_cage facts from the v1 breeding row.
-- These mice land with status='archived' so they don't pollute live counts
-- and current_cage_id=NULL so they don't show up as living anywhere.
-- -----------------------------------------------------------------------------
SET @breeding_has_dob := (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'breeding' AND column_name = 'male_dob'
);

SET @sql := IF(@breeding_has_dob > 0, '
  INSERT IGNORE INTO `mice`
    (mouse_id, sex, dob, current_cage_id, genotype, status, notes)
  SELECT
    b.male_id,
    "male",
    b.male_dob,
    NULL,
    b.male_genotype,
    "archived",
    CONCAT("Synthesized from v1 breeding parent; parent cage: ",
           COALESCE(b.male_parent_cage, "unknown"))
  FROM `breeding` b
  WHERE b.male_id IS NOT NULL AND b.male_id != ""
    AND NOT EXISTS (SELECT 1 FROM `mice` m WHERE m.mouse_id = b.male_id)', 'SELECT "skip: breeding already migrated" AS note');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(@breeding_has_dob > 0, '
  INSERT IGNORE INTO `mice`
    (mouse_id, sex, dob, current_cage_id, genotype, status, notes)
  SELECT
    b.female_id,
    "female",
    b.female_dob,
    NULL,
    b.female_genotype,
    "archived",
    CONCAT("Synthesized from v1 breeding parent; parent cage: ",
           COALESCE(b.female_parent_cage, "unknown"))
  FROM `breeding` b
  WHERE b.female_id IS NOT NULL AND b.female_id != ""
    AND NOT EXISTS (SELECT 1 FROM `mice` m WHERE m.mouse_id = b.female_id)', 'SELECT "skip: breeding already migrated" AS note');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- -----------------------------------------------------------------------------
-- Step 7: Seed mouse_cage_history with the current cage assignment for every
-- migrated mouse. Each living mouse gets one open interval (moved_out_at NULL)
-- representing "where this mouse is right now". Future moves append rows.
-- -----------------------------------------------------------------------------
INSERT INTO `mouse_cage_history` (mouse_id, cage_id, moved_in_at, reason)
SELECT m.mouse_id, m.current_cage_id, m.created_at, 'v1 migration: initial cage assignment'
FROM `mice` m
WHERE m.current_cage_id IS NOT NULL
  AND NOT EXISTS (
    SELECT 1 FROM `mouse_cage_history` h
    WHERE h.mouse_id = m.mouse_id AND h.moved_out_at IS NULL
  );

-- -----------------------------------------------------------------------------
-- Step 8: Drop derived columns from `breeding` (the source of truth is now
-- the mouse entity). NULL out any male_id/female_id values that don't match
-- a mouse_id, otherwise the FK add will fail.
-- -----------------------------------------------------------------------------
SET @sql := IF(@breeding_has_dob > 0, '
  UPDATE `breeding` b
  LEFT JOIN `mice` m ON m.mouse_id = b.male_id
  SET b.male_id = NULL
  WHERE b.male_id IS NOT NULL AND b.male_id != "" AND m.mouse_id IS NULL', 'SELECT "skip: already cleaned" AS note');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(@breeding_has_dob > 0, '
  UPDATE `breeding` b
  LEFT JOIN `mice` m ON m.mouse_id = b.female_id
  SET b.female_id = NULL
  WHERE b.female_id IS NOT NULL AND b.female_id != "" AND m.mouse_id IS NULL', 'SELECT "skip: already cleaned" AS note');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

ALTER TABLE `breeding` DROP COLUMN IF EXISTS `male_dob`;
ALTER TABLE `breeding` DROP COLUMN IF EXISTS `female_dob`;
ALTER TABLE `breeding` DROP COLUMN IF EXISTS `male_genotype`;
ALTER TABLE `breeding` DROP COLUMN IF EXISTS `male_parent_cage`;
ALTER TABLE `breeding` DROP COLUMN IF EXISTS `female_genotype`;
ALTER TABLE `breeding` DROP COLUMN IF EXISTS `female_parent_cage`;

-- Add FK constraints on breeding.male_id / female_id -> mice.mouse_id.
-- Wrapped in dynamic SQL so re-running the migration is a no-op (information_schema
-- check skips the ALTER if the constraint already exists).
SET @has_fk_male := (
  SELECT COUNT(*) FROM information_schema.table_constraints
  WHERE table_schema = DATABASE() AND table_name = 'breeding' AND constraint_name = 'fk_breeding_male'
);
SET @sql := IF(@has_fk_male = 0, '
  ALTER TABLE `breeding`
    ADD CONSTRAINT `fk_breeding_male`
    FOREIGN KEY (`male_id`) REFERENCES `mice`(`mouse_id`)
    ON UPDATE CASCADE ON DELETE SET NULL', 'SELECT "skip: fk_breeding_male exists" AS note');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_fk_female := (
  SELECT COUNT(*) FROM information_schema.table_constraints
  WHERE table_schema = DATABASE() AND table_name = 'breeding' AND constraint_name = 'fk_breeding_female'
);
SET @sql := IF(@has_fk_female = 0, '
  ALTER TABLE `breeding`
    ADD CONSTRAINT `fk_breeding_female`
    FOREIGN KEY (`female_id`) REFERENCES `mice`(`mouse_id`)
    ON UPDATE CASCADE ON DELETE SET NULL', 'SELECT "skip: fk_breeding_female exists" AS note');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

COMMIT;

-- =============================================================================
-- Post-migration cleanup (run manually after verifying)
-- =============================================================================
-- After confirming row counts and spot-checking data, drop the v1 tables:
--
--   DROP TABLE `holding`;
--   DROP TABLE `mice_v1_archive`;
--
-- Verification queries:
--   SELECT COUNT(*) AS total_mice FROM mice;
--   SELECT status, COUNT(*) FROM mice GROUP BY status;
--   SELECT COUNT(*) AS open_intervals FROM mouse_cage_history WHERE moved_out_at IS NULL;
--   SELECT COUNT(*) AS migrated_holding FROM mice WHERE notes LIKE 'Migrated from v1 holding%';
--   SELECT COUNT(*) AS synthesized_parents FROM mice WHERE notes LIKE 'Synthesized from v1 breeding%';
--   DESCRIBE breeding;
-- =============================================================================
