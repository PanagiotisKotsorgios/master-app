-- ================================================================
-- 026_ensure_guest_participation.sql
-- Defensive re-seed: guarantees that the 4 guest test schools have
--  (a) an owner user account (from 025)
--  (b) 10 athletes each             (from 021/022)
--  (c) registered athletes in the closed demo championship
-- so that logging in with any of the four guest owner accounts
-- (opengplms@gmail.com etc) always yields visible participation.
--
-- Some environments landed 021-025 in a partial state — this pass
-- fills every gap without duplicating anything.
--
-- Password shared by all 4 accounts: master-demo
--
-- Idempotent throughout — NOT EXISTS / INSERT IGNORE guards.
-- ================================================================

SET @plan_pro = (SELECT id FROM plans WHERE slug='pro' LIMIT 1);
SET @ev_full  = (SELECT id FROM events WHERE slug='demo-panellinio-tkd-2026-full' LIMIT 1);

SET @cat_k55m = (SELECT id FROM event_categories WHERE event_id=@ev_full AND name='Kumite -55kg U18 Άνδρες'   LIMIT 1);
SET @cat_k60m = (SELECT id FROM event_categories WHERE event_id=@ev_full AND name='Kumite -60kg U18 Άνδρες'   LIMIT 1);
SET @cat_k50f = (SELECT id FROM event_categories WHERE event_id=@ev_full AND name='Kumite -50kg U18 Γυναίκες' LIMIT 1);
SET @cat_katm = (SELECT id FROM event_categories WHERE event_id=@ev_full AND name='Kata Individual Άνδρες'    LIMIT 1);
SET @cat_katf = (SELECT id FROM event_categories WHERE event_id=@ev_full AND name='Kata Individual Γυναίκες'  LIMIT 1);


-- ── 1) SCHOOLS — recreate if missing ──────────────────────────
INSERT INTO schools (name, email, phone, plan_id, plan_status, active, created_at)
SELECT 'Α.Σ. Test Athens', 'opengplms@gmail.com', '6970223930', @plan_pro, 'active', 1, NOW()
  FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM schools WHERE name='Α.Σ. Test Athens');

INSERT INTO schools (name, email, phone, plan_id, plan_status, active, created_at)
SELECT 'ΓΣ Test Larisas', 'nolifeprogrammer1@gmail.com', '698678178', @plan_pro, 'active', 1, NOW()
  FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM schools WHERE name='ΓΣ Test Larisas');

INSERT INTO schools (name, email, phone, plan_id, plan_status, active, created_at)
SELECT 'ΑΟ Test Thessalonikis', 'info@mykalypsis.gr', '6970223930', @plan_pro, 'active', 1, NOW()
  FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM schools WHERE name='ΑΟ Test Thessalonikis');

INSERT INTO schools (name, email, phone, plan_id, plan_status, active, created_at)
SELECT 'Σ.Α. Test Patras', 'support@timologion.gr', '698678178', @plan_pro, 'active', 1, NOW()
  FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM schools WHERE name='Σ.Α. Test Patras');


-- ── 2) OWNER USERS — recreate if missing (bcrypt of "master-demo") ──
INSERT INTO users (school_id, name, email, password, role, active, created_at)
SELECT s.id, 'Test Owner — Athens', 'opengplms@gmail.com',
       '$2y$10$D2u3JqGqk2P9c8jvKf3.LO7c5o8kFjXK8Z4T6iN1r7Y2lQ3v9wS8O',
       'owner', 1, NOW()
  FROM schools s
 WHERE s.name = 'Α.Σ. Test Athens'
   AND NOT EXISTS (SELECT 1 FROM users WHERE email='opengplms@gmail.com');

INSERT INTO users (school_id, name, email, password, role, active, created_at)
SELECT s.id, 'Test Owner — Larisa', 'nolifeprogrammer1@gmail.com',
       '$2y$10$D2u3JqGqk2P9c8jvKf3.LO7c5o8kFjXK8Z4T6iN1r7Y2lQ3v9wS8O',
       'owner', 1, NOW()
  FROM schools s
 WHERE s.name = 'ΓΣ Test Larisas'
   AND NOT EXISTS (SELECT 1 FROM users WHERE email='nolifeprogrammer1@gmail.com');

INSERT INTO users (school_id, name, email, password, role, active, created_at)
SELECT s.id, 'Test Owner — Thessaloniki', 'info@mykalypsis.gr',
       '$2y$10$D2u3JqGqk2P9c8jvKf3.LO7c5o8kFjXK8Z4T6iN1r7Y2lQ3v9wS8O',
       'owner', 1, NOW()
  FROM schools s
 WHERE s.name = 'ΑΟ Test Thessalonikis'
   AND NOT EXISTS (SELECT 1 FROM users WHERE email='info@mykalypsis.gr');

INSERT INTO users (school_id, name, email, password, role, active, created_at)
SELECT s.id, 'Test Owner — Patras', 'support@timologion.gr',
       '$2y$10$D2u3JqGqk2P9c8jvKf3.LO7c5o8kFjXK8Z4T6iN1r7Y2lQ3v9wS8O',
       'owner', 1, NOW()
  FROM schools s
 WHERE s.name = 'Σ.Α. Test Patras'
   AND NOT EXISTS (SELECT 1 FROM users WHERE email='support@timologion.gr');


-- ── 3) ATHLETES — 10 per guest school if not already present ──
INSERT INTO athletes (school_id, full_name, birthdate, parent_phone, parent_email, monthly_fee, active, created_at)
SELECT s.id, x.name, x.bd, '6970223930', 'opengplms@gmail.com', 0.00, 1, NOW()
  FROM (
    SELECT 'Κωνσταντίνος Αθανασίου' AS name, '2009-03-14' AS bd UNION ALL
    SELECT 'Νίκος Λέκκας',            '2010-07-22' UNION ALL
    SELECT 'Δημήτρης Καραγιάννης',    '2009-11-05' UNION ALL
    SELECT 'Παναγιώτης Στέργιου',     '2010-01-30' UNION ALL
    SELECT 'Χρήστος Ζαφείρης',        '2009-06-18' UNION ALL
    SELECT 'Ελένη Παπαδοπούλου',      '2010-08-12' UNION ALL
    SELECT 'Ιωάννα Λαμπροπούλου',     '2009-04-04' UNION ALL
    SELECT 'Μαρία Πετρίδη',           '2010-10-19' UNION ALL
    SELECT 'Σοφία Χατζηνικολάου',     '2009-02-25' UNION ALL
    SELECT 'Άννα Κοντογιάννη',        '2010-05-08'
  ) x
  JOIN schools s ON s.name='Α.Σ. Test Athens'
 WHERE NOT EXISTS (SELECT 1 FROM athletes WHERE school_id=s.id AND full_name=x.name);

INSERT INTO athletes (school_id, full_name, birthdate, parent_phone, parent_email, monthly_fee, active, created_at)
SELECT s.id, x.name, x.bd, '698678178', 'nolifeprogrammer1@gmail.com', 0.00, 1, NOW()
  FROM (
    SELECT 'Στέφανος Μιχαλόπουλος' AS name, '2009-09-11' AS bd UNION ALL
    SELECT 'Γιώργος Σαμαράς',        '2010-02-28' UNION ALL
    SELECT 'Θωμάς Παπαγιάννης',      '2009-12-01' UNION ALL
    SELECT 'Βασίλης Κυριακίδης',     '2010-04-16' UNION ALL
    SELECT 'Ανδρέας Χριστόπουλος',   '2009-08-07' UNION ALL
    SELECT 'Κατερίνα Μακρή',         '2010-11-23' UNION ALL
    SELECT 'Δήμητρα Οικονόμου',      '2009-05-14' UNION ALL
    SELECT 'Χριστίνα Δούκα',         '2010-06-02' UNION ALL
    SELECT 'Νεφέλη Ρήγα',            '2009-10-30' UNION ALL
    SELECT 'Θεοδώρα Χρυσοβέργη',    '2010-03-19'
  ) x
  JOIN schools s ON s.name='ΓΣ Test Larisas'
 WHERE NOT EXISTS (SELECT 1 FROM athletes WHERE school_id=s.id AND full_name=x.name);

INSERT INTO athletes (school_id, full_name, birthdate, parent_phone, parent_email, monthly_fee, active, created_at)
SELECT s.id, x.name, x.bd, '6970223930', 'info@mykalypsis.gr', 0.00, 1, NOW()
  FROM (
    SELECT 'Λευτέρης Καζαντζίδης' AS name, '2009-07-27' AS bd UNION ALL
    SELECT 'Απόστολος Σεϊταρίδης',   '2010-09-15' UNION ALL
    SELECT 'Ηλίας Βλαχόπουλος',      '2009-01-08' UNION ALL
    SELECT 'Μιχάλης Καρύδης',        '2010-12-04' UNION ALL
    SELECT 'Αντώνης Παπασταύρου',    '2009-04-21' UNION ALL
    SELECT 'Άρτεμις Σακελλαρίου',    '2010-07-09' UNION ALL
    SELECT 'Δανάη Κατσαρού',         '2009-11-13' UNION ALL
    SELECT 'Ελευθερία Μαυρίδη',      '2010-05-26' UNION ALL
    SELECT 'Ζωή Παπακωνσταντίνου',   '2009-08-31' UNION ALL
    SELECT 'Ιφιγένεια Νικολαΐδου',   '2010-02-06'
  ) x
  JOIN schools s ON s.name='ΑΟ Test Thessalonikis'
 WHERE NOT EXISTS (SELECT 1 FROM athletes WHERE school_id=s.id AND full_name=x.name);

INSERT INTO athletes (school_id, full_name, birthdate, parent_phone, parent_email, monthly_fee, active, created_at)
SELECT s.id, x.name, x.bd, '698678178', 'support@timologion.gr', 0.00, 1, NOW()
  FROM (
    SELECT 'Αλέξανδρος Κρητικός'  AS name, '2009-10-24' AS bd UNION ALL
    SELECT 'Βαγγέλης Γιαννόπουλος',  '2010-06-08' UNION ALL
    SELECT 'Γρηγόρης Ασλανίδης',     '2009-02-17' UNION ALL
    SELECT 'Δημοσθένης Παπαμιχαήλ',  '2010-11-01' UNION ALL
    SELECT 'Θεόδωρος Σιδέρης',       '2009-05-10' UNION ALL
    SELECT 'Αικατερίνη Πολυζωΐδη',   '2010-03-25' UNION ALL
    SELECT 'Βαρβάρα Στάθη',          '2009-12-12' UNION ALL
    SELECT 'Γεωργία Τσάκα',          '2010-08-29' UNION ALL
    SELECT 'Δέσποινα Φυσαράκη',      '2009-01-06' UNION ALL
    SELECT 'Ελισάβετ Χαραλάμπους',   '2010-04-22'
  ) x
  JOIN schools s ON s.name='Σ.Α. Test Patras'
 WHERE NOT EXISTS (SELECT 1 FROM athletes WHERE school_id=s.id AND full_name=x.name);


-- ── 4) REGISTRATIONS — mass distribute across the 5 categories ──
-- Male-name → male cats via MOD(id,3); female-name → female cats via
-- MOD(id,2). Safe INSERT IGNORE against uk_event_cat_ath so it can
-- be re-run any number of times.
INSERT IGNORE INTO event_registrations
  (event_id, category_id, registering_school_id, athlete_id,
   coach_name, coach_phone,
   athlete_snapshot, status, payment_status, amount, created_at)
SELECT @ev_full,
       CASE
         WHEN SUBSTRING_INDEX(a.full_name, ' ', 1) NOT IN (
                'Ελένη','Ιωάννα','Μαρία','Σοφία','Άννα','Κατερίνα','Δήμητρα','Χριστίνα',
                'Νεφέλη','Θεοδώρα','Άρτεμις','Δανάη','Ελευθερία','Ζωή','Ιφιγένεια',
                'Αικατερίνη','Βαρβάρα','Γεωργία','Δέσποινα','Ελισάβετ'
              )
           THEN CASE MOD(a.id, 3)
                  WHEN 0 THEN @cat_k55m
                  WHEN 1 THEN @cat_k60m
                  ELSE       @cat_katm
                END
         ELSE CASE MOD(a.id, 2)
                  WHEN 0 THEN @cat_k50f
                  ELSE       @cat_katf
                END
       END,
       a.school_id, a.id,
       CONCAT('Προπονητής Δοκιμής (', s.name, ')'),
       CASE s.name
         WHEN 'Α.Σ. Test Athens'       THEN '6970223930'
         WHEN 'ΓΣ Test Larisas'        THEN '698678178'
         WHEN 'ΑΟ Test Thessalonikis'  THEN '6970223930'
         WHEN 'Σ.Α. Test Patras'       THEN '698678178'
         ELSE '698678178'
       END,
       JSON_OBJECT('full_name', a.full_name, 'birthdate', a.birthdate),
       'approved', 'unpaid', 15.00, NOW()
  FROM athletes a
  JOIN schools s ON s.id = a.school_id
 WHERE @ev_full   IS NOT NULL
   AND @cat_k55m  IS NOT NULL AND @cat_k60m IS NOT NULL
   AND @cat_k50f  IS NOT NULL AND @cat_katm IS NOT NULL AND @cat_katf IS NOT NULL
   AND s.name IN ('Α.Σ. Test Athens','ΓΣ Test Larisas','ΑΟ Test Thessalonikis','Σ.Α. Test Patras')
   AND a.active = 1;
