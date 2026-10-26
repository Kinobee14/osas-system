-- E-signatures (drawn on a signature pad) for the admin side.
--  * admin_signatures       : each admin's saved signature (drawn once in Settings or when signing a form)
--  * reservation_signatures : the signature an admin placed on one reservation's
--                             "Permission to Conduct Activity / Program" form
-- Safe to run more than once. Nothing existing is altered.
CREATE TABLE IF NOT EXISTS `admin_signatures` (
  `admin_id`   int(11)    NOT NULL,
  `signature`  mediumtext NOT NULL,
  `updated_at` timestamp  NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`admin_id`),
  CONSTRAINT `fk_adminsig_admin` FOREIGN KEY (`admin_id`) REFERENCES `admins` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `reservation_signatures` (
  `id`             int(11)     NOT NULL AUTO_INCREMENT,
  `reservation_id` int(11)     NOT NULL,
  `slot`           varchar(12) NOT NULL,            -- approver1 | approver2 | noted
  `admin_id`       int(11)     DEFAULT NULL,
  `signature`      mediumtext  NOT NULL,            -- snapshot (PNG data URL) taken at signing time
  `signed_at`      datetime    NOT NULL,            -- stored in UTC
  `code`           varchar(12) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ressig_slot` (`reservation_id`, `slot`),
  CONSTRAINT `fk_ressig_res` FOREIGN KEY (`reservation_id`) REFERENCES `reservations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One drawn signature per signatory of the Permission form (Settings -> Reservation Form Signatories).
CREATE TABLE IF NOT EXISTS `signatory_signatures` (
  `slot`       varchar(12) NOT NULL,                -- approver1 | approver2 | noted
  `signature`  mediumtext  NOT NULL,                -- PNG data URL
  `updated_by` int(11)     DEFAULT NULL,
  `updated_at` timestamp   NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`slot`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
