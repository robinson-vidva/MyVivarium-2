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
  `suggestions_json` text DEFAULT NULL,
  `tokens_json` text DEFAULT NULL,
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
  `estimated_prompt_tokens` int NOT NULL DEFAULT 0,
  `model` varchar(64) NOT NULL DEFAULT '',
  `provider` varchar(16) NOT NULL DEFAULT '',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ai_usage_user_created` (`user_id`, `created_at`),
  KEY `idx_ai_usage_conv` (`conversation_id`),
  CONSTRAINT `fk_ai_usage_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
);

-- Per-user AI chatbot rate-limit counters. Separate from the per-API-key
-- REST rate limit. One row per (user, window_kind, window_start) triple.
CREATE TABLE IF NOT EXISTS `ai_chat_rate` (
  `user_id` int NOT NULL,
  `window_kind` enum('minute','day') NOT NULL,
  `window_start` datetime NOT NULL,
  `count` int NOT NULL DEFAULT 0,
  PRIMARY KEY (`user_id`, `window_kind`, `window_start`),
  KEY `idx_aicr_user_kind` (`user_id`, `window_kind`)
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

-- Multi-configuration AI provider table. Each row is a named provider profile
-- (nickname) with its own model, API keys, base URL, system prompt override,
-- temperature, max tokens, enable flag, sort order, and default flag. The
-- chatbot iterates enabled rows by sort_order ascending and falls back on
-- transient failures (429/5xx/network); within each config it tries the
-- primary key first then the secondary key. api_key_primary / api_key_secondary
-- are encrypted with the same AES-256-CBC scheme as ai_settings.
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

-- Free-form key/value rows attached to a config. Used for provider-specific
-- request parameters (top_p, presence_penalty, response_format, seed, …).
-- A row whose key begins with 'header.' is sent as an HTTP request header
-- (with the 'header.' prefix stripped); all other rows are merged into the
-- JSON request body. Values are encrypted at rest because they may contain
-- secondary tokens (APIM subscription keys, org ids).
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

-- Per-config attribution on the usage log so the admin token-usage popup
-- can break tokens down by nickname. Nullable because legacy rows predate
-- the configs table.
ALTER TABLE `ai_usage_log` ADD COLUMN `config_id` int DEFAULT NULL,
  ADD KEY `idx_ai_usage_config_created` (`config_id`, `created_at`);
