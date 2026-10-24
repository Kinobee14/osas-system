-- Adds the "reason for declining" columns. Safe to run more than once (MariaDB 10.3+ / XAMPP).
ALTER TABLE `reservation_votes`
  ADD COLUMN IF NOT EXISTS `reason` varchar(500) DEFAULT NULL AFTER `vote`;

ALTER TABLE `reservations`
  ADD COLUMN IF NOT EXISTS `decline_reason` text DEFAULT NULL AFTER `remarks`;
