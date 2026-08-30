-- ============================================================
-- migrations/029_drop_filika_from_templates.sql
-- Remove the word "φιλικά" from reminder templates in-DB.
-- User feedback: reads a touch condescending.
-- Idempotent — REPLACE is a no-op if the substring is already gone.
-- ============================================================

UPDATE reminder_rules
   SET body_tpl = REPLACE(body_tpl, 'Θα θέλαμε φιλικά να σας', 'Θα θέλαμε να σας')
 WHERE body_tpl LIKE '%Θα θέλαμε φιλικά να σας%';

UPDATE reminder_rules
   SET body_tpl = REPLACE(body_tpl, 'θέλαμε φιλικά να', 'θέλαμε να')
 WHERE body_tpl LIKE '%θέλαμε φιλικά να%';
