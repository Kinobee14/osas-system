-- Adds the fields of the OSAS "Permission to Conduct Activity / Program" form
-- to `reservations`. Safe to run more than once (MariaDB 10.3+ / XAMPP).
-- Existing reservations keep working: every new column is nullable.

ALTER TABLE `reservations`
  ADD COLUMN IF NOT EXISTS `activity_scope`        varchar(10)  DEFAULT NULL AFTER `campus`,
  ADD COLUMN IF NOT EXISTS `date_prepared`         date         DEFAULT NULL AFTER `activity_scope`,
  ADD COLUMN IF NOT EXISTS `nature_of_activity`    varchar(255) DEFAULT NULL AFTER `date_prepared`,
  ADD COLUMN IF NOT EXISTS `nature_other`          varchar(150) DEFAULT NULL AFTER `nature_of_activity`,
  ADD COLUMN IF NOT EXISTS `participants`          int(11)      DEFAULT NULL AFTER `end_time`,
  ADD COLUMN IF NOT EXISTS `sponsoring_org`        varchar(150) DEFAULT NULL AFTER `participants`,
  ADD COLUMN IF NOT EXISTS `source_of_fund`        varchar(150) DEFAULT NULL AFTER `sponsoring_org`,
  ADD COLUMN IF NOT EXISTS `adviser_name`          varchar(150) DEFAULT NULL AFTER `purpose`,
  ADD COLUMN IF NOT EXISTS `recommending_name`     varchar(150) DEFAULT NULL AFTER `adviser_name`,
  ADD COLUMN IF NOT EXISTS `attachments_checklist` varchar(255) DEFAULT NULL AFTER `recommending_name`;
