-- ================================================================
-- 015_pro_plan_price.sql
-- Updates the Pro plan price from €30/mo (€288/yr) to €25/mo (€240/yr).
-- Idempotent: safe to re-run.
-- ================================================================

UPDATE plans
SET price_monthly = 25.00,
    price_annual  = 240.00
WHERE slug = 'pro'
  AND price_monthly = 30.00;
