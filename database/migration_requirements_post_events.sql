-- =========================================================
--  OSAS — Migration: Requirement Files + Post-Event Reports
--
--  Run this ONLY if you already have an `osas_db` with data in it
--  that you don't want to lose. If you're starting fresh, just
--  re-import `database/osas_db.sql` instead — it already contains
--  everything below.
--
--  phpMyAdmin → osas_db → Import → choose this file → Go.
-- =========================================================
USE osas_db;

-- ---------------------------------------------------------
-- reservation_attachments — the requirement files/pictures a
-- student must upload before a reservation can be submitted
-- (letter of request, approved concept paper, venue layout,
-- poster/photo mockups, etc.).
--
-- The actual bytes live on disk in public/uploads/requirements/
-- under a random `stored_name`; only metadata is kept here.
-- Files are streamed back through a PHP endpoint that checks
-- the session first, so nobody can guess a URL and read
-- another student's documents.
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS reservation_attachments (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  reservation_id  INT NOT NULL,
  kind            ENUM('image','document') NOT NULL DEFAULT 'document',
  label           VARCHAR(120) NOT NULL DEFAULT '',
  original_name   VARCHAR(255) NOT NULL,
  stored_name     VARCHAR(255) NOT NULL,
  mime_type       VARCHAR(120) NOT NULL,
  size_bytes      INT UNSIGNED NOT NULL DEFAULT 0,
  uploaded_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_att_reservation (reservation_id),
  CONSTRAINT fk_att_reservation FOREIGN KEY (reservation_id)
    REFERENCES reservations(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- post_event_reports — one per finished reservation. Submitted by
-- the STUDENT once their reservation is Done, then reviewed by a
-- Vice Admin, who approves it or sends it back with remarks.
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS post_event_reports (
  id                  INT AUTO_INCREMENT PRIMARY KEY,
  report_no           VARCHAR(30) NOT NULL UNIQUE,
  reservation_id      INT NOT NULL UNIQUE,
  attendees_count     INT NOT NULL DEFAULT 0,
  summary             TEXT NOT NULL,
  issues              TEXT NULL,
  recommendations     TEXT NULL,
  resources_condition ENUM('all_returned','partially_returned','damaged','not_applicable')
                      NOT NULL DEFAULT 'all_returned',
  venue_condition     ENUM('good','minor_damage','major_damage') NOT NULL DEFAULT 'good',
  prepared_by         VARCHAR(150) NOT NULL,
  review_status       ENUM('pending','approved','needs_revision') NOT NULL DEFAULT 'pending',
  review_remarks      TEXT NULL,
  reviewed_by         INT NULL,
  reviewed_at         TIMESTAMP NULL,
  created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_per_created (created_at),
  INDEX idx_per_review (review_status),
  CONSTRAINT fk_per_reservation FOREIGN KEY (reservation_id)
    REFERENCES reservations(id) ON DELETE CASCADE,
  CONSTRAINT fk_per_reviewer FOREIGN KEY (reviewed_by)
    REFERENCES admins(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- post_event_photos — documentation pictures attached to a
-- post-event report. Stored in public/uploads/post-events/.
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS post_event_photos (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  report_id      INT NOT NULL,
  caption        VARCHAR(160) NOT NULL DEFAULT '',
  original_name  VARCHAR(255) NOT NULL,
  stored_name    VARCHAR(255) NOT NULL,
  mime_type      VARCHAR(120) NOT NULL,
  size_bytes     INT UNSIGNED NOT NULL DEFAULT 0,
  uploaded_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_pep_report (report_id),
  CONSTRAINT fk_pep_report FOREIGN KEY (report_id)
    REFERENCES post_event_reports(id) ON DELETE CASCADE
) ENGINE=InnoDB;
