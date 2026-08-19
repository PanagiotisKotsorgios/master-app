-- ============================================================
-- migrations/007_event_creation_fee.sql
-- Optional event-creation fee (€50-style) that the organiser
-- pays BEFORE the event can be opened for public registration.
--
-- Non-breaking: all NEW columns default to a state that means
-- "no fee required" (waived / 0), and the system setting
-- 'event_creation_fee_default' defaults to '0'. To turn the
-- feature on, admin sets a non-zero default (e.g. '50') under
-- /admin/system-settings.php.
-- ============================================================

ALTER TABLE events
  ADD COLUMN creation_fee_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00
             AFTER max_participants;

ALTER TABLE events
  ADD COLUMN creation_fee_status ENUM('waived','unpaid','proof_uploaded','verified')
             NOT NULL DEFAULT 'waived'
             AFTER creation_fee_amount;

ALTER TABLE events
  ADD INDEX idx_creation_fee (creation_fee_status);

-- Mark existing event_payments as 'participation' (their existing
-- semantic). The new column lets us disambiguate.
ALTER TABLE event_payments
  ADD COLUMN purpose ENUM('participation','creation') NOT NULL DEFAULT 'participation'
             AFTER paying_school_id;

INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES
  ('event_creation_fee_default', '0'),
  ('event_creation_fee_currency', 'EUR');
