-- Student-drawn signatures on the Permission to Conduct Activity / Program form.
-- Optional: the app also adds these columns automatically on first use.
ALTER TABLE `reservations`
  ADD COLUMN IF NOT EXISTS `adviser_signature`      mediumtext DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `recommending_signature` mediumtext DEFAULT NULL;
