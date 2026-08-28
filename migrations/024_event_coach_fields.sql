-- ================================================================
-- 024_event_coach_fields.sql
-- Adds coach declaration fields (name + phone) to event_registrations
-- so participant schools can tell the organiser which coach is
-- travelling with the team. One-per-athlete-row for simplicity —
-- the organiser view groups by school + coach.
--
-- Idempotent — the runner ignores "Duplicate column name" errors.
-- ================================================================

ALTER TABLE `event_registrations`
  ADD COLUMN `coach_name`  VARCHAR(160) NULL AFTER `coach_user_id`,
  ADD COLUMN `coach_phone` VARCHAR(40)  NULL AFTER `coach_name`;
