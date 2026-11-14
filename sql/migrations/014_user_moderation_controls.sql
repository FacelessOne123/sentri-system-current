-- SenTri Migration 014: User moderation controls (ban, suspend, manual verify)
-- Adds fields for permanent account ban, temporary suspension, and reasons.
-- Used by admin.php and checked across portals by core/login_handler.php.

USE `sentri`;

ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `is_banned` TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `ban_reason` VARCHAR(255) NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `banned_at` DATETIME NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `suspended_until` DATETIME NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `suspension_reason` VARCHAR(255) NULL DEFAULT NULL;
