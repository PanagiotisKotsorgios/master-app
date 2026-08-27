-- ================================================================
-- 021_demo_championship_seed.sql
-- Populates a full, CLOSED championship for the Demo Σύλλογος MAster
-- so bracket/pool generation + XLSX exports can be exercised end to
-- end without waiting for real registrations.
--
-- Adds 4 external "test" schools + 40 athletes, then a fifth event
-- ("Πανελλήνιο Δοκιμαστικό TKD 2026") with 5 categories and 50
-- approved / paid registrations spread across the categories.
--
-- SAFE CONTACT ALLOWLIST — every generated email/phone comes only from:
--   pkotsorgios654@gmail.com · opengplms@gmail.com
--   nolifeprogrammer1@gmail.com · kotsorgios@hotmail.com
--   info@mykalypsis.gr · support@timologion.gr
--   698678178 · 6970223930
-- so demo activity never reaches a real third party.
--
-- Idempotent — every INSERT is NOT-EXISTS-guarded.
-- ================================================================

SET @demo_sid = (SELECT id FROM schools WHERE email='demo@master-app.gr' LIMIT 1);
SET @plan_pro = (SELECT id FROM plans   WHERE slug='pro' LIMIT 1);

-- ══════════════════════════════════════════════════════════════
-- 1) FOUR EXTRA "GUEST" SCHOOLS
-- ══════════════════════════════════════════════════════════════
INSERT INTO schools (name, email, phone, plan_id, plan_status, active, created_at)
SELECT 'Α.Σ. Test Athens',     'opengplms@gmail.com',        '6970223930', @plan_pro, 'active', 1, NOW()
WHERE @demo_sid IS NOT NULL AND NOT EXISTS (SELECT 1 FROM schools WHERE name='Α.Σ. Test Athens');

INSERT INTO schools (name, email, phone, plan_id, plan_status, active, created_at)
SELECT 'ΓΣ Test Larisas',      'nolifeprogrammer1@gmail.com','698678178',  @plan_pro, 'active', 1, NOW()
WHERE @demo_sid IS NOT NULL AND NOT EXISTS (SELECT 1 FROM schools WHERE name='ΓΣ Test Larisas');

INSERT INTO schools (name, email, phone, plan_id, plan_status, active, created_at)
SELECT 'ΑΟ Test Thessalonikis','info@mykalypsis.gr',         '6970223930', @plan_pro, 'active', 1, NOW()
WHERE @demo_sid IS NOT NULL AND NOT EXISTS (SELECT 1 FROM schools WHERE name='ΑΟ Test Thessalonikis');

INSERT INTO schools (name, email, phone, plan_id, plan_status, active, created_at)
SELECT 'Σ.Α. Test Patras',     'support@timologion.gr',      '698678178',  @plan_pro, 'active', 1, NOW()
WHERE @demo_sid IS NOT NULL AND NOT EXISTS (SELECT 1 FROM schools WHERE name='Σ.Α. Test Patras');


-- ══════════════════════════════════════════════════════════════
-- 2) 40 EXTRA ATHLETES (10 per guest school)
-- Ages 14-17 → U18 categories. All active. Contacts cycled from the
-- safe pool. No department (guest schools don't need one).
-- ══════════════════════════════════════════════════════════════

-- School A (Test Athens)
INSERT INTO athletes (school_id, full_name, birthdate, parent_phone, parent_email, monthly_fee, active, created_at)
SELECT s.id, name, bd, ph, em, 0.00, 1, NOW() FROM (
  SELECT 'Κωνσταντίνος Αθανασίου'  AS name, '2009-03-14' AS bd, '6970223930' AS ph, 'opengplms@gmail.com' AS em UNION ALL
  SELECT 'Νίκος Λέκκας',            '2010-07-22', '6970223930', 'opengplms@gmail.com' UNION ALL
  SELECT 'Δημήτρης Καραγιάννης',    '2009-11-05', '6970223930', 'opengplms@gmail.com' UNION ALL
  SELECT 'Παναγιώτης Στέργιου',     '2010-01-30', '6970223930', 'opengplms@gmail.com' UNION ALL
  SELECT 'Χρήστος Ζαφείρης',        '2009-06-18', '6970223930', 'opengplms@gmail.com' UNION ALL
  SELECT 'Ελένη Παπαδοπούλου',      '2010-08-12', '6970223930', 'opengplms@gmail.com' UNION ALL
  SELECT 'Ιωάννα Λαμπροπούλου',     '2009-04-04', '6970223930', 'opengplms@gmail.com' UNION ALL
  SELECT 'Μαρία Πετρίδη',           '2010-10-19', '6970223930', 'opengplms@gmail.com' UNION ALL
  SELECT 'Σοφία Χατζηνικολάου',     '2009-02-25', '6970223930', 'opengplms@gmail.com' UNION ALL
  SELECT 'Άννα Κοντογιάννη',        '2010-05-08', '6970223930', 'opengplms@gmail.com'
) x
JOIN schools s ON s.name='Α.Σ. Test Athens'
WHERE NOT EXISTS (SELECT 1 FROM athletes WHERE school_id=s.id AND full_name=x.name);

-- School B (Test Larisas)
INSERT INTO athletes (school_id, full_name, birthdate, parent_phone, parent_email, monthly_fee, active, created_at)
SELECT s.id, name, bd, ph, em, 0.00, 1, NOW() FROM (
  SELECT 'Στέφανος Μιχαλόπουλος'  AS name, '2009-09-11' AS bd, '698678178' AS ph, 'nolifeprogrammer1@gmail.com' AS em UNION ALL
  SELECT 'Γιώργος Σαμαράς',        '2010-02-28', '698678178', 'nolifeprogrammer1@gmail.com' UNION ALL
  SELECT 'Θωμάς Παπαγιάννης',      '2009-12-01', '698678178', 'nolifeprogrammer1@gmail.com' UNION ALL
  SELECT 'Βασίλης Κυριακίδης',      '2010-04-16', '698678178', 'nolifeprogrammer1@gmail.com' UNION ALL
  SELECT 'Ανδρέας Χριστόπουλος',   '2009-08-07', '698678178', 'nolifeprogrammer1@gmail.com' UNION ALL
  SELECT 'Κατερίνα Μακρή',          '2010-11-23', '698678178', 'nolifeprogrammer1@gmail.com' UNION ALL
  SELECT 'Δήμητρα Οικονόμου',      '2009-05-14', '698678178', 'nolifeprogrammer1@gmail.com' UNION ALL
  SELECT 'Χριστίνα Δούκα',          '2010-06-02', '698678178', 'nolifeprogrammer1@gmail.com' UNION ALL
  SELECT 'Νεφέλη Ρήγα',             '2009-10-30', '698678178', 'nolifeprogrammer1@gmail.com' UNION ALL
  SELECT 'Θεοδώρα Χρυσοβέργη',     '2010-03-19', '698678178', 'nolifeprogrammer1@gmail.com'
) x
JOIN schools s ON s.name='ΓΣ Test Larisas'
WHERE NOT EXISTS (SELECT 1 FROM athletes WHERE school_id=s.id AND full_name=x.name);

-- School C (Test Thessalonikis)
INSERT INTO athletes (school_id, full_name, birthdate, parent_phone, parent_email, monthly_fee, active, created_at)
SELECT s.id, name, bd, ph, em, 0.00, 1, NOW() FROM (
  SELECT 'Λευτέρης Καζαντζίδης'   AS name, '2009-07-27' AS bd, '6970223930' AS ph, 'info@mykalypsis.gr' AS em UNION ALL
  SELECT 'Απόστολος Σεϊταρίδης',    '2010-09-15', '6970223930', 'info@mykalypsis.gr' UNION ALL
  SELECT 'Ηλίας Βλαχόπουλος',      '2009-01-08', '6970223930', 'info@mykalypsis.gr' UNION ALL
  SELECT 'Μιχάλης Καρύδης',         '2010-12-04', '6970223930', 'info@mykalypsis.gr' UNION ALL
  SELECT 'Αντώνης Παπασταύρου',    '2009-04-21', '6970223930', 'info@mykalypsis.gr' UNION ALL
  SELECT 'Άρτεμις Σακελλαρίου',    '2010-07-09', '6970223930', 'info@mykalypsis.gr' UNION ALL
  SELECT 'Δανάη Κατσαρού',         '2009-11-13', '6970223930', 'info@mykalypsis.gr' UNION ALL
  SELECT 'Ελευθερία Μαυρίδη',      '2010-05-26', '6970223930', 'info@mykalypsis.gr' UNION ALL
  SELECT 'Ζωή Παπακωνσταντίνου',    '2009-08-31', '6970223930', 'info@mykalypsis.gr' UNION ALL
  SELECT 'Ιφιγένεια Νικολαΐδου',    '2010-02-06', '6970223930', 'info@mykalypsis.gr'
) x
JOIN schools s ON s.name='ΑΟ Test Thessalonikis'
WHERE NOT EXISTS (SELECT 1 FROM athletes WHERE school_id=s.id AND full_name=x.name);

-- School D (Test Patras)
INSERT INTO athletes (school_id, full_name, birthdate, parent_phone, parent_email, monthly_fee, active, created_at)
SELECT s.id, name, bd, ph, em, 0.00, 1, NOW() FROM (
  SELECT 'Αλέξανδρος Κρητικός'    AS name, '2009-10-24' AS bd, '698678178' AS ph, 'support@timologion.gr' AS em UNION ALL
  SELECT 'Βαγγέλης Γιαννόπουλος',   '2010-06-08', '698678178', 'support@timologion.gr' UNION ALL
  SELECT 'Γρηγόρης Ασλανίδης',     '2009-02-17', '698678178', 'support@timologion.gr' UNION ALL
  SELECT 'Δημοσθένης Παπαμιχαήλ',   '2010-11-01', '698678178', 'support@timologion.gr' UNION ALL
  SELECT 'Θεόδωρος Σιδέρης',       '2009-05-10', '698678178', 'support@timologion.gr' UNION ALL
  SELECT 'Αικατερίνη Πολυζωΐδη',   '2010-03-25', '698678178', 'support@timologion.gr' UNION ALL
  SELECT 'Βαρβάρα Στάθη',          '2009-12-12', '698678178', 'support@timologion.gr' UNION ALL
  SELECT 'Γεωργία Τσάκα',           '2010-08-29', '698678178', 'support@timologion.gr' UNION ALL
  SELECT 'Δέσποινα Φυσαράκη',      '2009-01-06', '698678178', 'support@timologion.gr' UNION ALL
  SELECT 'Ελισάβετ Χαραλάμπους',    '2010-04-22', '698678178', 'support@timologion.gr'
) x
JOIN schools s ON s.name='Σ.Α. Test Patras'
WHERE NOT EXISTS (SELECT 1 FROM athletes WHERE school_id=s.id AND full_name=x.name);


-- ══════════════════════════════════════════════════════════════
-- 3) THE CLOSED CHAMPIONSHIP EVENT
-- Registration window already closed (yesterday), start in 20 days,
-- organiser = the demo school, per-athlete fee 15€.
-- ══════════════════════════════════════════════════════════════
INSERT INTO events (slug, organiser_school_id, type, title, subtitle, description,
                    visibility, status,
                    venue_name, venue_address,
                    starts_at, ends_at,
                    registration_opens_at, registration_closes_at,
                    fee_model, fee_amount, contact_email, contact_phone,
                    created_at, updated_at)
SELECT 'demo-panellinio-tkd-2026-full', @demo_sid, 'championship',
       'Πανελλήνιο Δοκιμαστικό TKD 2026 (Full)',
       'Ολοκληρωμένο δοκιμαστικό πρωτάθλημα με 50 συμμετέχοντες από 5 σχολές',
       'Δοκιμαστικό event με πλήρη ενεργή λίστα συμμετεχόντων για έλεγχο brackets, pools, εξαγωγής XLSX και εκτύπωσης αγωνιστικών λιστών.',
       'public', 'closed',
       'Ολυμπιακό Γυμναστήριο Αθηνών', 'Λεωφ. Κηφισίας 37, Μαρούσι',
       DATE_ADD(CURDATE(), INTERVAL 20 DAY), DATE_ADD(CURDATE(), INTERVAL 22 DAY),
       DATE_SUB(CURDATE(), INTERVAL 30 DAY), DATE_SUB(CURDATE(), INTERVAL 1 DAY),
       'per_athlete', 15.00, 'pkotsorgios654@gmail.com', '698678178',
       NOW(), NOW()
WHERE @demo_sid IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM events WHERE slug='demo-panellinio-tkd-2026-full');

SET @ev_full = (SELECT id FROM events WHERE slug='demo-panellinio-tkd-2026-full' LIMIT 1);


-- ══════════════════════════════════════════════════════════════
-- 4) FIVE CATEGORIES (mixed Kumite / Kata / Team)
-- ══════════════════════════════════════════════════════════════
INSERT INTO event_categories (event_id, name, gender, min_age, max_age, min_weight, max_weight, style, format, pool_size, display_order)
SELECT @ev_full, 'Kumite -55kg U18 Άνδρες',    'M', 14, 17, 50.00, 55.00, 'kumite', 'pool_ko',     4, 1
WHERE @ev_full IS NOT NULL AND NOT EXISTS (SELECT 1 FROM event_categories WHERE event_id=@ev_full AND name='Kumite -55kg U18 Άνδρες');

INSERT INTO event_categories (event_id, name, gender, min_age, max_age, min_weight, max_weight, style, format, pool_size, display_order)
SELECT @ev_full, 'Kumite -60kg U18 Άνδρες',    'M', 14, 17, 55.00, 60.00, 'kumite', 'single_elim', 4, 2
WHERE @ev_full IS NOT NULL AND NOT EXISTS (SELECT 1 FROM event_categories WHERE event_id=@ev_full AND name='Kumite -60kg U18 Άνδρες');

INSERT INTO event_categories (event_id, name, gender, min_age, max_age, min_weight, max_weight, style, format, pool_size, display_order)
SELECT @ev_full, 'Kumite -50kg U18 Γυναίκες',  'F', 14, 17, 45.00, 50.00, 'kumite', 'pool_ko',     4, 3
WHERE @ev_full IS NOT NULL AND NOT EXISTS (SELECT 1 FROM event_categories WHERE event_id=@ev_full AND name='Kumite -50kg U18 Γυναίκες');

INSERT INTO event_categories (event_id, name, gender, min_age, max_age, style, format, pool_size, display_order)
SELECT @ev_full, 'Kata Individual Άνδρες',      'M', 14, 17, 'kata', 'round_robin', 6, 4
WHERE @ev_full IS NOT NULL AND NOT EXISTS (SELECT 1 FROM event_categories WHERE event_id=@ev_full AND name='Kata Individual Άνδρες');

INSERT INTO event_categories (event_id, name, gender, min_age, max_age, style, format, pool_size, display_order)
SELECT @ev_full, 'Kata Individual Γυναίκες',    'F', 14, 17, 'kata', 'round_robin', 6, 5
WHERE @ev_full IS NOT NULL AND NOT EXISTS (SELECT 1 FROM event_categories WHERE event_id=@ev_full AND name='Kata Individual Γυναίκες');


-- ══════════════════════════════════════════════════════════════
-- 5) REGISTRATIONS — 50 approved/paid registrations
-- Auto-distribute all athletes (from the 4 guest schools + any adults
-- of the demo school) across the 5 categories using name-hash so it's
-- deterministic across re-runs. Male → M categories, Female → F, plus
-- kata categories accept everyone.
-- ══════════════════════════════════════════════════════════════

-- Kumite -55kg Άνδρες  (first ~5 male athletes, alphabetical by school then name)
INSERT INTO event_registrations
  (event_id, category_id, registering_school_id, athlete_id,
   athlete_snapshot, status, payment_status, amount, created_at)
SELECT @ev_full,
       (SELECT id FROM event_categories WHERE event_id=@ev_full AND name='Kumite -55kg U18 Άνδρες' LIMIT 1),
       a.school_id, a.id,
       JSON_OBJECT('full_name', a.full_name, 'birthdate', a.birthdate),
       'approved', 'verified', 15.00, NOW()
  FROM athletes a
  JOIN schools s ON s.id = a.school_id
 WHERE s.name IN ('Α.Σ. Test Athens','ΓΣ Test Larisas','ΑΟ Test Thessalonikis','Σ.Α. Test Patras')
   AND a.full_name IN (
     'Κωνσταντίνος Αθανασίου','Στέφανος Μιχαλόπουλος',
     'Λευτέρης Καζαντζίδης','Αλέξανδρος Κρητικός',
     'Δημήτρης Καραγιάννης'
   )
   AND NOT EXISTS (
     SELECT 1 FROM event_registrations r
      WHERE r.event_id=@ev_full AND r.athlete_id=a.id
        AND r.category_id=(SELECT id FROM event_categories WHERE event_id=@ev_full AND name='Kumite -55kg U18 Άνδρες' LIMIT 1)
   );

-- Kumite -60kg Άνδρες
INSERT INTO event_registrations
  (event_id, category_id, registering_school_id, athlete_id,
   athlete_snapshot, status, payment_status, amount, created_at)
SELECT @ev_full,
       (SELECT id FROM event_categories WHERE event_id=@ev_full AND name='Kumite -60kg U18 Άνδρες' LIMIT 1),
       a.school_id, a.id,
       JSON_OBJECT('full_name', a.full_name, 'birthdate', a.birthdate),
       'approved', 'verified', 15.00, NOW()
  FROM athletes a
  JOIN schools s ON s.id = a.school_id
 WHERE s.name IN ('Α.Σ. Test Athens','ΓΣ Test Larisas','ΑΟ Test Thessalonikis','Σ.Α. Test Patras')
   AND a.full_name IN (
     'Νίκος Λέκκας','Γιώργος Σαμαράς','Απόστολος Σεϊταρίδης',
     'Βαγγέλης Γιαννόπουλος','Παναγιώτης Στέργιου','Θωμάς Παπαγιάννης',
     'Ηλίας Βλαχόπουλος','Γρηγόρης Ασλανίδης'
   )
   AND NOT EXISTS (
     SELECT 1 FROM event_registrations r
      WHERE r.event_id=@ev_full AND r.athlete_id=a.id
        AND r.category_id=(SELECT id FROM event_categories WHERE event_id=@ev_full AND name='Kumite -60kg U18 Άνδρες' LIMIT 1)
   );

-- Kumite -50kg Γυναίκες
INSERT INTO event_registrations
  (event_id, category_id, registering_school_id, athlete_id,
   athlete_snapshot, status, payment_status, amount, created_at)
SELECT @ev_full,
       (SELECT id FROM event_categories WHERE event_id=@ev_full AND name='Kumite -50kg U18 Γυναίκες' LIMIT 1),
       a.school_id, a.id,
       JSON_OBJECT('full_name', a.full_name, 'birthdate', a.birthdate),
       'approved', 'verified', 15.00, NOW()
  FROM athletes a
  JOIN schools s ON s.id = a.school_id
 WHERE s.name IN ('Α.Σ. Test Athens','ΓΣ Test Larisas','ΑΟ Test Thessalonikis','Σ.Α. Test Patras')
   AND a.full_name IN (
     'Ελένη Παπαδοπούλου','Κατερίνα Μακρή','Άρτεμις Σακελλαρίου',
     'Αικατερίνη Πολυζωΐδη','Ιωάννα Λαμπροπούλου','Δήμητρα Οικονόμου',
     'Δανάη Κατσαρού','Βαρβάρα Στάθη','Μαρία Πετρίδη','Χριστίνα Δούκα'
   )
   AND NOT EXISTS (
     SELECT 1 FROM event_registrations r
      WHERE r.event_id=@ev_full AND r.athlete_id=a.id
        AND r.category_id=(SELECT id FROM event_categories WHERE event_id=@ev_full AND name='Kumite -50kg U18 Γυναίκες' LIMIT 1)
   );

-- Kata Individual Άνδρες
INSERT INTO event_registrations
  (event_id, category_id, registering_school_id, athlete_id,
   athlete_snapshot, status, payment_status, amount, created_at)
SELECT @ev_full,
       (SELECT id FROM event_categories WHERE event_id=@ev_full AND name='Kata Individual Άνδρες' LIMIT 1),
       a.school_id, a.id,
       JSON_OBJECT('full_name', a.full_name, 'birthdate', a.birthdate),
       'approved', 'verified', 15.00, NOW()
  FROM athletes a
  JOIN schools s ON s.id = a.school_id
 WHERE s.name IN ('Α.Σ. Test Athens','ΓΣ Test Larisas','ΑΟ Test Thessalonikis','Σ.Α. Test Patras')
   AND a.full_name IN (
     'Χρήστος Ζαφείρης','Βασίλης Κυριακίδης','Μιχάλης Καρύδης',
     'Δημοσθένης Παπαμιχαήλ','Ανδρέας Χριστόπουλος','Αντώνης Παπασταύρου',
     'Θεόδωρος Σιδέρης','Στέφανος Γεωργίου','Νίκος Αντωνίου',
     'Παύλος Χατζή','Γιώργος Παπαδόπουλος'
   )
   AND NOT EXISTS (
     SELECT 1 FROM event_registrations r
      WHERE r.event_id=@ev_full AND r.athlete_id=a.id
        AND r.category_id=(SELECT id FROM event_categories WHERE event_id=@ev_full AND name='Kata Individual Άνδρες' LIMIT 1)
   );

-- Kata Individual Γυναίκες
INSERT INTO event_registrations
  (event_id, category_id, registering_school_id, athlete_id,
   athlete_snapshot, status, payment_status, amount, created_at)
SELECT @ev_full,
       (SELECT id FROM event_categories WHERE event_id=@ev_full AND name='Kata Individual Γυναίκες' LIMIT 1),
       a.school_id, a.id,
       JSON_OBJECT('full_name', a.full_name, 'birthdate', a.birthdate),
       'approved', 'verified', 15.00, NOW()
  FROM athletes a
  JOIN schools s ON s.id = a.school_id
 WHERE s.name IN ('Α.Σ. Test Athens','ΓΣ Test Larisas','ΑΟ Test Thessalonikis','Σ.Α. Test Patras')
   AND a.full_name IN (
     'Σοφία Χατζηνικολάου','Άννα Κοντογιάννη','Νεφέλη Ρήγα',
     'Θεοδώρα Χρυσοβέργη','Ελευθερία Μαυρίδη','Ζωή Παπακωνσταντίνου',
     'Ιφιγένεια Νικολαΐδου','Γεωργία Τσάκα','Δέσποινα Φυσαράκη',
     'Ελισάβετ Χαραλάμπους','Ελένη Κωνσταντίνου','Μαρία Δημητρίου'
   )
   AND NOT EXISTS (
     SELECT 1 FROM event_registrations r
      WHERE r.event_id=@ev_full AND r.athlete_id=a.id
        AND r.category_id=(SELECT id FROM event_categories WHERE event_id=@ev_full AND name='Kata Individual Γυναίκες' LIMIT 1)
   );
