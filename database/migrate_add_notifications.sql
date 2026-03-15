-- Migration: Add notifications table for in-app notification system
-- Run this on existing databases that don't have the notifications table yet.

CREATE TABLE IF NOT EXISTS `notifications` (
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
