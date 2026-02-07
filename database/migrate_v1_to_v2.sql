-- =============================================================================
-- MyVivarium v1 -> v2 In-Place SQL Migration
-- =============================================================================
--
-- Run this on your EXISTING MyVivarium database to upgrade it to v2 schema.
-- This is the recommended approach if you want to upgrade in-place rather
-- than creating a new database.
--
-- IMPORTANT: Back up your database before running this!
--   mysqldump -u root -p myvivarium > backup_before_v2.sql
--
-- USAGE:
--   mysql -u root -p myvivarium < migrate_v1_to_v2.sql
--
-- =============================================================================

-- Step 1: Add vivarium_manager role support
-- (Auto-assign vivarium_manager role to users with matching positions)
UPDATE users SET role = 'vivarium_manager'
WHERE position IN ('Vivarium Manager', 'Animal Care Technician')
AND role = 'user';

-- Step 2: Add archive/status column to cages
ALTER TABLE `cages` ADD COLUMN IF NOT EXISTS `status` ENUM('active', 'archived') NOT NULL DEFAULT 'active';

-- Step 3: Add location tracking to cages
ALTER TABLE `cages` ADD COLUMN IF NOT EXISTS `room` VARCHAR(255) DEFAULT NULL;
ALTER TABLE `cages` ADD COLUMN IF NOT EXISTS `rack` VARCHAR(255) DEFAULT NULL;

-- Step 4: Add genotype field to holding cages
ALTER TABLE `holding` ADD COLUMN IF NOT EXISTS `genotype` VARCHAR(255) DEFAULT NULL;

-- Step 5: Add genotype fields to breeding cages
ALTER TABLE `breeding` ADD COLUMN IF NOT EXISTS `male_genotype` VARCHAR(255) DEFAULT NULL;
ALTER TABLE `breeding` ADD COLUMN IF NOT EXISTS `female_genotype` VARCHAR(255) DEFAULT NULL;

-- Step 6: Make holding cage fields truly optional (allow NULL)
ALTER TABLE `holding` MODIFY COLUMN `dob` DATE DEFAULT NULL;
ALTER TABLE `holding` MODIFY COLUMN `parent_cg` VARCHAR(255) DEFAULT NULL;

-- Step 7: Make breeding cage fields truly optional (allow NULL)
ALTER TABLE `breeding` MODIFY COLUMN `cross` VARCHAR(255) DEFAULT NULL;
ALTER TABLE `breeding` MODIFY COLUMN `male_id` VARCHAR(255) DEFAULT NULL;
ALTER TABLE `breeding` MODIFY COLUMN `female_id` VARCHAR(255) DEFAULT NULL;
ALTER TABLE `breeding` MODIFY COLUMN `male_dob` DATE DEFAULT NULL;
ALTER TABLE `breeding` MODIFY COLUMN `female_dob` DATE DEFAULT NULL;

-- Step 8: Set all existing cages as active
UPDATE `cages` SET `status` = 'active' WHERE `status` IS NULL OR `status` = '';

-- Step 9: Create activity log table (for audit trail)
CREATE TABLE IF NOT EXISTS `activity_log` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int DEFAULT NULL,
  `action` varchar(50) NOT NULL,
  `entity_type` varchar(50) NOT NULL,
  `entity_id` varchar(255) DEFAULT NULL,
  `details` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_activity_log_user` (`user_id`),
  KEY `idx_activity_log_entity` (`entity_type`, `entity_id`),
  KEY `idx_activity_log_created` (`created_at`),
  FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
);

-- =============================================================================
-- Verification queries - run these to confirm migration success
-- =============================================================================
-- SELECT COUNT(*) AS total_cages, SUM(status = 'active') AS active, SUM(status = 'archived') AS archived FROM cages;
-- DESCRIBE cages;
-- DESCRIBE holding;
-- DESCRIBE breeding;
-- SELECT DISTINCT role FROM users;
-- SHOW CREATE TABLE activity_log;
