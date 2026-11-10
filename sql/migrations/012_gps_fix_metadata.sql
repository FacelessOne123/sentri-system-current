-- 012_gps_fix_metadata.sql
-- Fix metadata for the last-known-location fallback.
--
-- The browser caches the last good fix locally and mirrors it here so the
-- position survives a dropped connection and follows the user to another
-- browser. `gps_source` records where the fix came from so the UI can label
-- it honestly (a stale fix is never presented as live GPS).

ALTER TABLE `users`
  ADD COLUMN `gps_accuracy` DECIMAL(8,2) DEFAULT NULL AFTER `gps_lng`,
  ADD COLUMN `gps_at` DATETIME DEFAULT NULL AFTER `gps_accuracy`,
  ADD COLUMN `gps_source` ENUM('browser','manual') DEFAULT NULL AFTER `gps_at`;
