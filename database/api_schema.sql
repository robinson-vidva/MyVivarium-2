-- =============================================================================
-- REST API schema additions
-- =============================================================================
-- Run this AFTER schema.sql to add API support. Idempotent on tables (uses
-- IF NOT EXISTS); the maintenance column additions assume they have not been
-- applied before — if you re-run, MySQL will error on duplicate column. In
-- that case use `database/api_setup.php` which checks INFORMATION_SCHEMA first.

CREATE TABLE IF NOT EXISTS `api_keys` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `key_hash` char(64) NOT NULL,
  `label` varchar(255) NOT NULL,
  `scopes` varchar(64) NOT NULL DEFAULT 'read',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_used_at` datetime DEFAULT NULL,
  `revoked_at` datetime DEFAULT NULL,
  `expires_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_api_key_hash` (`key_hash`),
  KEY `idx_api_keys_user` (`user_id`),
  KEY `idx_api_keys_expires` (`expires_at`),
  CONSTRAINT `fk_api_keys_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS `pending_operations` (
  `id` char(36) NOT NULL,
  `user_id` int NOT NULL,
  `method` varchar(10) NOT NULL,
  `path` varchar(512) NOT NULL,
  `body_json` mediumtext NOT NULL,
  `diff_json` mediumtext NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at` datetime NOT NULL,
  `executed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_pending_user` (`user_id`),
  KEY `idx_pending_expires` (`expires_at`),
  KEY `idx_pending_user_exec` (`user_id`, `executed_at`),
  CONSTRAINT `fk_pending_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS `api_request_log` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `user_id` int DEFAULT NULL,
  `endpoint` varchar(512) NOT NULL,
  `method` varchar(10) NOT NULL,
  `status_code` int NOT NULL,
  `response_time_ms` int NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_arl_user` (`user_id`, `created_at`),
  KEY `idx_arl_created` (`created_at`),
  CONSTRAINT `fk_arl_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS `rate_limit` (
  `key_id` int NOT NULL,
  `window_start` datetime NOT NULL,
  `request_count` int NOT NULL DEFAULT 0,
  PRIMARY KEY (`key_id`),
  CONSTRAINT `fk_rate_limit_key` FOREIGN KEY (`key_id`) REFERENCES `api_keys` (`id`) ON DELETE CASCADE
);

-- Extend the existing maintenance table to support the API note metadata.
-- These columns are additive and nullable — existing pages keep working.
ALTER TABLE `maintenance`
  ADD COLUMN `note_type`  varchar(64)  DEFAULT NULL,
  ADD COLUMN `deleted_at` datetime     DEFAULT NULL,
  ADD COLUMN `updated_at` timestamp    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;

-- AI chatbot conversation persistence (part three).
CREATE TABLE IF NOT EXISTS `ai_conversations` (
  `id` char(36) NOT NULL,
  `user_id` int NOT NULL,
  `title` varchar(200) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ai_conv_user` (`user_id`),
  KEY `idx_ai_conv_updated` (`updated_at`),
  CONSTRAINT `fk_ai_conv_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS `ai_messages` (
  `id` int NOT NULL AUTO_INCREMENT,
  `conversation_id` char(36) NOT NULL,
  `role` enum('user','assistant','tool','system_event') NOT NULL,
  `content` text,
  `tool_call_json` text,
  `tool_result_json` text,
  `pending_op_id` varchar(36) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ai_msg_conv_created` (`conversation_id`, `created_at`),
  CONSTRAINT `fk_ai_msg_conv` FOREIGN KEY (`conversation_id`) REFERENCES `ai_conversations` (`id`) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS `ai_usage_log` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `user_id` int DEFAULT NULL,
  `conversation_id` char(36) DEFAULT NULL,
  `prompt_tokens` int NOT NULL DEFAULT 0,
  `completion_tokens` int NOT NULL DEFAULT 0,
  `model` varchar(64) NOT NULL DEFAULT '',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ai_usage_user_created` (`user_id`, `created_at`),
  KEY `idx_ai_usage_conv` (`conversation_id`),
  CONSTRAINT `fk_ai_usage_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
);

-- AI Configuration storage for the admin chatbot settings (Groq key, model, prompt, toggle).
-- Values are stored encrypted with AES-256-CBC; key lives in AI_SETTINGS_ENCRYPTION_KEY.
CREATE TABLE IF NOT EXISTS `ai_settings` (
  `id` int NOT NULL AUTO_INCREMENT,
  `setting_key` varchar(64) NOT NULL,
  `setting_value` mediumtext NOT NULL,
  `updated_by` int DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_ai_settings_key` (`setting_key`),
  KEY `idx_ai_settings_updated_by` (`updated_by`),
  CONSTRAINT `fk_ai_settings_user` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
);
