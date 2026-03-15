-- Migration: Add created_at timestamp to cages table
-- This enables sorting cages by date added

ALTER TABLE `cages` ADD COLUMN `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP;
