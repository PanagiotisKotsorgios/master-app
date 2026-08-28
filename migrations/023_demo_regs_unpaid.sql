-- ================================================================
-- 023_demo_regs_unpaid.sql
-- The demo championship in 021/022 seeded every registration as
-- payment_status='verified'. That defeats the whole point of the
-- payments workflow — the organiser should see pending payments
-- from every registered club until they actually collect money.
--
-- Flip them all back to 'unpaid' (only for THIS demo event, only
-- for rows that still show the seeded 'verified' state). Real /
-- future paid registrations are untouched.
--
-- Idempotent — the UPDATE narrows on the demo slug + amount + the
-- exact previous state. Re-running the migration after the first
-- successful pass is a no-op.
-- ================================================================

SET @ev_full = (SELECT id FROM events WHERE slug='demo-panellinio-tkd-2026-full' LIMIT 1);

UPDATE event_registrations
   SET payment_status = 'unpaid',
       paid_at        = NULL,
       verified_at    = NULL,
       verified_by    = NULL
 WHERE @ev_full IS NOT NULL
   AND event_id       = @ev_full
   AND payment_status = 'verified'
   AND amount         = 15.00;   -- guard: only the seed rows, in case
                                  -- the organiser manually confirmed
                                  -- one at a different amount already.
