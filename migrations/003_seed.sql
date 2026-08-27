-- ============================================================
-- migrations/003_seed.sql — Idempotent seed data
-- ============================================================
-- Fills in the rows the app can't run without: at least one active
-- plan for register.php, and default system_settings for the admin
-- panel.  INSERT IGNORE + WHERE-NOT-EXISTS keeps it safe on re-run.
-- ============================================================

-- ── Plans ────────────────────────────────────────────────
INSERT INTO plans (name, slug, price_monthly, price_annual, max_athletes,
                   sms_enabled, email_enabled, competitions_enabled,
                   economics_enabled, reports_enabled, elot_export, active)
SELECT 'Basic', 'basic', 0, 0, 60, 0, 1, 0, 0, 0, 0, 1
WHERE NOT EXISTS (SELECT 1 FROM plans WHERE slug = 'basic');

INSERT INTO plans (name, slug, price_monthly, price_annual, max_athletes,
                   sms_enabled, email_enabled, competitions_enabled,
                   economics_enabled, reports_enabled, elot_export, active)
SELECT 'Pro', 'pro', 25, 240, 9999, 1, 1, 1, 1, 1, 1, 1
WHERE NOT EXISTS (SELECT 1 FROM plans WHERE slug = 'pro');

-- ── System settings essentials ───────────────────────────
INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES
  ('trial_days',            '14'),
  ('maintenance_mode',      '0'),
  ('mail_from_name',        'MAster'),
  ('bank_reference_hint',   'MASTER-{SCHOOL_NAME}'),
  ('summer_pause_enabled',  '0'),
  ('summer_pause_month',    '7'),
  ('summer_pause_end_month','8');
