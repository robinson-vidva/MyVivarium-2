-- Table for storing IACUC (Institutional Animal Care and Use Committee) records
CREATE TABLE `iacuc` (
  `iacuc_id` varchar(255) NOT NULL,
  `iacuc_title` varchar(255) NOT NULL,
  `file_url` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`iacuc_id`)
);

-- Table for storing user information
CREATE TABLE `users` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL, 
  `username` varchar(255) NOT NULL,
  `position` varchar(255) NOT NULL,
  `role` varchar(255) NOT NULL,
  `password` varchar(255) NOT NULL,
  `status` varchar(255) NOT NULL,
  `reset_token` varchar(255) DEFAULT NULL,
  `reset_token_expiration` datetime DEFAULT NULL,
  `login_attempts` int DEFAULT 0,
  `account_locked` datetime DEFAULT NULL,
  `email_verified` tinyint DEFAULT 0,
  `email_token` varchar(255) DEFAULT NULL,
  `initials` varchar(5) DEFAULT NULL,
  PRIMARY KEY (`id`)
);

-- Table for storing cage information
CREATE TABLE `cages` (
  `cage_id` varchar(255) NOT NULL UNIQUE,
  `pi_name` int DEFAULT NULL,
  `quantity` int DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `status` enum('active', 'archived') NOT NULL DEFAULT 'active',
  `room` varchar(255) DEFAULT NULL,
  `rack` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`cage_id`),
  FOREIGN KEY (`pi_name`) REFERENCES `users` (`id`) ON DELETE SET NULL
);

-- Junction table for associating cages with IACUC records
CREATE TABLE `cage_iacuc` (
  `cage_id` varchar(255) NOT NULL,
  `iacuc_id` varchar(255) NOT NULL,
  PRIMARY KEY (`cage_id`, `iacuc_id`),
  FOREIGN KEY (`cage_id`) REFERENCES `cages` (`cage_id`) ON UPDATE CASCADE,
  FOREIGN KEY (`iacuc_id`) REFERENCES `iacuc` (`iacuc_id`) ON DELETE CASCADE
);

-- Junction table for associating cages with users
CREATE TABLE `cage_users` (
  `cage_id` varchar(255) NOT NULL,
  `user_id` int NOT NULL,
  PRIMARY KEY (`cage_id`, `user_id`),
  FOREIGN KEY (`cage_id`) REFERENCES `cages` (`cage_id`) ON UPDATE CASCADE,
  FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
);

-- Table for storing strain information
CREATE TABLE `strains` (
  `id` int NOT NULL AUTO_INCREMENT,
  `str_id` varchar(255) NOT NULL,
  `str_name` varchar(255) NOT NULL,
  `str_aka` varchar(255) DEFAULT NULL,
  `str_url` varchar(255) DEFAULT NULL,
  `str_rrid` varchar(255) DEFAULT NULL,
  `str_notes` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_strains_str_id` (`str_id`)
);

-- =============================================================================
-- Canonical mouse entity (v2)
-- =============================================================================
-- A mouse is a first-class, long-lived entity. Identity (`mouse_id`) survives
-- cage moves, parent lookups, and lifecycle transitions. Replaces the v1
-- `holding` and per-cage `mice` tables, both of which were cage-scoped rows
-- without independent identity.
--
-- mouse_id: user-supplied, globally unique, EDITABLE (rename via ON UPDATE
--           CASCADE — self-FK + breeding FKs follow). Suggested default
--           composite (e.g., {cage_id}_{ear_code}) is generated client-side
--           but may be overridden.
-- dob/sex:  required for NEW entries (enforced in the app layer); nullable in
--           the schema so legacy rows imported from v1 without these values
--           can land truthfully rather than via sentinel dates.
-- parents:  hybrid model — `sire_id`/`dam_id` are FKs (NULL allowed for
--           founders); `sire_external_ref`/`dam_external_ref` carry free-text
--           descriptors for parents that live outside this system.
-- source_cage_label: free-text "the cage this mouse came from", primarily
--           used to preserve V1's `holding.parent_cg` value (cage-level
--           lineage in V1; in V2 lineage is per-mouse via sire/dam, but we
--           keep the legacy parent-cage label so imported records don't
--           lose the breadcrumb).
-- status:   alive → sacrificed | transferred_out | archived. `archived` is the
--           soft-delete equivalent. Hard delete is admin-only and writes to
--           activity_log first.
CREATE TABLE `mice` (
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
  `source_cage_label` varchar(255) DEFAULT NULL,
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
  CONSTRAINT `fk_mice_cage`   FOREIGN KEY (`current_cage_id`) REFERENCES `cages`   (`cage_id`)  ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT `fk_mice_strain` FOREIGN KEY (`strain`)          REFERENCES `strains` (`str_id`)   ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT `fk_mice_sire`   FOREIGN KEY (`sire_id`)         REFERENCES `mice`    (`mouse_id`) ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT `fk_mice_dam`    FOREIGN KEY (`dam_id`)          REFERENCES `mice`    (`mouse_id`) ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT `fk_mice_creator` FOREIGN KEY (`created_by`)     REFERENCES `users`   (`id`)       ON DELETE SET NULL
);

-- =============================================================================
-- Mouse cage-move history
-- =============================================================================
-- Append-only log of every cage assignment for a mouse. The "current" cage is
-- the row where moved_out_at IS NULL. `mice.current_cage_id` is a denormalized
-- pointer to that row's cage_id, kept in sync by the application layer.
--
-- cage_id NULL is legal: represents an interval where the mouse has no cage
-- (sacrificed, transferred out, awaiting placement).
CREATE TABLE `mouse_cage_history` (
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
  CONSTRAINT `fk_mch_mouse`  FOREIGN KEY (`mouse_id`) REFERENCES `mice`  (`mouse_id`) ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT `fk_mch_cage`   FOREIGN KEY (`cage_id`)  REFERENCES `cages` (`cage_id`)  ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT `fk_mch_user`   FOREIGN KEY (`moved_by`) REFERENCES `users` (`id`)       ON DELETE SET NULL
);

-- =============================================================================
-- Breeding cages (v2)
-- =============================================================================
-- male_id / female_id are FK references into `mice`. Per-parent dob/genotype/
-- parent_cage columns from v1 are removed — those facts now live on the mouse
-- entity and are looked up via JOIN. NULL FKs allowed when the parent isn't
-- yet in the system; populate as soon as the mouse is registered.
CREATE TABLE `breeding` (
  `id` int NOT NULL AUTO_INCREMENT,
  `cage_id` varchar(255) NOT NULL,
  `cross` varchar(255) DEFAULT NULL,
  `male_id` varchar(255) DEFAULT NULL,
  `female_id` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_breeding_male` (`male_id`),
  KEY `idx_breeding_female` (`female_id`),
  CONSTRAINT `fk_breeding_cage`   FOREIGN KEY (`cage_id`)    REFERENCES `cages` (`cage_id`)  ON UPDATE CASCADE,
  CONSTRAINT `fk_breeding_male`   FOREIGN KEY (`male_id`)    REFERENCES `mice`  (`mouse_id`) ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT `fk_breeding_female` FOREIGN KEY (`female_id`)  REFERENCES `mice`  (`mouse_id`) ON UPDATE CASCADE ON DELETE SET NULL
);

-- Table for storing litter information
CREATE TABLE `litters` (
  `id` int NOT NULL AUTO_INCREMENT,
  `cage_id` varchar(255) NOT NULL,
  `dom` date NOT NULL,
  `litter_dob` date DEFAULT NULL,
  `pups_alive` int NOT NULL,
  `pups_dead` int NOT NULL,
  `pups_male` int DEFAULT NULL,
  `pups_female` int DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`cage_id`) REFERENCES `cages` (`cage_id`) ON UPDATE CASCADE
);

-- Table for storing file information related to cages
CREATE TABLE `files` (
  `id` int NOT NULL AUTO_INCREMENT,
  `file_name` varchar(255) NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `cage_id` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`cage_id`) REFERENCES `cages` (`cage_id`) ON UPDATE CASCADE
);

-- Table for storing notes related to cages and users
CREATE TABLE `notes` (
  `id` int NOT NULL AUTO_INCREMENT,
  `cage_id` varchar(255) DEFAULT NULL,
  `note_text` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `user_id` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`cage_id`) REFERENCES `cages` (`cage_id`) ON UPDATE CASCADE,
  FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
);

-- Table for storing tasks information
CREATE TABLE `tasks` (
  `id` int NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `description` text NOT NULL,
  `assigned_by` int DEFAULT NULL,
  `assigned_to` varchar(50) NOT NULL,
  `status` enum('Pending','In Progress','Completed') NOT NULL DEFAULT 'Pending',
  `completion_date` date DEFAULT NULL,
  `cage_id` varchar(255) DEFAULT NULL,
  `creation_date` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  FOREIGN KEY (`cage_id`) REFERENCES `cages` (`cage_id`) ON UPDATE CASCADE,
  FOREIGN KEY (`assigned_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
);

-- Table for storing outbox email information
CREATE TABLE `outbox` (
  `id` int NOT NULL AUTO_INCREMENT,
  `recipient` varchar(255) NOT NULL,
  `subject` varchar(255) NOT NULL,
  `body` text NOT NULL,
  `status` enum('pending','sent','failed') NOT NULL DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `scheduled_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `sent_at` timestamp NULL DEFAULT NULL,
  `error_message` text DEFAULT NULL,
  `task_id` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_outbox_recipient` (`recipient`),
  FOREIGN KEY (`task_id`) REFERENCES `tasks` (`id`) ON DELETE SET NULL
);

-- Table for storing reminders
CREATE TABLE `reminders` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `title` VARCHAR(255) NOT NULL,
  `description` TEXT NOT NULL,
  -- Nullable + ON DELETE SET NULL so a reminder created by a user who is later
  -- deleted survives (orphaned) rather than being cascade-deleted out from under
  -- the people it's assigned to.
  `assigned_by` INT DEFAULT NULL,
  `assigned_to` VARCHAR(255) NOT NULL,
  `recurrence_type` ENUM('daily', 'weekly', 'monthly') NOT NULL,
  `day_of_week` ENUM('Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday') DEFAULT NULL,
  `day_of_month` INT DEFAULT NULL,
  `time_of_day` TIME NOT NULL,
  `status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
  `cage_id` VARCHAR(255) DEFAULT NULL,
  `creation_date` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `last_task_created` DATETIME NULL,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`cage_id`) REFERENCES `cages` (`cage_id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_reminders_assigned_by` FOREIGN KEY (`assigned_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
);

-- Table for in-app notifications
CREATE TABLE `notifications` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `user_id` INT NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `message` TEXT DEFAULT NULL,
  `link` VARCHAR(255) DEFAULT NULL,
  `type` ENUM('reminder', 'task', 'system') NOT NULL DEFAULT 'system',
  `is_read` TINYINT NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_notifications_user` (`user_id`, `is_read`, `created_at`),
  FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
);

-- Table for storing maintenance logs.
-- note_type / deleted_at / updated_at support the REST API note metadata
-- (soft-delete + edit tracking); they are nullable/additive so the web
-- maintenance form keeps working unchanged.
CREATE TABLE `maintenance` (
  `id` int NOT NULL AUTO_INCREMENT,
  `cage_id` varchar(255) NOT NULL,
  -- Nullable + ON DELETE SET NULL so the maintenance audit trail survives when
  -- the logging user is deleted (the record is kept, attribution is cleared).
  `user_id` int DEFAULT NULL,
  `comments` text DEFAULT NULL,
  `note_type` varchar(64) DEFAULT NULL,
  `timestamp` timestamp NOT NULL DEFAULT current_timestamp(),
  `deleted_at` datetime DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`cage_id`) REFERENCES `cages` (`cage_id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_maintenance_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
);

-- Table for storing activity/audit log
CREATE TABLE `activity_log` (
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

-- Table for storing system settings
CREATE TABLE `settings` (
  `name` varchar(255) NOT NULL,
  `value` varchar(255) NOT NULL,
  PRIMARY KEY (`name`)
);

-- Email transport configuration managed through the admin Email Settings page.
-- Rows with is_encrypted = 1 store AES-256-CBC ciphertext (base64(iv) ":" base64(ct))
-- using the same key (AI_SETTINGS_ENCRYPTION_KEY) as the ai_settings table.
CREATE TABLE `email_settings` (
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

-- =============================================================================
-- REST API tables
-- =============================================================================
-- Token-based API access, per-key rate limiting, request audit log, and the
-- two-phase "pending operation" confirm flow for destructive API writes.

CREATE TABLE `api_keys` (
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

CREATE TABLE `pending_operations` (
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

CREATE TABLE `api_request_log` (
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

CREATE TABLE `rate_limit` (
  `key_id` int NOT NULL,
  `window_start` datetime NOT NULL,
  `request_count` int NOT NULL DEFAULT 0,
  PRIMARY KEY (`key_id`),
  CONSTRAINT `fk_rate_limit_key` FOREIGN KEY (`key_id`) REFERENCES `api_keys` (`id`) ON DELETE CASCADE
);

-- =============================================================================
-- AI chatbot tables
-- =============================================================================
-- Conversation persistence, per-message records, token-usage accounting,
-- per-user chat rate limiting, single-slot legacy settings (ai_settings), and
-- the multi-configuration provider profiles (ai_configs / ai_config_settings).
-- Secret columns (api_key_primary/secondary, ai_settings.setting_value,
-- ai_config_settings.setting_value) hold AES-256-CBC ciphertext keyed by
-- AI_SETTINGS_ENCRYPTION_KEY.

CREATE TABLE `ai_conversations` (
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

CREATE TABLE `ai_messages` (
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

CREATE TABLE `ai_usage_log` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `user_id` int DEFAULT NULL,
  `conversation_id` char(36) DEFAULT NULL,
  `prompt_tokens` int NOT NULL DEFAULT 0,
  `completion_tokens` int NOT NULL DEFAULT 0,
  `estimated_prompt_tokens` int NOT NULL DEFAULT 0,
  `model` varchar(64) NOT NULL DEFAULT '',
  `provider` varchar(16) NOT NULL DEFAULT '',
  `config_id` int DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ai_usage_user_created` (`user_id`, `created_at`),
  KEY `idx_ai_usage_conv` (`conversation_id`),
  KEY `idx_ai_usage_config_created` (`config_id`, `created_at`),
  CONSTRAINT `fk_ai_usage_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
);

-- Per-user AI chatbot rate-limit counters. Separate from the per-API-key REST
-- rate limit. One row per (user, window_kind, window_start) triple.
CREATE TABLE `ai_chat_rate` (
  `user_id` int NOT NULL,
  `window_kind` enum('minute','day') NOT NULL,
  `window_start` datetime NOT NULL,
  `count` int NOT NULL DEFAULT 0,
  PRIMARY KEY (`user_id`, `window_kind`, `window_start`),
  KEY `idx_aicr_user_kind` (`user_id`, `window_kind`)
);

-- Legacy single-slot AI settings (Groq key, model, prompt, toggle). Retained
-- alongside ai_configs; the app migrates these into ai_configs rows lazily.
CREATE TABLE `ai_settings` (
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

-- Multi-configuration AI provider profiles. The chatbot iterates enabled rows
-- by sort_order ascending and falls back on transient failures.
CREATE TABLE `ai_configs` (
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

-- Free-form per-config key/value rows (provider-specific body params and
-- 'header.'-prefixed request headers). Values encrypted at rest.
CREATE TABLE `ai_config_settings` (
  `id` int NOT NULL AUTO_INCREMENT,
  `config_id` int NOT NULL,
  `setting_key` varchar(128) NOT NULL,
  `setting_value` mediumtext,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_ai_config_settings_key` (`config_id`, `setting_key`),
  KEY `idx_ai_config_settings_config` (`config_id`),
  CONSTRAINT `fk_ai_config_settings_config` FOREIGN KEY (`config_id`) REFERENCES `ai_configs` (`id`) ON DELETE CASCADE
);

-- Insert initial data into the users table
INSERT INTO `users` (`name`, `username`, `position`, `role`, `password`, `status`, `reset_token`, `reset_token_expiration`, `login_attempts`, `account_locked`, `email_verified`, `email_token`, `initials`)
VALUES ('Temporary Admin', 'admin@myvivarium.online', 'Principal Investigator', 'admin', '$2y$10$roVlhpjZsRFXY.m9JtRB/OAaN2dp50O7D2J5idI2MsUotxMyrHRZ6', 'approved', NULL, NULL, 0, NULL, 1, NULL, 'TAN');

-- Insert initial data into the strains table
INSERT INTO `strains` (`str_id`, `str_name`, `str_aka`, `str_url`, `str_rrid`)
VALUES ('035561', 'STOCK Tc(HSA21,CAG-EGFP)1Yakaz/J', 'B6D2F1 TcMAC21', 'https://www.jax.org/strain/035561', 'IMSR_JAX:035561');
