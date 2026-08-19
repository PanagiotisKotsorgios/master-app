-- ============================================================
-- migrations/004_event_invoices.sql
-- Adds invoice upload columns to event_payments.
--
-- Flow:
--   Admin verifies a payment (status='verified')
--     → Admin uploads an invoice PDF via /admin/event_invoices.php
--         → invoice_file_path / invoice_uploaded_at / invoice_uploaded_by populated
--     → Club sees + downloads the invoice via /pages/event_invoices.php
--
-- Idempotent: the runner skips "Duplicate column name" errors on re-run.
-- ============================================================

ALTER TABLE event_payments
  ADD COLUMN invoice_file_path VARCHAR(300) NULL AFTER verification_notes;

ALTER TABLE event_payments
  ADD COLUMN invoice_uploaded_at DATETIME NULL AFTER invoice_file_path;

ALTER TABLE event_payments
  ADD COLUMN invoice_uploaded_by INT NULL AFTER invoice_uploaded_at;

ALTER TABLE event_payments
  ADD INDEX idx_invoice_pending (status, invoice_file_path);
