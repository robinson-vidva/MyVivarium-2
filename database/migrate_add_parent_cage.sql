-- Migration: Add parent cage fields to breeding table
-- These fields track the source/parent cage for male and female mice in breeding cages

ALTER TABLE `breeding` ADD COLUMN `male_parent_cage` varchar(255) DEFAULT NULL AFTER `male_genotype`;
ALTER TABLE `breeding` ADD COLUMN `female_parent_cage` varchar(255) DEFAULT NULL AFTER `female_genotype`;
