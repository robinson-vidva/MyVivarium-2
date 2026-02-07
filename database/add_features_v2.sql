-- Migration: Add features v2
-- Features: Archive cages, cage location fields, genotype fields
-- Run this migration on existing databases

-- Feature 3: Archive cage instead of hard delete
ALTER TABLE `cages` ADD COLUMN `status` ENUM('active', 'archived') NOT NULL DEFAULT 'active';

-- Feature 6: Add location fields to cages (room, rack)
ALTER TABLE `cages` ADD COLUMN `room` VARCHAR(255) DEFAULT NULL;
ALTER TABLE `cages` ADD COLUMN `rack` VARCHAR(255) DEFAULT NULL;

-- Feature 6: Add genotype fields to holding cages
ALTER TABLE `holding` ADD COLUMN `genotype` VARCHAR(255) DEFAULT NULL;

-- Feature 6: Add genotype fields to breeding cages
ALTER TABLE `breeding` ADD COLUMN `male_genotype` VARCHAR(255) DEFAULT NULL;
ALTER TABLE `breeding` ADD COLUMN `female_genotype` VARCHAR(255) DEFAULT NULL;

-- Feature 6: Make holding fields truly optional (allow NULL)
ALTER TABLE `holding` MODIFY COLUMN `dob` DATE DEFAULT NULL;
ALTER TABLE `holding` MODIFY COLUMN `parent_cg` VARCHAR(255) DEFAULT NULL;

-- Feature 6: Make breeding fields truly optional (allow NULL)
ALTER TABLE `breeding` MODIFY COLUMN `cross` VARCHAR(255) DEFAULT NULL;
ALTER TABLE `breeding` MODIFY COLUMN `male_id` VARCHAR(255) DEFAULT NULL;
ALTER TABLE `breeding` MODIFY COLUMN `female_id` VARCHAR(255) DEFAULT NULL;
ALTER TABLE `breeding` MODIFY COLUMN `male_dob` DATE DEFAULT NULL;
ALTER TABLE `breeding` MODIFY COLUMN `female_dob` DATE DEFAULT NULL;
