-- 013_reports_custom_type.sql
-- Free-text incident type for the "Other" category.
--
-- When a resident picks "Other", the report form now offers a text field so
-- they can describe the incident instead of being limited to a fixed list.
-- NULL for every other category.

ALTER TABLE `reports`
  ADD COLUMN `custom_type` VARCHAR(100) DEFAULT NULL AFTER `category`;
