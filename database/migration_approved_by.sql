-- Lets the student edit the "Approved by" signatories on the Permission form.
-- Safe to run more than once (MariaDB 10.3+ / XAMPP). NULL = use the default names.
ALTER TABLE `reservations`
  ADD COLUMN IF NOT EXISTS `approver1_name`  varchar(150) DEFAULT NULL AFTER `recommending_name`,
  ADD COLUMN IF NOT EXISTS `approver1_title` varchar(150) DEFAULT NULL AFTER `approver1_name`,
  ADD COLUMN IF NOT EXISTS `approver2_name`  varchar(150) DEFAULT NULL AFTER `approver1_title`,
  ADD COLUMN IF NOT EXISTS `approver2_title` varchar(150) DEFAULT NULL AFTER `approver2_name`;
