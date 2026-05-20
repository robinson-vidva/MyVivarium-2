-- =============================================================================
-- Migration: multi-configuration AI provider tables.
-- Adds ai_configs, ai_config_settings, and ai_usage_log.config_id.
--
-- Safe to run on a database that already has the older single-slot AI tables
-- (ai_settings + ai_usage_log + ai_chat_rate). Existing per-provider keys
-- (groq_api_key, openai_api_key, custom_*) are left untouched; the application
-- migrates them lazily into ai_configs rows on the first admin save once the
-- table exists.
--
-- Run with: mysql -u <user> -p <database> < database/migrations/2026_05_20_ai_configs.sql
-- =============================================================================

CREATE TABLE IF NOT EXISTS `ai_configs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nickname` varchar(100) NOT NULL,
  `provider` varchar(32) NOT NULL,
  `model` varchar(255) DEFAULT NULL,
  `preset` varchar(64) DEFAULT NULL,
  `api_key_primary` mediumtext,
  `api_key_secondary` mediumtext,
  `base_url` varchar(512) DEFAULT NULL,
  `system_prompt` text,
  `temperature` decimal(3,2) DEFAULT NULL,
  `max_tokens` int DEFAULT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int NOT NULL DEFAULT 0,
  `is_default` tinyint(1) NOT NULL DEFAULT 0,
  `created_by` int DEFAULT NULL,
  `updated_by` int DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_ai_configs_nickname` (`nickname`),
  KEY `idx_ai_configs_order` (`sort_order`),
  KEY `idx_ai_configs_enabled_order` (`enabled`, `sort_order`),
  CONSTRAINT `fk_ai_configs_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_ai_configs_updater` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS `ai_config_settings` (
  `id` int NOT NULL AUTO_INCREMENT,
  `config_id` int NOT NULL,
  `setting_key` varchar(128) NOT NULL,
  `setting_value` mediumtext,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_ai_config_settings_key` (`config_id`, `setting_key`),
  KEY `idx_ai_config_settings_config` (`config_id`),
  CONSTRAINT `fk_ai_config_settings_config` FOREIGN KEY (`config_id`) REFERENCES `ai_configs` (`id`) ON DELETE CASCADE
);

-- Add config_id to ai_usage_log if missing. MySQL has no native
-- ADD COLUMN IF NOT EXISTS; this block uses prepared SQL guarded by
-- INFORMATION_SCHEMA so re-runs are no-ops.
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME   = 'ai_usage_log'
     AND COLUMN_NAME  = 'config_id'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE `ai_usage_log` ADD COLUMN `config_id` int DEFAULT NULL, ADD KEY `idx_ai_usage_config_created` (`config_id`, `created_at`)',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
