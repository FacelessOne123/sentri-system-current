-- 012_gps_bridge.sql
-- GPS bridge + last-known-location support.
--
-- Adds fix metadata to users, one-time pairing codes for local GPS bridges,
-- long-lived bridge device tokens and a short location history.

ALTER TABLE `users`
  ADD COLUMN `gps_accuracy` DECIMAL(8,2) DEFAULT NULL AFTER `gps_lng`,
  ADD COLUMN `gps_at` DATETIME DEFAULT NULL AFTER `gps_accuracy`,
  ADD COLUMN `gps_source` ENUM('browser','bridge','manual') DEFAULT NULL AFTER `gps_at`;

CREATE TABLE IF NOT EXISTS `gps_pair_codes` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `user_id` INT(11) NOT NULL,
  `code_hash` CHAR(64) NOT NULL,
  `expires_at` DATETIME NOT NULL,
  `used_at` DATETIME DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_pair_code` (`code_hash`),
  KEY `idx_pair_user` (`user_id`),
  CONSTRAINT `fk_pair_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `user_gps_devices` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `user_id` INT(11) NOT NULL,
  `label` VARCHAR(100) NOT NULL,
  `token_hash` CHAR(64) NOT NULL,
  `last_seen_at` DATETIME DEFAULT NULL,
  `revoked_at` DATETIME DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_gps_token` (`token_hash`),
  KEY `idx_gps_device_user` (`user_id`),
  CONSTRAINT `fk_gps_device_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `user_gps_fixes` (
  `id` BIGINT(20) NOT NULL AUTO_INCREMENT,
  `user_id` INT(11) NOT NULL,
  `device_id` INT(11) DEFAULT NULL,
  `latitude` DECIMAL(10,7) NOT NULL,
  `longitude` DECIMAL(10,7) NOT NULL,
  `accuracy` DECIMAL(8,2) DEFAULT NULL,
  `source` ENUM('browser','bridge','manual') NOT NULL DEFAULT 'bridge',
  `recorded_at` DATETIME DEFAULT NULL,
  `received_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_gps_fix_user` (`user_id`,`received_at`),
  KEY `idx_gps_fix_received` (`received_at`),
  CONSTRAINT `fk_gps_fix_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_gps_fix_device` FOREIGN KEY (`device_id`) REFERENCES `user_gps_devices` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
