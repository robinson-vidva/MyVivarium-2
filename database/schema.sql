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
  `assigned_by` INT NOT NULL,
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
  FOREIGN KEY (`assigned_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
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

-- Table for storing maintenance logs
CREATE TABLE `maintenance` (
  `id` int NOT NULL AUTO_INCREMENT,
  `cage_id` varchar(255) NOT NULL,
  `user_id` int NOT NULL,
  `comments` text DEFAULT NULL,
  `timestamp` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  FOREIGN KEY (`cage_id`) REFERENCES `cages` (`cage_id`) ON UPDATE CASCADE,
  FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
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

-- Insert initial data into the users table
INSERT INTO `users` (`name`, `username`, `position`, `role`, `password`, `status`, `reset_token`, `reset_token_expiration`, `login_attempts`, `account_locked`, `email_verified`, `email_token`, `initials`)
VALUES ('Temporary Admin', 'admin@myvivarium.online', 'Principal Investigator', 'admin', '$2y$10$roVlhpjZsRFXY.m9JtRB/OAaN2dp50O7D2J5idI2MsUotxMyrHRZ6', 'approved', NULL, NULL, 0, NULL, 1, NULL, 'TAN');

-- Insert initial data into the strains table
INSERT INTO `strains` (`str_id`, `str_name`, `str_aka`, `str_url`, `str_rrid`)
VALUES ('035561', 'STOCK Tc(HSA21,CAG-EGFP)1Yakaz/J', 'B6D2F1 TcMAC21', 'https://www.jax.org/strain/035561', 'IMSR_JAX:035561');
