-- =========================================================
--  OSAS — Migration: add a Course column to attendance records
--
--  Run this ONCE after migration_attendance.sql. It only ADDS a
--  column; nothing is dropped or deleted. Rows already saved get
--  an empty course. Safe to run more than once (MariaDB 10.3+).
--
--  phpMyAdmin → osas_db → Import → choose this file → Go.
-- =========================================================
USE osas_db;

ALTER TABLE attendance_records
  ADD COLUMN IF NOT EXISTS course VARCHAR(50) NOT NULL DEFAULT '' AFTER full_name;
