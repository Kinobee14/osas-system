-- Admin-editable "Approved by" and "Noted by" signatories for the
-- Permission to Conduct Activity / Program form.
-- Safe to run more than once. Seeded with the names that used to be hard-coded.
CREATE TABLE IF NOT EXISTS `form_signatories` (
  `id`              tinyint(4)   NOT NULL,
  `approver1_name`  varchar(150) NOT NULL DEFAULT '',
  `approver1_title` varchar(150) NOT NULL DEFAULT '',
  `approver2_name`  varchar(150) NOT NULL DEFAULT '',
  `approver2_title` varchar(150) NOT NULL DEFAULT '',
  `noted_name`      varchar(150) NOT NULL DEFAULT '',
  `noted_title`     varchar(150) NOT NULL DEFAULT '',
  `updated_at`      timestamp    NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `form_signatories`
  (`id`, `approver1_name`, `approver1_title`, `approver2_name`, `approver2_title`, `noted_name`, `noted_title`)
VALUES
  (1, 'DR. RODRIGO M. DANTAY JR.', 'Vice President, Office of the Student Affairs and Services',
      'ENGR. WENALD H. LOPEZ', 'Vice President for Academic Affairs',
      'DR. ELOISA P. MACKAY', 'Vice President for Administration');
