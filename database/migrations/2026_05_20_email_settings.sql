-- Migration: email_settings table for the admin Email Settings page.
-- Adds a single key/value table whose secret rows are encrypted with the
-- same AES-256-CBC helper (and the same AI_SETTINGS_ENCRYPTION_KEY env
-- value) that already protects the ai_settings table.
--
-- Run with: mysql -u <user> -p <database> < database/migrations/2026_05_20_email_settings.sql

CREATE TABLE IF NOT EXISTS `email_settings` (
  `id` int NOT NULL AUTO_INCREMENT,
  `setting_key` varchar(64) NOT NULL,
  `setting_value` mediumtext NOT NULL,
  `is_encrypted` tinyint(1) NOT NULL DEFAULT 0,
  `updated_by` int DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_email_settings_key` (`setting_key`),
  KEY `idx_email_settings_updated_by` (`updated_by`),
  CONSTRAINT `fk_email_settings_user` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
);
