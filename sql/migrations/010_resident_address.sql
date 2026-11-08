-- SenTri Migration 010: Resident home address
-- Adds the optional/required residence address used at signup and in the
-- community profile. Officials use it to verify who filed a report; it is
-- never used as the report's location (that still comes from the dropped pin).
--
-- Run AFTER sentri.sql (fresh install) or on top of any existing install.
--
-- NOTE: the column is NULL for existing accounts. The login/signup
-- auto-migrators and the resident-profile gate prompt them to complete it.

USE `sentri`;

ALTER TABLE `users`
 ADD COLUMN IF NOT EXISTS `resident_address` VARCHAR(255) NULL DEFAULT NULL
 AFTER `municipality`;
