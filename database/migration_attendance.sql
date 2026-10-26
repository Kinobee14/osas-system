-- =========================================================
--  OSAS — Migration: QR attendance (sessions + records)
--
--  Run this ONCE on your existing `osas_db`. It only ADDS two
--  tables; nothing is dropped, altered or deleted. Safe to run
--  more than once (MariaDB 10.3+ / XAMPP).
--
--  phpMyAdmin → osas_db → Import → choose this file → Go.
--
--  Column types match the live schema: reservations.id and
--  admins.id are signed INT(11).
-- =========================================================
USE osas_db;

-- 1. One attendance session per reservation. The token is what the QR code
--    carries (64 hex chars = 256 random bits); the raw reservation id is never
--    the secret.
CREATE TABLE IF NOT EXISTS attendance_sessions (
  id             INT(11) NOT NULL AUTO_INCREMENT,
  reservation_id INT(11) NOT NULL,
  token          CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  is_open        TINYINT(1) NOT NULL DEFAULT 0,
  opened_at      DATETIME NULL DEFAULT NULL,
  closed_at      DATETIME NULL DEFAULT NULL,
  created_by     INT(11) NULL DEFAULT NULL,
  created_at     DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_att_session_reservation (reservation_id),
  UNIQUE KEY uq_att_session_token (token),
  KEY fk_att_session_admin (created_by),
  CONSTRAINT fk_att_session_reservation FOREIGN KEY (reservation_id) REFERENCES reservations (id) ON DELETE CASCADE,
  CONSTRAINT fk_att_session_admin       FOREIGN KEY (created_by)     REFERENCES admins (id)       ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. One row per student who timed in. `student_id` holds the NORMALISED id
--    (upper-case, no spaces), and the unique key makes a second time-in for the
--    same student + session impossible even under a double-tap race.
--    Times are stored as Asia/Manila wall-clock (see shared/events.php app_now()).
CREATE TABLE IF NOT EXISTS attendance_records (
  id         INT(11) NOT NULL AUTO_INCREMENT,
  session_id INT(11) NOT NULL,
  student_id VARCHAR(30)  NOT NULL,
  full_name  VARCHAR(100) NOT NULL,
  time_in    DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_att_record_student (session_id, student_id),
  KEY idx_att_record_time (session_id, time_in),
  CONSTRAINT fk_att_record_session FOREIGN KEY (session_id) REFERENCES attendance_sessions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
