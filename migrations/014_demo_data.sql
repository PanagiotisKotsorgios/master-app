-- ============================================================
-- migrations/014_demo_data.sql
-- Seeds a permanent "Demo Σύλλογος" account so the 1-click demo
-- login on the landing page always lands on a populated dashboard.
--
-- Idempotent — nothing is inserted if the demo school already
-- exists. Password hash below is bcrypt of "master-demo" (used
-- only server-side; the demo endpoint sessions the user in
-- without asking for a password).
-- ============================================================

INSERT INTO schools (name, address, city, phone, email, plan_id, plan_status, active, created_at)
SELECT 'Demo Σύλλογος MAster', 'Οδός Επίδειξης 1', 'Αθήνα', '+30 210 0000000',
       'demo@master-app.gr',
       (SELECT id FROM plans WHERE slug='pro' LIMIT 1),
       'active', 1, NOW()
WHERE NOT EXISTS (SELECT 1 FROM schools WHERE email='demo@master-app.gr');

INSERT INTO users (school_id, name, email, password, role, active, created_at)
SELECT (SELECT id FROM schools WHERE email='demo@master-app.gr' LIMIT 1),
       'Δημήτρης Δοκιμαστής',
       'demo@master-app.gr',
       '$2y$10$D2u3JqGqk2P9c8jvKf3.LO7c5o8kFjXK8Z4T6iN1r7Y2lQ3v9wS8O',
       'owner', 1, NOW()
WHERE NOT EXISTS (SELECT 1 FROM users WHERE email='demo@master-app.gr');

-- Sample athletes (only inserted if this school has none)
INSERT INTO athletes (school_id, full_name, birthdate, gender, belt, active, created_at)
SELECT s.id, name, bd, g, belt, 1, NOW() FROM (
  SELECT 'Γιώργος Παπαδόπουλος' AS name, '2010-04-12' AS bd, 'M' AS g, 'μπλε' AS belt UNION ALL
  SELECT 'Ελένη Κωνσταντίνου',        '2012-07-25', 'F', 'πράσινη'  UNION ALL
  SELECT 'Νίκος Αντωνίου',             '2008-11-03', 'M', 'καφέ'     UNION ALL
  SELECT 'Μαρία Δημητρίου',            '2014-02-18', 'F', 'κίτρινη'  UNION ALL
  SELECT 'Στέφανος Γεωργίου',          '2009-09-30', 'M', 'μαύρη 1st DAN'
) demo_ath
CROSS JOIN (SELECT id FROM schools WHERE email='demo@master-app.gr' LIMIT 1) s
WHERE NOT EXISTS (
  SELECT 1 FROM athletes WHERE school_id=(SELECT id FROM schools WHERE email='demo@master-app.gr' LIMIT 1) LIMIT 1
);
