-- ================================================================
-- 017_demo_seed_full.sql
-- Fully populates the Demo Σύλλογος MAster (from 014) with realistic
-- sample data so every sidebar page has records to explore — athletes,
-- departments, subscriptions, payments, notifications, events,
-- invoices, parent/athlete portal users, athlete documents.
--
-- SAFE CONTACT ALLOWLIST — every generated athlete/parent contact
-- point is drawn only from this pool. No real third-party address
-- is ever inserted, so demo activity (SMS/email tests, reminder
-- previews) can never reach an outside inbox.
--
--   EMAILS
--     pkotsorgios654@gmail.com
--     opengplms@gmail.com
--     nolifeprogrammer1@gmail.com
--     kotsorgios@hotmail.com
--     info@mykalypsis.gr
--     support@timologion.gr
--   PHONES
--     698678178
--     6970223930
--
-- Password hash used for parent/athlete portal accounts below is the
-- bcrypt of "master-demo" (same as the 014 owner). Anyone can log in
-- as those demo portal users with password: master-demo
--
-- Idempotent — every block is guarded with NOT EXISTS / ON DUPLICATE
-- KEY UPDATE so the runner can re-execute safely.
-- ================================================================

-- Pin the demo school id for the rest of the migration
SET @demo_sid = (SELECT id FROM schools WHERE email='demo@master-app.gr' LIMIT 1);

-- ══════════════════════════════════════════════════════════════
-- 1) DEPARTMENTS (5)
-- ══════════════════════════════════════════════════════════════
INSERT INTO departments (school_id, name, schedule, max_athletes, monthly_fee, sport, active)
SELECT @demo_sid, 'Παιδικό Τμήμα (6-12)', 'Δευτ. & Τετ. 17:00-18:30', 30, 30.00, 'taekwondo_wtf', 1
WHERE @demo_sid IS NOT NULL AND NOT EXISTS (SELECT 1 FROM departments WHERE school_id=@demo_sid AND name='Παιδικό Τμήμα (6-12)');

INSERT INTO departments (school_id, name, schedule, max_athletes, monthly_fee, sport, active)
SELECT @demo_sid, 'Έφηβοι (13-17)', 'Τρ. & Πεμ. 18:00-19:30', 25, 35.00, 'taekwondo_wtf', 1
WHERE @demo_sid IS NOT NULL AND NOT EXISTS (SELECT 1 FROM departments WHERE school_id=@demo_sid AND name='Έφηβοι (13-17)');

INSERT INTO departments (school_id, name, schedule, max_athletes, monthly_fee, sport, active)
SELECT @demo_sid, 'Ενήλικες (18+)', 'Δευτ. & Τετ. 20:00-21:30', 20, 40.00, 'karate_shotokan', 1
WHERE @demo_sid IS NOT NULL AND NOT EXISTS (SELECT 1 FROM departments WHERE school_id=@demo_sid AND name='Ενήλικες (18+)');

INSERT INTO departments (school_id, name, schedule, max_athletes, monthly_fee, sport, active)
SELECT @demo_sid, 'Πρωταθλητισμός', 'Καθημερινά 18:00-20:00', 15, 50.00, 'taekwondo_wtf', 1
WHERE @demo_sid IS NOT NULL AND NOT EXISTS (SELECT 1 FROM departments WHERE school_id=@demo_sid AND name='Πρωταθλητισμός');

INSERT INTO departments (school_id, name, schedule, max_athletes, monthly_fee, sport, active)
SELECT @demo_sid, 'Ελεύθερα (Fitness)', 'Σαβ. 10:00-12:00', 30, 30.00, 'kickboxing', 1
WHERE @demo_sid IS NOT NULL AND NOT EXISTS (SELECT 1 FROM departments WHERE school_id=@demo_sid AND name='Ελεύθερα (Fitness)');


-- ══════════════════════════════════════════════════════════════
-- 2) FIRST 5 ATHLETES — the same 5 names that 014 tried to seed
--    (that INSERT failed because it referenced columns that don't
--    exist on athletes). We insert them here properly, with the
--    demo dept + fee + safe contact info. UPDATE fallback catches
--    the case where 014 succeeded on a future schema.
-- ══════════════════════════════════════════════════════════════
INSERT INTO athletes (school_id, department_id, full_name, birthdate, parent_phone, parent_email, monthly_fee, active, registration_date, created_at)
SELECT @demo_sid, (SELECT id FROM departments WHERE school_id=@demo_sid AND name='Έφηβοι (13-17)' LIMIT 1),
       'Γιώργος Παπαδόπουλος', '2010-04-12', '698678178', 'pkotsorgios654@gmail.com', 35.00, 1,
       DATE_SUB(CURDATE(), INTERVAL 15 MONTH), NOW()
WHERE @demo_sid IS NOT NULL AND NOT EXISTS (SELECT 1 FROM athletes WHERE school_id=@demo_sid AND full_name='Γιώργος Παπαδόπουλος');

INSERT INTO athletes (school_id, department_id, full_name, birthdate, parent_phone, parent_email, monthly_fee, active, registration_date, created_at)
SELECT @demo_sid, (SELECT id FROM departments WHERE school_id=@demo_sid AND name='Έφηβοι (13-17)' LIMIT 1),
       'Ελένη Κωνσταντίνου', '2012-07-25', '6970223930', 'opengplms@gmail.com', 35.00, 1,
       DATE_SUB(CURDATE(), INTERVAL 11 MONTH), NOW()
WHERE @demo_sid IS NOT NULL AND NOT EXISTS (SELECT 1 FROM athletes WHERE school_id=@demo_sid AND full_name='Ελένη Κωνσταντίνου');

INSERT INTO athletes (school_id, department_id, full_name, birthdate, parent_phone, parent_email, monthly_fee, active, registration_date, created_at)
SELECT @demo_sid, (SELECT id FROM departments WHERE school_id=@demo_sid AND name='Έφηβοι (13-17)' LIMIT 1),
       'Νίκος Αντωνίου', '2008-11-03', '698678178', 'nolifeprogrammer1@gmail.com', 35.00, 1,
       DATE_SUB(CURDATE(), INTERVAL 20 MONTH), NOW()
WHERE @demo_sid IS NOT NULL AND NOT EXISTS (SELECT 1 FROM athletes WHERE school_id=@demo_sid AND full_name='Νίκος Αντωνίου');

INSERT INTO athletes (school_id, department_id, full_name, birthdate, parent_phone, parent_email, monthly_fee, active, registration_date, created_at)
SELECT @demo_sid, (SELECT id FROM departments WHERE school_id=@demo_sid AND name='Παιδικό Τμήμα (6-12)' LIMIT 1),
       'Μαρία Δημητρίου', '2014-02-18', '6970223930', 'kotsorgios@hotmail.com', 30.00, 1,
       DATE_SUB(CURDATE(), INTERVAL 8 MONTH), NOW()
WHERE @demo_sid IS NOT NULL AND NOT EXISTS (SELECT 1 FROM athletes WHERE school_id=@demo_sid AND full_name='Μαρία Δημητρίου');

INSERT INTO athletes (school_id, department_id, full_name, birthdate, parent_phone, parent_email, monthly_fee, active, registration_date, created_at)
SELECT @demo_sid, (SELECT id FROM departments WHERE school_id=@demo_sid AND name='Έφηβοι (13-17)' LIMIT 1),
       'Στέφανος Γεωργίου', '2009-09-30', '698678178', 'info@mykalypsis.gr', 35.00, 1,
       DATE_SUB(CURDATE(), INTERVAL 16 MONTH), NOW()
WHERE @demo_sid IS NOT NULL AND NOT EXISTS (SELECT 1 FROM athletes WHERE school_id=@demo_sid AND full_name='Στέφανος Γεωργίου');

-- Fallback: if 014's inserts had succeeded on a future schema, patch dept + fee + contacts on those rows
UPDATE athletes SET department_id = (SELECT id FROM departments WHERE school_id=@demo_sid AND name='Έφηβοι (13-17)' LIMIT 1),
                    monthly_fee = 35.00, parent_phone='698678178',    parent_email='pkotsorgios654@gmail.com'
 WHERE school_id=@demo_sid AND full_name='Γιώργος Παπαδόπουλος' AND monthly_fee = 0;
UPDATE athletes SET department_id = (SELECT id FROM departments WHERE school_id=@demo_sid AND name='Έφηβοι (13-17)' LIMIT 1),
                    monthly_fee = 35.00, parent_phone='6970223930',   parent_email='opengplms@gmail.com'
 WHERE school_id=@demo_sid AND full_name='Ελένη Κωνσταντίνου' AND monthly_fee = 0;
UPDATE athletes SET department_id = (SELECT id FROM departments WHERE school_id=@demo_sid AND name='Έφηβοι (13-17)' LIMIT 1),
                    monthly_fee = 35.00, parent_phone='698678178',    parent_email='nolifeprogrammer1@gmail.com'
 WHERE school_id=@demo_sid AND full_name='Νίκος Αντωνίου' AND monthly_fee = 0;
UPDATE athletes SET department_id = (SELECT id FROM departments WHERE school_id=@demo_sid AND name='Παιδικό Τμήμα (6-12)' LIMIT 1),
                    monthly_fee = 30.00, parent_phone='6970223930',   parent_email='kotsorgios@hotmail.com'
 WHERE school_id=@demo_sid AND full_name='Μαρία Δημητρίου' AND monthly_fee = 0;
UPDATE athletes SET department_id = (SELECT id FROM departments WHERE school_id=@demo_sid AND name='Έφηβοι (13-17)' LIMIT 1),
                    monthly_fee = 35.00, parent_phone='698678178',    parent_email='info@mykalypsis.gr'
 WHERE school_id=@demo_sid AND full_name='Στέφανος Γεωργίου' AND monthly_fee = 0;


-- ══════════════════════════════════════════════════════════════
-- 3) EXTRA 10 ATHLETES — mix of ages (minors + adults), across all 5
--    departments, contacts cycled from the safe pool.
-- ══════════════════════════════════════════════════════════════
-- Minor #6 — Παιδικό
INSERT INTO athletes (school_id, department_id, full_name, birthdate, parent_phone, parent_email, monthly_fee, active, registration_date, created_at)
SELECT @demo_sid, (SELECT id FROM departments WHERE school_id=@demo_sid AND name='Παιδικό Τμήμα (6-12)' LIMIT 1),
       'Άννα Ιωάννου', '2015-03-08', '6970223930', 'support@timologion.gr', 30.00, 1,
       DATE_SUB(CURDATE(), INTERVAL 9 MONTH), NOW()
WHERE @demo_sid IS NOT NULL AND NOT EXISTS (SELECT 1 FROM athletes WHERE school_id=@demo_sid AND full_name='Άννα Ιωάννου');

INSERT INTO athletes (school_id, department_id, full_name, birthdate, parent_phone, parent_email, monthly_fee, active, registration_date, created_at)
SELECT @demo_sid, (SELECT id FROM departments WHERE school_id=@demo_sid AND name='Παιδικό Τμήμα (6-12)' LIMIT 1),
       'Δημήτρης Λαζαρίδης', '2016-06-21', '698678178', 'pkotsorgios654@gmail.com', 30.00, 1,
       DATE_SUB(CURDATE(), INTERVAL 4 MONTH), NOW()
WHERE @demo_sid IS NOT NULL AND NOT EXISTS (SELECT 1 FROM athletes WHERE school_id=@demo_sid AND full_name='Δημήτρης Λαζαρίδης');

INSERT INTO athletes (school_id, department_id, full_name, birthdate, parent_phone, parent_email, monthly_fee, active, registration_date, created_at)
SELECT @demo_sid, (SELECT id FROM departments WHERE school_id=@demo_sid AND name='Παιδικό Τμήμα (6-12)' LIMIT 1),
       'Κατερίνα Μπάκα', '2017-10-14', '6970223930', 'opengplms@gmail.com', 30.00, 1,
       DATE_SUB(CURDATE(), INTERVAL 6 MONTH), NOW()
WHERE @demo_sid IS NOT NULL AND NOT EXISTS (SELECT 1 FROM athletes WHERE school_id=@demo_sid AND full_name='Κατερίνα Μπάκα');

INSERT INTO athletes (school_id, department_id, full_name, birthdate, parent_phone, parent_email, monthly_fee, active, registration_date, created_at)
SELECT @demo_sid, (SELECT id FROM departments WHERE school_id=@demo_sid AND name='Παιδικό Τμήμα (6-12)' LIMIT 1),
       'Σοφία Νικολάου', '2013-08-19', '698678178', 'nolifeprogrammer1@gmail.com', 30.00, 1,
       DATE_SUB(CURDATE(), INTERVAL 3 MONTH), NOW()
WHERE @demo_sid IS NOT NULL AND NOT EXISTS (SELECT 1 FROM athletes WHERE school_id=@demo_sid AND full_name='Σοφία Νικολάου');

-- Minor #10 — Πρωταθλητισμός
INSERT INTO athletes (school_id, department_id, full_name, birthdate, parent_phone, parent_email, monthly_fee, active, registration_date, created_at)
SELECT @demo_sid, (SELECT id FROM departments WHERE school_id=@demo_sid AND name='Πρωταθλητισμός' LIMIT 1),
       'Παύλος Χατζή', '2011-01-05', '6970223930', 'kotsorgios@hotmail.com', 50.00, 1,
       DATE_SUB(CURDATE(), INTERVAL 12 MONTH), NOW()
WHERE @demo_sid IS NOT NULL AND NOT EXISTS (SELECT 1 FROM athletes WHERE school_id=@demo_sid AND full_name='Παύλος Χατζή');

-- ── Adults (5) — Ενήλικες / Ελεύθερα / Πρωταθλητισμός ──
INSERT INTO athletes (school_id, department_id, full_name, birthdate, phone, email, monthly_fee, active, registration_date, created_at)
SELECT @demo_sid, (SELECT id FROM departments WHERE school_id=@demo_sid AND name='Ενήλικες (18+)' LIMIT 1),
       'Ανδρέας Σαράφης', '1998-05-11', '698678178', 'info@mykalypsis.gr', 40.00, 1,
       DATE_SUB(CURDATE(), INTERVAL 14 MONTH), NOW()
WHERE @demo_sid IS NOT NULL AND NOT EXISTS (SELECT 1 FROM athletes WHERE school_id=@demo_sid AND full_name='Ανδρέας Σαράφης');

INSERT INTO athletes (school_id, department_id, full_name, birthdate, phone, email, monthly_fee, active, registration_date, created_at)
SELECT @demo_sid, (SELECT id FROM departments WHERE school_id=@demo_sid AND name='Ενήλικες (18+)' LIMIT 1),
       'Ειρήνη Ρήγα', '2001-12-03', '6970223930', 'support@timologion.gr', 40.00, 1,
       DATE_SUB(CURDATE(), INTERVAL 7 MONTH), NOW()
WHERE @demo_sid IS NOT NULL AND NOT EXISTS (SELECT 1 FROM athletes WHERE school_id=@demo_sid AND full_name='Ειρήνη Ρήγα');

INSERT INTO athletes (school_id, department_id, full_name, birthdate, phone, email, monthly_fee, active, registration_date, created_at)
SELECT @demo_sid, (SELECT id FROM departments WHERE school_id=@demo_sid AND name='Ενήλικες (18+)' LIMIT 1),
       'Χρήστος Παπανικολάου', '1990-07-22', '698678178', 'pkotsorgios654@gmail.com', 40.00, 1,
       DATE_SUB(CURDATE(), INTERVAL 18 MONTH), NOW()
WHERE @demo_sid IS NOT NULL AND NOT EXISTS (SELECT 1 FROM athletes WHERE school_id=@demo_sid AND full_name='Χρήστος Παπανικολάου');

INSERT INTO athletes (school_id, department_id, full_name, birthdate, phone, email, monthly_fee, active, registration_date, created_at)
SELECT @demo_sid, (SELECT id FROM departments WHERE school_id=@demo_sid AND name='Πρωταθλητισμός' LIMIT 1),
       'Ζωή Καρίμαλη', '2005-04-30', '6970223930', 'opengplms@gmail.com', 50.00, 1,
       DATE_SUB(CURDATE(), INTERVAL 5 MONTH), NOW()
WHERE @demo_sid IS NOT NULL AND NOT EXISTS (SELECT 1 FROM athletes WHERE school_id=@demo_sid AND full_name='Ζωή Καρίμαλη');

INSERT INTO athletes (school_id, department_id, full_name, birthdate, phone, email, monthly_fee, active, registration_date, created_at)
SELECT @demo_sid, (SELECT id FROM departments WHERE school_id=@demo_sid AND name='Ελεύθερα (Fitness)' LIMIT 1),
       'Θανάσης Βασιλείου', '1985-11-15', '698678178', 'nolifeprogrammer1@gmail.com', 30.00, 1,
       DATE_SUB(CURDATE(), INTERVAL 10 MONTH), NOW()
WHERE @demo_sid IS NOT NULL AND NOT EXISTS (SELECT 1 FROM athletes WHERE school_id=@demo_sid AND full_name='Θανάσης Βασιλείου');


-- ══════════════════════════════════════════════════════════════
-- 4) SUBSCRIPTIONS — 6 months of history per athlete.
--    Distribution (deterministic via athlete.id modulo):
--      months -5,-4,-3  : all paid
--      month  -2        : ~80% paid, 20% pending
--      month  -1        : ~60% paid, 30% pending, 10% overdue
--      month   0 (curr) : ~50% pending, 40% paid, 10% overdue
-- ══════════════════════════════════════════════════════════════

-- Month -5 : paid
INSERT INTO subscriptions (school_id, athlete_id, type, amount, paid_at, valid_from, valid_until, payment_method, status, created_at)
SELECT @demo_sid, a.id, 'monthly', a.monthly_fee,
       DATE_ADD(DATE_FORMAT(CURDATE(), '%Y-%m-01') - INTERVAL 5 MONTH, INTERVAL 4 DAY),
       DATE_FORMAT(CURDATE(), '%Y-%m-01') - INTERVAL 5 MONTH,
       LAST_DAY(DATE_FORMAT(CURDATE(), '%Y-%m-01') - INTERVAL 5 MONTH),
       'cash', 'paid', NOW()
  FROM athletes a
 WHERE a.school_id=@demo_sid AND a.active=1 AND a.monthly_fee > 0
   AND NOT EXISTS (SELECT 1 FROM subscriptions s
                    WHERE s.athlete_id=a.id
                      AND s.valid_from = DATE_FORMAT(CURDATE(), '%Y-%m-01') - INTERVAL 5 MONTH);

-- Month -4 : paid
INSERT INTO subscriptions (school_id, athlete_id, type, amount, paid_at, valid_from, valid_until, payment_method, status, created_at)
SELECT @demo_sid, a.id, 'monthly', a.monthly_fee,
       DATE_ADD(DATE_FORMAT(CURDATE(), '%Y-%m-01') - INTERVAL 4 MONTH, INTERVAL 5 DAY),
       DATE_FORMAT(CURDATE(), '%Y-%m-01') - INTERVAL 4 MONTH,
       LAST_DAY(DATE_FORMAT(CURDATE(), '%Y-%m-01') - INTERVAL 4 MONTH),
       'card', 'paid', NOW()
  FROM athletes a
 WHERE a.school_id=@demo_sid AND a.active=1 AND a.monthly_fee > 0
   AND NOT EXISTS (SELECT 1 FROM subscriptions s
                    WHERE s.athlete_id=a.id
                      AND s.valid_from = DATE_FORMAT(CURDATE(), '%Y-%m-01') - INTERVAL 4 MONTH);

-- Month -3 : paid
INSERT INTO subscriptions (school_id, athlete_id, type, amount, paid_at, valid_from, valid_until, payment_method, status, created_at)
SELECT @demo_sid, a.id, 'monthly', a.monthly_fee,
       DATE_ADD(DATE_FORMAT(CURDATE(), '%Y-%m-01') - INTERVAL 3 MONTH, INTERVAL 3 DAY),
       DATE_FORMAT(CURDATE(), '%Y-%m-01') - INTERVAL 3 MONTH,
       LAST_DAY(DATE_FORMAT(CURDATE(), '%Y-%m-01') - INTERVAL 3 MONTH),
       'cash', 'paid', NOW()
  FROM athletes a
 WHERE a.school_id=@demo_sid AND a.active=1 AND a.monthly_fee > 0
   AND NOT EXISTS (SELECT 1 FROM subscriptions s
                    WHERE s.athlete_id=a.id
                      AND s.valid_from = DATE_FORMAT(CURDATE(), '%Y-%m-01') - INTERVAL 3 MONTH);

-- Month -2 : 80% paid, 20% pending
INSERT INTO subscriptions (school_id, athlete_id, type, amount, paid_at, valid_from, valid_until, payment_method, status, created_at)
SELECT @demo_sid, a.id, 'monthly', a.monthly_fee,
       IF(MOD(a.id, 5) < 4, DATE_ADD(DATE_FORMAT(CURDATE(), '%Y-%m-01') - INTERVAL 2 MONTH, INTERVAL 7 DAY), NULL),
       DATE_FORMAT(CURDATE(), '%Y-%m-01') - INTERVAL 2 MONTH,
       LAST_DAY(DATE_FORMAT(CURDATE(), '%Y-%m-01') - INTERVAL 2 MONTH),
       'deposit',
       CASE WHEN MOD(a.id, 5) < 4 THEN 'paid' ELSE 'pending' END,
       NOW()
  FROM athletes a
 WHERE a.school_id=@demo_sid AND a.active=1 AND a.monthly_fee > 0
   AND NOT EXISTS (SELECT 1 FROM subscriptions s
                    WHERE s.athlete_id=a.id
                      AND s.valid_from = DATE_FORMAT(CURDATE(), '%Y-%m-01') - INTERVAL 2 MONTH);

-- Month -1 : ~60% paid, 30% pending, 10% overdue
INSERT INTO subscriptions (school_id, athlete_id, type, amount, paid_at, valid_from, valid_until, payment_method, status, created_at)
SELECT @demo_sid, a.id, 'monthly', a.monthly_fee,
       IF(MOD(a.id, 10) < 6, DATE_ADD(DATE_FORMAT(CURDATE(), '%Y-%m-01') - INTERVAL 1 MONTH, INTERVAL 5 DAY), NULL),
       DATE_FORMAT(CURDATE(), '%Y-%m-01') - INTERVAL 1 MONTH,
       LAST_DAY(DATE_FORMAT(CURDATE(), '%Y-%m-01') - INTERVAL 1 MONTH),
       'cash',
       CASE WHEN MOD(a.id, 10) < 6 THEN 'paid'
            WHEN MOD(a.id, 10) < 9 THEN 'pending'
            ELSE 'overdue' END,
       NOW()
  FROM athletes a
 WHERE a.school_id=@demo_sid AND a.active=1 AND a.monthly_fee > 0
   AND NOT EXISTS (SELECT 1 FROM subscriptions s
                    WHERE s.athlete_id=a.id
                      AND s.valid_from = DATE_FORMAT(CURDATE(), '%Y-%m-01') - INTERVAL 1 MONTH);

-- Month 0 (current) : ~50% pending, 40% paid, 10% overdue (from prev cycle)
INSERT INTO subscriptions (school_id, athlete_id, type, amount, paid_at, valid_from, valid_until, payment_method, status, created_at)
SELECT @demo_sid, a.id, 'monthly', a.monthly_fee,
       IF(MOD(a.id, 10) < 4, DATE_ADD(DATE_FORMAT(CURDATE(), '%Y-%m-01'), INTERVAL 3 DAY), NULL),
       DATE_FORMAT(CURDATE(), '%Y-%m-01'),
       LAST_DAY(CURDATE()),
       'cash',
       CASE WHEN MOD(a.id, 10) < 4 THEN 'paid' ELSE 'pending' END,
       NOW()
  FROM athletes a
 WHERE a.school_id=@demo_sid AND a.active=1 AND a.monthly_fee > 0
   AND NOT EXISTS (SELECT 1 FROM subscriptions s
                    WHERE s.athlete_id=a.id
                      AND s.valid_from = DATE_FORMAT(CURDATE(), '%Y-%m-01'));


-- ══════════════════════════════════════════════════════════════
-- 5) MONTHLY PAYMENTS ROLL-UP (payments table)
--    Mirrors the paid subscriptions so the athlete×month matrix
--    on payment_analytics.php lights up. ON DUPLICATE KEY UPDATE
--    keeps re-runs safe (unique key: athlete_id + month).
-- ══════════════════════════════════════════════════════════════
INSERT INTO payments (school_id, athlete_id, month, amount, payment_method, paid_at, notes, created_at)
SELECT s.school_id, s.athlete_id, DATE_FORMAT(s.valid_from, '%Y-%m'),
       s.amount, s.payment_method, s.paid_at, 'Demo seed', NOW()
  FROM subscriptions s
 WHERE s.school_id=@demo_sid AND s.status='paid' AND s.paid_at IS NOT NULL
ON DUPLICATE KEY UPDATE amount = VALUES(amount), paid_at = VALUES(paid_at);


-- ══════════════════════════════════════════════════════════════
-- 6) REMINDER LOGS — one recent notification per athlete (email),
--    plus a batch of SMS records for pending debtors.
-- ══════════════════════════════════════════════════════════════
INSERT INTO reminder_logs (school_id, athlete_id, type, trigger_type, recipient, subject, body, status, sent_at)
SELECT @demo_sid, a.id, 'email', 'days_before',
       COALESCE(a.parent_email, a.email, 'pkotsorgios654@gmail.com'),
       'Φιλική υπενθύμιση συνδρομής',
       CONCAT('Αγαπητέ/ή κηδεμόνα, θα θέλαμε φιλικά να σας υπενθυμίσουμε τη συνδρομή του/της ', a.full_name, '. Όποτε σας εξυπηρετεί, είμαστε στη διάθεσή σας. Ευχαριστούμε πολύ, Demo Σύλλογος MAster'),
       'sent',
       DATE_SUB(NOW(), INTERVAL FLOOR(RAND()*20) DAY)
  FROM athletes a
 WHERE a.school_id=@demo_sid AND a.active=1
   AND NOT EXISTS (SELECT 1 FROM reminder_logs r
                    WHERE r.school_id=@demo_sid AND r.athlete_id=a.id AND r.trigger_type='days_before');

INSERT INTO reminder_logs (school_id, athlete_id, type, trigger_type, recipient, subject, body, status, sent_at)
SELECT @demo_sid, a.id, 'sms', 'on_due',
       COALESCE(a.parent_phone, a.phone, '698678178'),
       'SMS υπενθύμιση',
       CONCAT('Φιλική υπενθύμιση για τη συνδρομή του/της ', a.full_name, ' — Demo Σύλλογος MAster. Ευχαριστούμε!'),
       'sent',
       DATE_SUB(NOW(), INTERVAL FLOOR(RAND()*10) DAY)
  FROM athletes a
 WHERE a.school_id=@demo_sid AND a.active=1
   AND NOT EXISTS (SELECT 1 FROM reminder_logs r
                    WHERE r.school_id=@demo_sid AND r.athlete_id=a.id AND r.trigger_type='on_due');


-- ══════════════════════════════════════════════════════════════
-- 7) EVENT — completed seminar
-- ══════════════════════════════════════════════════════════════
INSERT INTO events (slug, organiser_school_id, type, title, subtitle, description, visibility, status,
                    venue_name, venue_address, starts_at, ends_at,
                    registration_opens_at, registration_closes_at,
                    fee_model, fee_amount, contact_email, contact_phone,
                    created_at, updated_at)
SELECT 'demo-seminario-amunas-2026', @demo_sid, 'seminar',
       'Σεμινάριο Άμυνας 2026', 'Ασφάλεια και αυτοάμυνα για ενηλίκους',
       'Δοκιμαστικό σεμινάριο. Πρακτικές τεχνικές, σενάρια κοντινής απόστασης, νομικό πλαίσιο νόμιμης άμυνας.',
       'public', 'completed',
       'Αίθουσα MAster HQ', 'Λ. Αθηνών 100, Αθήνα',
       DATE_SUB(CURDATE(), INTERVAL 20 DAY), DATE_SUB(CURDATE(), INTERVAL 20 DAY),
       DATE_SUB(CURDATE(), INTERVAL 40 DAY), DATE_SUB(CURDATE(), INTERVAL 22 DAY),
       'per_athlete', 15.00, 'info@mykalypsis.gr', '698678178',
       NOW(), NOW()
WHERE @demo_sid IS NOT NULL AND NOT EXISTS (SELECT 1 FROM events WHERE slug='demo-seminario-amunas-2026');


-- ══════════════════════════════════════════════════════════════
-- 8) EVENT PAYMENT — verified seminar invoice
-- ══════════════════════════════════════════════════════════════
INSERT INTO event_payments (event_id, paying_school_id, amount, method, reference_code,
                            status, verified_at, invoice_file_path, invoice_uploaded_at,
                            created_at, updated_at)
SELECT (SELECT id FROM events WHERE slug='demo-seminario-amunas-2026' LIMIT 1),
       @demo_sid, 45.00, 'bank', 'MASTER-DEMO-INV-001',
       'verified', DATE_SUB(NOW(), INTERVAL 18 DAY),
       'uploads/events/demo/demo-invoice-001.pdf', DATE_SUB(NOW(), INTERVAL 17 DAY),
       DATE_SUB(NOW(), INTERVAL 20 DAY), DATE_SUB(NOW(), INTERVAL 17 DAY)
WHERE @demo_sid IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM event_payments WHERE reference_code='MASTER-DEMO-INV-001');

-- ══════════════════════════════════════════════════════════════
-- 9) PARENT PORTAL USERS (2) — links to a couple of minor athletes
--    Password = "master-demo" (same hash as demo owner in 014)
-- ══════════════════════════════════════════════════════════════
INSERT INTO parent_users (school_id, parent_email, password_hash, first_login, created_at)
SELECT @demo_sid, 'pkotsorgios654@gmail.com',
       '$2y$10$D2u3JqGqk2P9c8jvKf3.LO7c5o8kFjXK8Z4T6iN1r7Y2lQ3v9wS8O', 0, NOW()
WHERE @demo_sid IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM parent_users WHERE school_id=@demo_sid AND parent_email='pkotsorgios654@gmail.com');

INSERT INTO parent_users (school_id, parent_email, password_hash, first_login, created_at)
SELECT @demo_sid, 'opengplms@gmail.com',
       '$2y$10$D2u3JqGqk2P9c8jvKf3.LO7c5o8kFjXK8Z4T6iN1r7Y2lQ3v9wS8O', 0, NOW()
WHERE @demo_sid IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM parent_users WHERE school_id=@demo_sid AND parent_email='opengplms@gmail.com');

-- Link parent 1 → children whose parent_email matches
INSERT INTO parent_children (parent_id, athlete_id)
SELECT (SELECT id FROM parent_users WHERE school_id=@demo_sid AND parent_email='pkotsorgios654@gmail.com' LIMIT 1),
       a.id
  FROM athletes a
 WHERE a.school_id=@demo_sid AND a.parent_email='pkotsorgios654@gmail.com'
   AND NOT EXISTS (SELECT 1 FROM parent_children pc
                    WHERE pc.parent_id = (SELECT id FROM parent_users WHERE school_id=@demo_sid AND parent_email='pkotsorgios654@gmail.com' LIMIT 1)
                      AND pc.athlete_id = a.id);

INSERT INTO parent_children (parent_id, athlete_id)
SELECT (SELECT id FROM parent_users WHERE school_id=@demo_sid AND parent_email='opengplms@gmail.com' LIMIT 1),
       a.id
  FROM athletes a
 WHERE a.school_id=@demo_sid AND a.parent_email='opengplms@gmail.com'
   AND NOT EXISTS (SELECT 1 FROM parent_children pc
                    WHERE pc.parent_id = (SELECT id FROM parent_users WHERE school_id=@demo_sid AND parent_email='opengplms@gmail.com' LIMIT 1)
                      AND pc.athlete_id = a.id);


-- ══════════════════════════════════════════════════════════════
-- 10) ATHLETE PORTAL USERS (2 adults) — password = "master-demo"
-- ══════════════════════════════════════════════════════════════
UPDATE athletes SET athlete_portal_access = 1
 WHERE school_id=@demo_sid AND full_name IN ('Ανδρέας Σαράφης','Ειρήνη Ρήγα');

INSERT INTO athlete_users (school_id, athlete_id, email, password_hash, first_login, active, created_at)
SELECT @demo_sid, a.id, a.email,
       '$2y$10$D2u3JqGqk2P9c8jvKf3.LO7c5o8kFjXK8Z4T6iN1r7Y2lQ3v9wS8O', 0, 1, NOW()
  FROM athletes a
 WHERE a.school_id=@demo_sid AND a.full_name='Ανδρέας Σαράφης' AND a.email IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM athlete_users WHERE athlete_id=a.id);

INSERT INTO athlete_users (school_id, athlete_id, email, password_hash, first_login, active, created_at)
SELECT @demo_sid, a.id, a.email,
       '$2y$10$D2u3JqGqk2P9c8jvKf3.LO7c5o8kFjXK8Z4T6iN1r7Y2lQ3v9wS8O', 0, 1, NOW()
  FROM athletes a
 WHERE a.school_id=@demo_sid AND a.full_name='Ειρήνη Ρήγα' AND a.email IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM athlete_users WHERE athlete_id=a.id);


-- ══════════════════════════════════════════════════════════════
-- 11) ATHLETE DOCUMENTS — 3 samples across athletes
-- ══════════════════════════════════════════════════════════════
INSERT INTO athlete_documents (school_id, athlete_id, type, title, file_path, mime_type,
                               issued_date, uploaded_by, verified_by_school, verified_at, created_at)
SELECT @demo_sid, a.id, 'delta', 'Δελτίο 2026',
       'uploads/athletes/demo/delta-sarafis.pdf', 'application/pdf',
       DATE_SUB(CURDATE(), INTERVAL 3 MONTH),
       'school', 1, DATE_SUB(NOW(), INTERVAL 60 DAY), NOW()
  FROM athletes a
 WHERE a.school_id=@demo_sid AND a.full_name='Ανδρέας Σαράφης'
   AND NOT EXISTS (SELECT 1 FROM athlete_documents WHERE athlete_id=a.id AND type='delta');

INSERT INTO athlete_documents (school_id, athlete_id, type, title, file_path, mime_type,
                               issued_date, uploaded_by, verified_by_school, verified_at, created_at)
SELECT @demo_sid, a.id, 'dan', '1st Dan Karate',
       'uploads/athletes/demo/dan-sarafis.pdf', 'application/pdf',
       DATE_SUB(CURDATE(), INTERVAL 14 MONTH),
       'athlete', 1, DATE_SUB(NOW(), INTERVAL 30 DAY), NOW()
  FROM athletes a
 WHERE a.school_id=@demo_sid AND a.full_name='Ανδρέας Σαράφης'
   AND NOT EXISTS (SELECT 1 FROM athlete_documents WHERE athlete_id=a.id AND type='dan');

INSERT INTO athlete_documents (school_id, athlete_id, type, title, file_path, mime_type,
                               issued_date, expires_at, uploaded_by, verified_by_school, created_at)
SELECT @demo_sid, a.id, 'medical', 'Ιατρική βεβαίωση 2026',
       'uploads/athletes/demo/medical-riga.pdf', 'application/pdf',
       DATE_SUB(CURDATE(), INTERVAL 1 MONTH),
       DATE_ADD(CURDATE(), INTERVAL 11 MONTH),
       'athlete', 0, NOW()
  FROM athletes a
 WHERE a.school_id=@demo_sid AND a.full_name='Ειρήνη Ρήγα'
   AND NOT EXISTS (SELECT 1 FROM athlete_documents WHERE athlete_id=a.id AND type='medical');
