-- ================================================================
-- 025_demo_guest_owners.sql
-- Creates owner login accounts for the 4 guest test schools seeded
-- in 021 so the operator can log in as a "participating club" and
-- see the event flow from the OTHER side (registrations, coach
-- declaration, pay_start, pay_proof, payer note, etc.) — not just
-- the organiser view.
--
-- All four accounts share the same password: master-demo
--   (bcrypt hash reused from the 014 demo owner)
--
-- Emails come strictly from the operator-supplied safe pool so
-- password-recovery flows can never spam a real third party.
--
-- Idempotent — NOT EXISTS guards each INSERT.
-- ================================================================

INSERT INTO users (school_id, name, email, password, role, active, created_at)
SELECT s.id, 'Test Owner — Athens', 'opengplms@gmail.com',
       '$2y$10$5zL/uydtqqSBz2pv.6Y5.Oc..OG8Mxpd8/5uGo4iCB1fJPgwsyhqO',
       'owner', 1, NOW()
  FROM schools s
 WHERE s.name = 'Α.Σ. Test Athens'
   AND NOT EXISTS (SELECT 1 FROM users WHERE email='opengplms@gmail.com' AND school_id=s.id);

INSERT INTO users (school_id, name, email, password, role, active, created_at)
SELECT s.id, 'Test Owner — Larisa', 'nolifeprogrammer1@gmail.com',
       '$2y$10$5zL/uydtqqSBz2pv.6Y5.Oc..OG8Mxpd8/5uGo4iCB1fJPgwsyhqO',
       'owner', 1, NOW()
  FROM schools s
 WHERE s.name = 'ΓΣ Test Larisas'
   AND NOT EXISTS (SELECT 1 FROM users WHERE email='nolifeprogrammer1@gmail.com' AND school_id=s.id);

INSERT INTO users (school_id, name, email, password, role, active, created_at)
SELECT s.id, 'Test Owner — Thessaloniki', 'info@mykalypsis.gr',
       '$2y$10$5zL/uydtqqSBz2pv.6Y5.Oc..OG8Mxpd8/5uGo4iCB1fJPgwsyhqO',
       'owner', 1, NOW()
  FROM schools s
 WHERE s.name = 'ΑΟ Test Thessalonikis'
   AND NOT EXISTS (SELECT 1 FROM users WHERE email='info@mykalypsis.gr' AND school_id=s.id);

INSERT INTO users (school_id, name, email, password, role, active, created_at)
SELECT s.id, 'Test Owner — Patras', 'support@timologion.gr',
       '$2y$10$5zL/uydtqqSBz2pv.6Y5.Oc..OG8Mxpd8/5uGo4iCB1fJPgwsyhqO',
       'owner', 1, NOW()
  FROM schools s
 WHERE s.name = 'Σ.Α. Test Patras'
   AND NOT EXISTS (SELECT 1 FROM users WHERE email='support@timologion.gr' AND school_id=s.id);
