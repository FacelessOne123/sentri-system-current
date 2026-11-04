-- SenTri Migration 008: First-login guide video flag
-- Adds the per-account flag that drives the one-time "How to use SenTri"
-- guide popup shown after the first community sign-in.
--
-- api/guide.php flips this flag 0 -> 1 atomically; only the request that
-- performs the flip is answered with {"show":true}, so the popup plays
-- exactly once per account even if the user double-clicks or opens a
-- second tab.
--
-- Run AFTER sentri.sql (fresh install) or on top of any existing install.
--
-- NOTE: the column defaults to 0 and is intentionally NOT backfilled, so
-- accounts that already exist see the guide once on their next sign-in.
-- Backfill with `UPDATE users SET guide_seen = 1;` before running this
-- migration if the guide should be reserved for brand-new accounts only.

USE `sentri`;

ALTER TABLE users
 ADD COLUMN IF NOT EXISTS `guide_seen` TINYINT(1) NOT NULL DEFAULT 0
 AFTER `force_password_reset`;
