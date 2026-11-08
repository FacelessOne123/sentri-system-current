-- SenTri Migration 009: Barangay change lock
-- Adds the timestamp that enforces the anti-abuse lock on a community
-- member's assigned barangay. portal/community.php writes it whenever the
-- barangay is changed and refuses further changes until the lock window
-- (BRGY_LOCK_MINUTES, 30 days) has elapsed.
--
-- Run AFTER sentri.sql (fresh install) or on top of any existing install.
--
-- NOTE: the column is NULL for every existing account, which means the first
-- assignment (or first change) is always allowed. Nothing is backfilled on
-- purpose: members who have never picked a barangay must be able to pick one.

USE `sentri`;

ALTER TABLE users
 ADD COLUMN IF NOT EXISTS `barangay_changed_at` DATETIME NULL DEFAULT NULL
 AFTER `barangay_name`;
