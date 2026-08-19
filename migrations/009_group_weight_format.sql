-- ============================================================
-- migrations/009_group_weight_format.sql
-- Adds a new bracket format 'group_weight' — automatic grouping
-- of registered athletes by their nearest current weight, into
-- pools of size = event_categories.pool_size (±weight_margin_kg
-- tolerance). Useful for small events without fixed weight
-- categories (like tkd_book's GROUP_BASED mode).
--
-- Non-breaking: existing categories keep their current format.
-- ============================================================

ALTER TABLE event_categories
  MODIFY COLUMN format
    ENUM('single_elim','double_elim','round_robin','pool_ko','pool_only','exhibition','group_weight')
    NOT NULL DEFAULT 'single_elim';

ALTER TABLE event_categories
  ADD COLUMN weight_margin_kg DECIMAL(4,2) NOT NULL DEFAULT 0.00 AFTER pool_size;
