-- ================================================================
-- 020_reminder_logs_has_debt.sql
-- The `reminder_logs.trigger_type` ENUM is missing 'has_debt', so the
-- nightly cron gets a MySQL warning:
--   Data truncated for column 'trigger_type' at row 1
-- and the row is silently rejected. Extend the enum to include every
-- value the notification_rules table can carry.
-- Idempotent — ALTER MODIFY of an already-updated ENUM is a no-op.
-- ================================================================

ALTER TABLE `reminder_logs`
  MODIFY `trigger_type` ENUM(
    '3days_before',
    'days_before',
    'on_expiry',
    'on_due',
    '5days_after',
    'days_after',
    'after_payment',
    'has_debt',
    'manual'
  ) DEFAULT 'manual';
