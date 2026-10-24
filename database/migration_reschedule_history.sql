-- =========================================================
--  OSAS — Migration: Double-booking protection, Reschedule
--  Requests, Schedule History and Cancelled reservations
--
--  Run this ONCE on your existing `osas_db` (it keeps all of
--  your current data — nothing is dropped or deleted).
--  Safe to run more than once (MariaDB 10.3+ / XAMPP).
--
--  phpMyAdmin → osas_db → Import → choose this file → Go.
-- =========================================================
USE osas_db;

-- 1. Reservations can now be 'cancelled' (kept as a record, never deleted).
ALTER TABLE reservations
  MODIFY status ENUM('pending','approved','declined','completed','cancelled') NOT NULL DEFAULT 'pending';

ALTER TABLE reservations
  ADD COLUMN IF NOT EXISTS cancelled_at  DATETIME     NULL DEFAULT NULL AFTER decline_reason,
  ADD COLUMN IF NOT EXISTS cancel_reason VARCHAR(500) NULL DEFAULT NULL AFTER cancelled_at;

-- 2. Faster overlap lookups for conflict detection.
ALTER TABLE reservations
  ADD INDEX IF NOT EXISTS idx_res_slot (venue_id, event_date, start_time, end_time);

-- 3. Reschedule requests (student asks → admin approves / rejects / suggests).
CREATE TABLE IF NOT EXISTS reservation_reschedule_requests (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  reservation_id   INT NOT NULL,
  student_id       INT NULL,
  venue_id         INT NULL,
  -- the schedule the reservation had when the request was made
  orig_date        DATE NOT NULL,
  orig_start       TIME NOT NULL,
  orig_end         TIME NOT NULL,
  -- what the student is asking for
  new_date         DATE NOT NULL,
  new_start        TIME NOT NULL,
  new_end          TIME NOT NULL,
  reason           TEXT NOT NULL,
  status           ENUM('pending','approved','rejected','suggested','withdrawn') NOT NULL DEFAULT 'pending',
  admin_id         INT NULL,
  admin_note       VARCHAR(500) NULL,
  -- filled when the admin answers "how about this time instead?"
  suggested_date   DATE NULL,
  suggested_start  TIME NULL,
  suggested_end    TIME NULL,
  responded_at     DATETIME NULL,
  created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_rrr_reservation (reservation_id),
  INDEX idx_rrr_status (status),
  CONSTRAINT fk_rrr_reservation FOREIGN KEY (reservation_id) REFERENCES reservations(id) ON DELETE CASCADE,
  CONSTRAINT fk_rrr_student     FOREIGN KEY (student_id)     REFERENCES users(id)        ON DELETE SET NULL,
  CONSTRAINT fk_rrr_admin       FOREIGN KEY (admin_id)       REFERENCES admins(id)       ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Schedule history: one row every time an approved reschedule moves a
--    reservation. The ORIGINAL schedule always stays here.
CREATE TABLE IF NOT EXISTS reservation_schedule_history (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  reservation_id   INT NOT NULL,
  request_id       INT NULL,
  venue_name       VARCHAR(150) NOT NULL,
  old_date         DATE NOT NULL,
  old_start        TIME NOT NULL,
  old_end          TIME NOT NULL,
  new_date         DATE NOT NULL,
  new_start        TIME NOT NULL,
  new_end          TIME NOT NULL,
  reason           TEXT NULL,
  changed_by_admin INT NULL,
  changed_at       DATETIME NOT NULL,
  INDEX idx_rsh_reservation (reservation_id),
  CONSTRAINT fk_rsh_reservation FOREIGN KEY (reservation_id) REFERENCES reservations(id) ON DELETE CASCADE,
  CONSTRAINT fk_rsh_request     FOREIGN KEY (request_id)     REFERENCES reservation_reschedule_requests(id) ON DELETE SET NULL,
  CONSTRAINT fk_rsh_admin       FOREIGN KEY (changed_by_admin) REFERENCES admins(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
