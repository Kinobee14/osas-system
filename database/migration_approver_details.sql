-- Per-approver status, approval date and notation for the reservation form.
-- Safe to run more than once (MariaDB 10.3+ / XAMPP).
ALTER TABLE `reservations`
  ADD COLUMN IF NOT EXISTS `approver1_status`   varchar(20)  NOT NULL DEFAULT 'pending',
  ADD COLUMN IF NOT EXISTS `approver1_date`     date         DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `approver1_notation` varchar(500) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `approver2_status`   varchar(20)  NOT NULL DEFAULT 'pending',
  ADD COLUMN IF NOT EXISTS `approver2_date`     date         DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `approver2_notation` varchar(500) DEFAULT NULL;
