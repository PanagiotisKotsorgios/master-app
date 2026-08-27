<?php
/**
 * ============================================================
 * tools/stress_test_insert.php — Εισαγωγή Δοκιμαστικών Δεδομένων
 * ============================================================
 * Δημιουργεί:
 *   • 1 σχολή Basic plan  (59 αθλητές, τμήματα, κανόνες, ιστορικό)
 *   • 1 σχολή Pro plan    (80 αθλητές, τμήματα, κανόνες, οικονομικά,
 *                          αγωνιστικά, ιστορικό, υπέρβαση SMS)
 * Πρόσβαση: ?token=stress_2026 ή CLI
 * ============================================================
 */

if (php_sapi_name() !== 'cli' && ($_GET['token'] ?? '') !== 'stress_2026') {
    http_response_code(403);
    die('Access denied. Add ?token=stress_2026 to URL or run via CLI.');
}

require_once __DIR__ . '/../includes/config.php';

$db = getDB();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// ── Επιτρεπτά emails / phones ────────────────────────────────────────────────
$ALLOWED_EMAILS = [
    'pkotsorgios654@gmail.com',
    'opengplms@gmail.com',
    'nolifeprogrammer1@gmail.com',
    'hattorihanzo1916@gmail.com',
    'mikasa1915@gmail.com',
];
$ALLOWED_PHONES = [
    '6986788178',
    '6986422079',
    '6970223930',
];

// ── Ελληνικά ονόματα ──────────────────────────────────────────────────────────
$FIRST_MALE   = ['ΝΙΚΟΣ','ΓΙΩΡΓΟΣ','ΔΗΜΗΤΡΗΣ','ΠΑΝΑΓΙΩΤΗΣ','ΚΩΣΤΑΣ','ΑΛΕΞΑΝΔΡΟΣ','ΧΡΗΣΤΟΣ','ΒΑΣΙΛΗΣ','ΘΑΝΑΣΗΣ','ΣΤΑΥΡΟΣ','ΗΛΙΑΣ','ΣΠΥΡΟΣ','ΛΕΥΤΕΡΗΣ','ΓΙΑΝΝΗΣ','ΑΝΤΩΝΗΣ','ΜΙΧΑΛΗΣ','ΠΕΤΡΟΣ','ΣΩΤΗΡΗΣ','ΝΙΚΟΛΑΟΣ','ΕΥΑΓΓΕΛΟΣ','ΚΥΡΙΑΚΟΣ','ΠΑΡΗΣ','ΣΤΕΛΙΟΣ','ΑΓΓΕΛΟΣ','ΜΑΝΩΛΗΣ'];
$FIRST_FEMALE = ['ΜΑΡΙΑ','ΕΛΕΝΗ','ΣΟΦΙΑ','ΑΝΝΑ','ΧΡΙΣΤΙΝΑ','ΑΙΚΑΤΕΡΙΝΗ','ΔΗΜΗΤΡΑ','ΕΥΑΓΓΕΛΙΑ','ΣΤΑΥΡΟΥΛΑ','ΝΙΚΗ','ΑΓΓΕΛΙΚΗ','ΠΑΝΑΓΙΩΤΑ','ΙΩΑΝΝΑ','ΑΣΠΑ','ΑΝΤΩΝΙΑ','ΕΙΡΗΝΗ','ΘΕΟΔΩΡΑ','ΜΑΓΔΑ','ΖΩΗ','ΚΑΤΕΡΙΝΑ','ΟΛΓΑ','ΒΑΣΙΛΙΚΗ','ΑΛΕΞΑΝΔΡΑ','ΧΑΡΙΚΛΕΙΑ','ΣΜΑΡΑΓΔΑ'];
$SURNAMES     = ['ΠΑΠΑΔΟΠΟΥΛΟΣ','ΑΘΑΝΑΣΙΟΥ','ΝΙΚΟΛΑΟΥ','ΔΗΜΗΤΡΙΟΥ','ΚΩΝΣΤΑΝΤΙΝΟΥ','ΠΑΠΑΔΗΜΗΤΡΙΟΥ','ΓΕΩΡΓΙΟΥ','ΑΓΓΕΛΟΠΟΥΛΟΣ','ΑΔΑΜΟΠΟΥΛΟΣ','ΚΑΤΣΑΡΟΣ','ΛΙΑΠΗΣ','ΤΖΙΒΕΛΕΚΗΣ','ΜΟΥΡΑΤΙΔΗΣ','ΖΑΦΕΙΡΙΟΥ','ΠΡΕΒΕΖΑΝΟΣ','ΣΤΑΥΡΑΚΑΚΗΣ','ΚΟΥΤΣΟΠΕΤΡΟΣ','ΔΡΑΓΑΤΑΚΗΣ','ΒΛΑΧΟΠΟΥΛΟΣ','ΑΛΕΞΑΝΔΡΟΠΟΥΛΟΣ','ΚΑΡΑΤΖΙΩΤΗΣ','ΜΠΟΥΡΔΟΥΛΗΣ','ΞΕΝΟΣ','ΤΑΣΙΟΣ','ΚΥΡΙΑΚΙΔΗΣ','ΠΑΠΑΙΩΑΝΝΟΥ','ΙΩΑΝΝΙΔΗΣ','ΧΡΙΣΤΟΔΟΥΛΟΥ','ΘΕΟΔΩΡΙΔΗΣ','ΣΙΔΕΡΗΣ'];
$FATHER_NAMES = ['ΓΕΩΡΓΙΟΣ','ΝΙΚΟΛΑΟΣ','ΔΗΜΗΤΡΙΟΣ','ΑΝΤΩΝΙΟΣ','ΠΑΝΑΓΙΩΤΗΣ','ΚΩΝΣΤΑΝΤΙΝΟΣ','ΑΛΕΞΑΝΔΡΟΣ','ΒΑΣΙΛΕΙΟΣ','ΧΡΗΣΤΟΣ','ΣΩΤΗΡΙΟΣ','ΙΩΑΝΝΗΣ','ΕΥΑΓΓΕΛΟΣ','ΜΙΧΑΗΛ','ΣΤΑΥΡΟΣ'];
$MOTHER_NAMES = ['ΜΑΡΙΑ','ΕΛΕΝΗ','ΣΟΦΙΑ','ΧΡΙΣΤΙΝΑ','ΑΙΚΑΤΕΡΙΝΗ','ΔΗΜΗΤΡΑ','ΕΥΑΓΓΕΛΙΑ','ΑΝΝΑ','ΙΩΑΝΝΑ','ΑΝΤΩΝΙΑ'];
$CITIES_BASIC = ['ΑΘΗΝΑ','ΠΕΙΡΑΙΑΣ','ΓΛΥΦΑΔΑ','ΚΑΛΛΙΘΕΑ','ΠΑΛΑΙΟ ΦΑΛΗΡΟ','ΝΙΚΑΙΑ','ΚΟΡΥΔΑΛΛΟΣ','ΜΟΣΧΑΤΟ'];
$CITIES_PRO   = ['ΘΕΣΣΑΛΟΝΙΚΗ','ΚΑΛΑΜΑΡΙΑ','ΝΕΑΠΟΛΗ','ΣΥΚΙΕΣ','ΑΜΠΕΛΟΚΗΠΟΙ','ΕΥΟΣΜΟΣ','ΣΤΑΥΡΟΥΠΟΛΗ','ΠΟΛΙΧΝΗ'];
$ADDRESSES_BASIC = ['Ακαδημίας','Πανεπιστημίου','Σταδίου','Ερμού','Αθηνάς','Πατησίων','Αλεξάνδρας','Μεσογείων','Κηφισίας','Βουλιαγμένης'];
$ADDRESSES_PRO   = ['Εγνατία','Τσιμισκή','Μητροπόλεως','Βασ.Όλγας','Νίκης','Δελφών','Μοναστηρίου','Λαγκαδά','Αγ.Δημητρίου','Βενιζέλου'];

// ── Βοηθητικές συναρτήσεις ───────────────────────────────────────────────────
function pick(array $arr) { return $arr[array_rand($arr)]; }

function randomDate(string $from, string $to): string {
    return date('Y-m-d', rand(strtotime($from), strtotime($to)));
}

function makeName(bool $female, array $first_m, array $first_f, array $surns): array {
    $first   = $female ? pick($first_f) : pick($first_m);
    $surn    = pick($surns);
    if ($female) {
        if (mb_substr($surn, -2, null, 'UTF-8') === 'ΟΣ') {
            $surn = mb_substr($surn, 0, -2, 'UTF-8') . 'ΟΥ';
        } elseif (mb_substr($surn, -2, null, 'UTF-8') === 'ΗΣ') {
            $surn = mb_substr($surn, 0, -2, 'UTF-8') . 'Η';
        } elseif (mb_substr($surn, -3, null, 'UTF-8') === 'ΙΔΗ') {
            $surn = mb_substr($surn, 0, -3, 'UTF-8') . 'ΙΔΟ';
        }
    }
    return ['first' => $first, 'surname' => $surn, 'full' => $first . ' ' . $surn];
}

// ── Καθαρισμός προηγούμενης εκτέλεσης ───────────────────────────────────────
function cleanupSchool(PDO $db, string $schoolEmail): void {
    $st = $db->prepare("SELECT id FROM schools WHERE email=? LIMIT 1");
    $st->execute([$schoolEmail]);
    $sid = $st->fetchColumn();
    if (!$sid) return;

    // Βρίσκουμε athlete ids
    $ath = $db->prepare("SELECT id FROM athletes WHERE school_id=?");
    $ath->execute([$sid]);
    $athIds = $ath->fetchAll(PDO::FETCH_COLUMN);

    if ($athIds) {
        $in = implode(',', array_map('intval', $athIds));
        $db->exec("DELETE FROM subscriptions WHERE athlete_id IN ($in)");
        $db->exec("DELETE FROM athlete_pause_periods WHERE athlete_id IN ($in)");
        $db->exec("DELETE FROM weight_history WHERE athlete_id IN ($in)");
    }

    $tables = ['usage_log','transactions','athletes','notification_rules',
               'departments','competitions','school_meta','school_exempt_months',
               'school_plan_payments','users'];
    foreach ($tables as $t) {
        try {
            $db->prepare("DELETE FROM `$t` WHERE school_id=?")->execute([$sid]);
        } catch (PDOException $e) { /* πίνακας δεν υπάρχει ή διαφορετικό key */ }
    }

    // competition_participants
    try {
        $compIds = $db->prepare("SELECT id FROM competitions WHERE school_id=?");
        $compIds->execute([$sid]);
        foreach ($compIds->fetchAll(PDO::FETCH_COLUMN) as $cid) {
            $db->prepare("DELETE FROM competition_participants WHERE competition_id=?")->execute([$cid]);
        }
    } catch (PDOException $e) {}

    $db->prepare("DELETE FROM schools WHERE id=?")->execute([$sid]);
    echo "  ✓ Καθαρίστηκε προηγούμενη σχολή: $schoolEmail (id=$sid)\n";
}

// ── Δημιουργία σχολής ────────────────────────────────────────────────────────
function createSchool(PDO $db, array $d): int {
    $db->prepare("
        INSERT INTO schools (name, afm, address, city, phone, email, plan_id, plan_status,
                             subscription_status, active, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, 'active', 'active', 1, NOW())
    ")->execute([$d['name'], $d['afm'], $d['address'], $d['city'], $d['phone'], $d['email'], $d['plan_id']]);
    return (int)$db->lastInsertId();
}

// ── Δημιουργία owner ────────────────────────────────────────────────────────
function createOwner(PDO $db, int $sid, string $name, string $email): int {
    // Password = "password"
    $hash = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';
    $db->prepare("
        INSERT INTO users (school_id, name, email, password, role, active, created_at)
        VALUES (?, ?, ?, ?, 'owner', 1, NOW())
    ")->execute([$sid, $name, $email, $hash]);
    return (int)$db->lastInsertId();
}

// ── Δημιουργία τμημάτων ──────────────────────────────────────────────────────
function createDepartments(PDO $db, int $sid, string $sport): array {
    $depts = [
        ['name' => 'Παιδικό Τμήμα (6-12 ετών)',   'schedule' => 'Δευτέρα & Τετάρτη 17:00-18:30', 'fee' => 30.00, 'max' => 20],
        ['name' => 'Εφηβικό Τμήμα (13-17 ετών)',   'schedule' => 'Τρίτη & Πέμπτη 18:00-19:30',   'fee' => 35.00, 'max' => 20],
        ['name' => 'Ενήλικες',                      'schedule' => 'Δευτέρα, Τετάρτη & Παρασκευή 20:00-21:30', 'fee' => 40.00, 'max' => 30],
        ['name' => 'Αγωνιστικό Τμήμα',              'schedule' => 'Καθημερινά 19:00-21:00',       'fee' => 50.00, 'max' => 15],
    ];
    $ids = [];
    $stmt = $db->prepare("
        INSERT INTO departments (school_id, name, schedule, max_athletes, monthly_fee, sport, active)
        VALUES (?, ?, ?, ?, ?, ?, 1)
    ");
    foreach ($depts as $d) {
        $stmt->execute([$sid, $d['name'], $d['schedule'], $d['max'], $d['fee'], $sport]);
        $ids[] = (int)$db->lastInsertId();
    }
    return $ids; // [παιδικό, εφηβικό, ενήλικες, αγωνιστικό]
}

// ── Εισαγωγή αθλητών ────────────────────────────────────────────────────────
function insertAthletes(
    PDO $db, int $sid, int $count,
    array $allowedEmails, array $allowedPhones,
    array $deptIds,
    array $firstM, array $firstF, array $surns,
    array $fatherN, array $motherN,
    array $cities, array $addresses
): array {
    $athleteIds = [];
    $emailIdx = 0; $phoneIdx = 0;
    $fees = [25.00, 30.00, 35.00, 40.00, 50.00];

    $stmt = $db->prepare("
        INSERT INTO athletes
            (school_id, department_id, full_name, father_name, mother_name,
             birthdate, amka, registration_date, phone, parent_phone,
             email, parent_email, address, emergency_phone,
             notes, active, monthly_fee, debt_from_month, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, NOW())
    ");

    for ($i = 0; $i < $count; $i++) {
        $female    = ($i % 3 === 2);
        $nm        = makeName($female, $firstM, $firstF, $surns);
        $isMinor   = ($i % 5 === 0 || $i % 7 === 0); // ~25% ανήλικοι
        $birthY    = $isMinor ? rand(2011, 2016) : rand(1985, 2006);
        $birthdate = $birthY . '-' . str_pad(rand(1,12),2,'0',STR_PAD_LEFT) . '-' . str_pad(rand(1,28),2,'0',STR_PAD_LEFT);

        // Ημερομηνία εγγραφής: κατανεμημένη σε 2-3 χρόνια πριν
        $regDate = randomDate('2022-09-01', '2025-11-01');

        // debt_from_month = μήνας εγγραφής ή λίγο αργότερα
        $regDt = new DateTime($regDate);
        $debtFrom = $regDt->format('Y-m');

        // Email: κάθε 3ος αθλητής παίρνει email
        $email = ($i % 3 === 0) ? $allowedEmails[$emailIdx % count($allowedEmails)] : null;
        if ($i % 3 === 0) $emailIdx++;

        // Phone: κάθε 2ος αθλητής παίρνει τηλέφωνο
        $phone = ($i % 2 === 0) ? $allowedPhones[$phoneIdx % count($allowedPhones)] : null;
        if ($i % 2 === 0) $phoneIdx++;

        // Γονέας (για ανηλίκους)
        $parentPhone = $isMinor ? $allowedPhones[$phoneIdx % count($allowedPhones)] : null;
        $parentEmail = ($isMinor && $i % 2 === 0) ? $allowedEmails[$emailIdx % count($allowedEmails)] : null;

        $fatherName = pick($fatherN);
        $motherName = pick($motherN);
        $address    = pick($addresses) . ' ' . rand(1,120) . ', ' . pick($cities);
        $emergency  = $allowedPhones[$i % count($allowedPhones)];

        // Τμήμα: παιδί→παιδικό, έφηβος→εφηβικό, ενήλικας→ενήλικες/αγωνιστικό
        if ($isMinor) {
            $deptId = $deptIds[0]; // παιδικό
        } elseif ($birthY >= 2004) {
            $deptId = $deptIds[1]; // εφηβικό
        } elseif ($i % 6 === 0) {
            $deptId = $deptIds[3]; // αγωνιστικό
        } else {
            $deptId = $deptIds[2]; // ενήλικες
        }

        $fee = $fees[$i % count($fees)];

        // Σημειώσεις (κάθε 5ος)
        $notes = null;
        if ($i % 5 === 0) {
            $noteOptions = ['Αθλητής με εξαιρετικές επιδόσεις', 'Απαιτεί ιδιαίτερη προσοχή στις ασκήσεις', 'Παλαίμαχος - επέστρεψε μετά από διακοπή', 'Νέο μέλος - ξεκινά από μηδέν', 'Συμμετέχει σε αγώνες'];
            $notes = pick($noteOptions);
        }

        $stmt->execute([
            $sid, $deptId, $nm['full'], $fatherName, $motherName,
            $birthdate, null, $regDate, $phone, $parentPhone,
            $email, $parentEmail, $address, $emergency,
            $notes, $fee, $debtFrom
        ]);
        $athleteIds[] = (int)$db->lastInsertId();
    }
    return $athleteIds;
}

// ── Εισαγωγή συνδρομών ──────────────────────────────────────────────────────
function insertSubscriptions(PDO $db, int $sid, array $athleteIds, bool $isPro): void {
    $methods = ['cash', 'card', 'deposit'];
    $fees    = [25.00, 30.00, 35.00, 40.00, 50.00];

    $stmtSub = $db->prepare("
        INSERT INTO subscriptions
            (school_id, athlete_id, type, amount, paid_at, valid_from, valid_until,
             payment_method, status, notes, created_at)
        VALUES (?, ?, 'monthly', ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    $stmtFee = $db->prepare("UPDATE athletes SET monthly_fee=? WHERE id=?");

    foreach ($athleteIds as $i => $athId) {
        $amount = $fees[$i % count($fees)];
        $stmtFee->execute([$amount, $athId]);
        $method = $methods[$i % count($methods)];

        // Κατανομή: 35% paid-active, 25% paid-old, 20% overdue, 20% χωρίς
        $sc = $i % 20;

        if ($sc >= 0 && $sc <= 6) {
            // Ενεργή πληρωμή (καλύπτει τρέχοντα μήνα)
            $validFrom  = date('Y-m-01'); // 1η τρέχοντος μήνα
            $validUntil = date('Y-m-d', strtotime('last day of +1 month'));
            $paidAt     = date('Y-m-d', strtotime('-' . rand(1,25) . ' days'));
            $status     = 'paid';
            $note       = null;
        } elseif ($sc >= 7 && $sc <= 11) {
            // Παλιά πληρωμή (ληγμένη αλλά paid)
            $monthsAgo  = rand(2, 8);
            $validFrom  = date('Y-m-01', strtotime("-$monthsAgo months"));
            $validUntil = date('Y-m-d', strtotime("last day of -" . ($monthsAgo - 1) . " months"));
            $paidAt     = $validFrom;
            $status     = 'paid';
            $note       = null;
        } elseif ($sc >= 12 && $sc <= 15) {
            // Ληξιπρόθεσμη
            $daysAgo    = rand(10, 90);
            $validFrom  = date('Y-m-01', strtotime("-2 months"));
            $validUntil = date('Y-m-d', strtotime("-$daysAgo days"));
            $paidAt     = null;
            $status     = 'overdue';
            $note       = 'Εκκρεμεί πληρωμή';
        } else {
            // Χωρίς συνδρομή — παραλείπουμε
            continue;
        }

        $stmtSub->execute([$sid, $athId, $amount, $paidAt, $validFrom, $validUntil, $method, $status, $note]);

        // Pro: πρόσθετες ιστορικές πληρωμές (1-3 παλιότερες)
        if ($isPro && $i % 3 !== 0) {
            $histCount = rand(1, 3);
            for ($h = 1; $h <= $histCount; $h++) {
                $mOff = $h * rand(2, 4);
                $hFrom  = date('Y-m-01', strtotime("-{$mOff} months"));
                $hUntil = date('Y-m-d', strtotime("last day of -" . ($mOff - 1) . " months"));
                $hPaid  = $hFrom;
                try {
                    $stmtSub->execute([$sid, $athId, $amount, $hPaid, $hFrom, $hUntil, $method, 'paid', null]);
                } catch (PDOException $e) { /* overlap — παραλείπουμε */ }
            }
        }
    }
}

// ── Κανόνες ειδοποιήσεων ────────────────────────────────────────────────────
function insertNotificationRules(PDO $db, int $sid, string $schoolName, bool $isPro): int {
    $rules = [
        [
            'name'    => 'Υπενθύμιση 5 μέρες πριν τη λήξη',
            'trigger' => 'days_before', 'days' => 5,
            'ch'      => 'email,sms',
            'subj'    => "Υπενθύμιση πληρωμής — {$schoolName}",
            'body'    => "Αγαπητέ/ή {{athlete_name}},\n\nΗ συνδρομή σας λήγει σε 5 μέρες ({{valid_until}}).\nΠοσό: {{amount}}€\n\nΠαρακαλούμε φροντίστε για την ανανέωσή της.\n\nΜε εκτίμηση,\n{$schoolName}",
        ],
        [
            'name'    => 'Υπενθύμιση ημέρα λήξης',
            'trigger' => 'on_due', 'days' => 0,
            'ch'      => 'email,sms',
            'subj'    => "Η συνδρομή σας λήγει σήμερα — {$schoolName}",
            'body'    => "Αγαπητέ/ή {{athlete_name}},\n\nΜια φιλική υπενθύμιση: η συνδρομή σας λήγει σήμερα ({{valid_until}}).\nΠοσό: {{amount}}€\n\nΌποτε σας εξυπηρετεί, ας την ανανεώσουμε.\n\nΣας ευχαριστούμε πολύ,\n{$schoolName}",
        ],
        [
            'name'    => 'Ειδοποίηση 3 μέρες μετά τη λήξη',
            'trigger' => 'days_after', 'days' => 3,
            'ch'      => 'sms',
            'subj'    => "Εκκρεμής πληρωμή — {$schoolName}",
            'body'    => "Αγαπητέ/ή {{athlete_name}}, φιλική υπενθύμιση για τη συνδρομή σας ({{valid_until}}) — ποσό {{amount}}€. Ευχαριστούμε! {$schoolName}",
        ],
        [
            'name'    => 'Ειδοποίηση 10 μέρες μετά τη λήξη',
            'trigger' => 'days_after', 'days' => 10,
            'ch'      => 'email,sms',
            'subj'    => "Εκκρεμής πληρωμή — {$schoolName}",
            'body'    => "Αγαπητέ/ή {{athlete_name}},\n\nΘα θέλαμε φιλικά να σας υπενθυμίσουμε τη συνδρομή σας από τις τελευταίες 10 ημέρες.\nΠοσό: {{amount}}€\n\nΌποτε σας εξυπηρετεί, μπορούμε να την τακτοποιήσουμε. Αν χρειάζεστε κάτι, είμαστε στη διάθεσή σας.\n\nΣας ευχαριστούμε πολύ,\n{$schoolName}",
        ],
        [
            'name'    => 'Επιβεβαίωση πληρωμής',
            'trigger' => 'after_payment', 'days' => 0,
            'ch'      => 'email',
            'subj'    => "Επιβεβαίωση πληρωμής — {$schoolName}",
            'body'    => "Αγαπητέ/ή {{athlete_name}},\n\nΛάβαμε την πληρωμή σας {{amount}}€.\nΗ συνδρομή σας ισχύει έως {{valid_until}}.\n\nΕυχαριστούμε!\n{$schoolName}",
        ],
    ];

    if ($isPro) {
        $rules[] = [
            'name'    => 'Υπενθύμιση χρέους (1+ μήνας)',
            'trigger' => 'has_debt', 'days' => 0,
            'ch'      => 'email,sms',
            'subj'    => "Εκκρεμείς συνδρομές — {$schoolName}",
            'body'    => "Αγαπητέ/ή {{athlete_name}},\n\nΥπάρχουν εκκρεμείς συνδρομές για τον λογαριασμό σας.\nΠαρακαλούμε επικοινωνήστε μαζί μας.\n\n{$schoolName}",
        ];
    }

    $stmt = $db->prepare("
        INSERT INTO notification_rules
            (school_id, rule_name, trigger_type, trigger_days, channels, active,
             subject_tpl, body_tpl, include_name, include_date, include_amount)
        VALUES (?, ?, ?, ?, ?, 1, ?, ?, 1, 1, 1)
    ");
    $firstRuleId = null;
    foreach ($rules as $r) {
        $stmt->execute([$sid, $r['name'], $r['trigger'], $r['days'], $r['ch'], $r['subj'], $r['body']]);
        if ($firstRuleId === null) $firstRuleId = (int)$db->lastInsertId();
    }

    // school_meta: notif_seeded
    $db->prepare("INSERT INTO school_meta (school_id, meta_key, meta_val) VALUES (?, 'notif_seeded', '1') ON DUPLICATE KEY UPDATE meta_val='1'")->execute([$sid]);
    if ($firstRuleId) {
        $db->prepare("INSERT INTO school_meta (school_id, meta_key, meta_val) VALUES (?, 'default_rule_id', ?) ON DUPLICATE KEY UPDATE meta_val=?")->execute([$sid, $firstRuleId, $firstRuleId]);
    }

    return (int)count($rules);
}

// ── Οικονομικές κινήσεις (Pro only) ─────────────────────────────────────────
function insertTransactions(PDO $db, int $sid, array $athleteIds): void {
    $incomeCategories = ['Μηνιαία Συνδρομή','Εγγραφή','Εξέταση Ζώνης','Αγωνιστικά','Πώληση Εξοπλισμού','Σεμινάριο'];
    $expenseCategories = ['Ενοίκιο','ΔΕΗ / Νερό','Εξοπλισμός','Ασφάλεια','Διαφήμιση','Μεταφορικά','Καθαρισμός','Λογιστής'];
    $methods = ['cash','card','deposit','other'];

    $stmtT = $db->prepare("
        INSERT INTO transactions
            (school_id, type, category, amount, description, transaction_date,
             payment_method, athlete_id, notes, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");

    $now = new DateTime();
    // 12 μήνες πίσω
    for ($m = 11; $m >= 0; $m--) {
        $monthStart = (new DateTime())->modify("-{$m} months")->format('Y-m-01');
        $monthEnd   = (new DateTime($monthStart))->modify('last day of this month')->format('Y-m-d');

        // Έσοδα μήνα
        $numIncome = rand(8, 20);
        for ($j = 0; $j < $numIncome; $j++) {
            $cat    = pick($incomeCategories);
            $amount = rand(2, 10) * 10 + (rand(0,1) ? 0 : 5); // 20-105€
            $date   = randomDate($monthStart, $monthEnd);
            $athId  = ($j < count($athleteIds)) ? $athleteIds[$j % count($athleteIds)] : null;
            $desc   = "Εισροή: $cat";
            $note   = ($j % 4 === 0) ? 'Μετρητά από χέρι' : null;
            $stmtT->execute([$sid, 'income', $cat, $amount, $desc, $date, pick($methods), $athId, $note]);
        }

        // Έξοδα μήνα
        $numExpense = rand(3, 8);
        for ($j = 0; $j < $numExpense; $j++) {
            $cat    = pick($expenseCategories);
            $amount = rand(5, 30) * 10; // 50-300€
            $date   = randomDate($monthStart, $monthEnd);
            $desc   = "Πληρωμή: $cat";
            $stmtT->execute([$sid, 'expense', $cat, $amount, $desc, $date, pick($methods), null, null]);
        }
    }
}

// ── Αγωνιστικά (Pro only) ────────────────────────────────────────────────────
function insertCompetitions(PDO $db, int $sid, array $athleteIds): void {
    $comps = [
        ['name'=>'Πρωτάθλημα Αττικής 2025',      'date'=>'2025-03-15','location'=>'Αθήνα','type'=>'regional','cost'=>200.00,'notes'=>'Εξαιρετική συμμετοχή της σχολής μας'],
        ['name'=>'Παγκόσμιο Πρωτάθλημα 2025',    'date'=>'2025-05-20','location'=>'Μαδρίτη','type'=>'world','cost'=>1500.00,'notes'=>'Πρώτη συμμετοχή σε διεθνή διοργάνωση'],
        ['name'=>'Κύπελλο Βορείου Ελλάδος 2025', 'date'=>'2025-10-08','location'=>'Θεσσαλονίκη','type'=>'cup','cost'=>150.00,'notes'=>null],
        ['name'=>'Φιλικό Τουρνουά Χειμώνα 2025', 'date'=>'2025-12-14','location'=>'Θεσσαλονίκη','type'=>'friendly','cost'=>80.00,'notes'=>'Τοπικό τουρνουά φιλίας'],
        ['name'=>'Πρωτάθλημα Ελλάδος 2026',      'date'=>'2026-02-22','location'=>'Αθήνα','type'=>'national','cost'=>300.00,'notes'=>'Σημαντική επιτυχία με 3 μετάλλια'],
        ['name'=>'Ανοιχτό Τουρνουά Ανοίξης 2026','date'=>'2026-04-12','location'=>'Θεσσαλονίκη','type'=>'open','cost'=>120.00,'notes'=>null],
    ];

    $medals = ['gold','silver','bronze','none','none','none']; // 50% χωρίς μετάλλιο

    $stmtC = $db->prepare("
        INSERT INTO competitions (school_id, name, comp_date, location, cost, comp_type, notes)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $stmtP = $db->prepare("
        INSERT INTO competition_participants (competition_id, athlete_id, result, medal, points)
        VALUES (?, ?, ?, ?, ?)
    ");

    foreach ($comps as $ci => $c) {
        $stmtC->execute([$sid, $c['name'], $c['date'], $c['location'], $c['cost'], $c['type'], $c['notes']]);
        $compId = (int)$db->lastInsertId();

        // 6-12 αθλητές συμμετέχουν
        $participants = array_slice($athleteIds, $ci * 7, rand(6, 12));
        foreach ($participants as $pi => $athId) {
            $medal  = pick($medals) ?? 'none'; // enum: none/gold/silver/bronze
            $result = match($medal) {
                'gold'   => '1η θέση',
                'silver' => '2η θέση',
                'bronze' => '3η θέση',
                default  => (rand(0,1) ? 'Αποκλείστηκε στα ημιτελικά' : 'Συμμετοχή'),
            };
            $points = match($medal) { 'gold' => 10, 'silver' => 7, 'bronze' => 5, default => rand(0,3) };
            $stmtP->execute([$compId, $athId, $result, $medal, $points]);
        }
    }
}

// ── Ιστορικό usage_log (ειδοποιήσεις) ───────────────────────────────────────
function insertUsageLog(PDO $db, int $sid, int $userId, array $allowedEmails, array $allowedPhones, int $smsCount): void {
    $subjects = [
        'Υπενθύμιση πληρωμής',
        'Η συνδρομή σας λήγει σήμερα',
        'Εκκρεμής πληρωμή',
        'Επιβεβαίωση πληρωμής',
        'Μαζική ειδοποίηση σχολής',
    ];
    $stmt = $db->prepare("
        INSERT INTO usage_log (school_id, user_id, type, recipient, subject, status, sent_at)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");

    // Email: ~30 τελευταίων ημερών
    for ($i = 0; $i < 40; $i++) {
        $daysAgo = rand(1, 30);
        $sentAt  = date('Y-m-d H:i:s', strtotime("-{$daysAgo} days") + rand(0, 86399));
        $email   = pick($allowedEmails);
        $subj    = pick($subjects);
        $status  = ($i % 10 === 0) ? 'failed' : 'sent';
        $stmt->execute([$sid, $userId, 'email', $email, $subj, $status, $sentAt]);
    }

    // SMS: $smsCount εντός τρέχοντος μήνα (για το overage popup)
    for ($i = 0; $i < $smsCount; $i++) {
        $day    = str_pad(rand(1, 14), 2, '0', STR_PAD_LEFT);
        $hour   = str_pad(rand(8, 22), 2, '0', STR_PAD_LEFT);
        $min    = str_pad(rand(0, 59), 2, '0', STR_PAD_LEFT);
        $sentAt = date('Y-m') . "-{$day} {$hour}:{$min}:00";
        $phone  = $allowedPhones[$i % count($allowedPhones)];
        $stmt->execute([$sid, $userId, 'sms', $phone, 'SMS', 'sent', $sentAt]);
    }
}

// ════════════════════════════════════════════════════════════════════
// ΕΚΤΕΛΕΣΗ
// ════════════════════════════════════════════════════════════════════

echo "<pre style='font-family:monospace;font-size:13px;line-height:1.7'>\n";
echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║     MAster Stress Test — Εισαγωγή Δοκιμαστικών Δεδομένων   ║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n\n";

// Ρύθμιση monthly_sms_limit = 200 για Pro overage popup
$db->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('monthly_sms_limit','200') ON DUPLICATE KEY UPDATE setting_value='200'")->execute();
echo "✓ monthly_sms_limit = 200 (SMS overage popup θα εμφανιστεί στη Pro σχολή)\n\n";

// ─────────────────────────────────────────────────────────────────────
//  ΣΧΟΛΗ 1: BASIC PLAN
// ─────────────────────────────────────────────────────────────────────
echo "━━━ ΣΧΟΛΗ 1: BASIC PLAN ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
$basicEmail = 'hattorihanzo1916@gmail.com';
cleanupSchool($db, $basicEmail);

$basicSchoolId = createSchool($db, [
    'name'    => 'ΣΧΟΛΗ ΠΟΛΕΜΙΚΩΝ ΤΕΧΝΩΝ ΑΘΗΝΩΝ',
    'afm'     => '123456789',
    'address' => 'Ακαδημίας 28',
    'city'    => 'Αθήνα',
    'phone'   => '6986422079',
    'email'   => $basicEmail,
    'plan_id' => 1,
]);
$basicUserId = createOwner($db, $basicSchoolId, 'ΓΙΩΡΓΟΣ ΠΑΠΑΔΟΠΟΥΛΟΣ', $basicEmail);
echo "✓ Σχολή δημιουργήθηκε (ID: $basicSchoolId)\n";
echo "  Login: $basicEmail / password\n";

$basicDepts = createDepartments($db, $basicSchoolId, 'karate');
echo "✓ Τμήματα: " . count($basicDepts) . " δημιουργήθηκαν\n";

$basicAthletes = insertAthletes(
    $db, $basicSchoolId, 59,
    $ALLOWED_EMAILS, $ALLOWED_PHONES,
    $basicDepts,
    $FIRST_MALE, $FIRST_FEMALE, $SURNAMES,
    $FATHER_NAMES, $MOTHER_NAMES,
    $CITIES_BASIC, $ADDRESSES_BASIC
);
echo "✓ Αθλητές: " . count($basicAthletes) . " εισήχθησαν\n";

insertSubscriptions($db, $basicSchoolId, $basicAthletes, false);
echo "✓ Συνδρομές: εισήχθησαν (paid/overdue/χωρίς)\n";

$rCount = insertNotificationRules($db, $basicSchoolId, 'ΣΧΟΛΗ ΠΟΛΕΜΙΚΩΝ ΤΕΧΝΩΝ ΑΘΗΝΩΝ', false);
echo "✓ Κανόνες ειδοποιήσεων: $rCount εισήχθησαν\n";

insertUsageLog($db, $basicSchoolId, $basicUserId, $ALLOWED_EMAILS, $ALLOWED_PHONES, 12);
echo "✓ Ιστορικό ειδοποιήσεων: εισήχθη (40 email + 12 SMS)\n\n";

// ─────────────────────────────────────────────────────────────────────
//  ΣΧΟΛΗ 2: PRO PLAN
// ─────────────────────────────────────────────────────────────────────
echo "━━━ ΣΧΟΛΗ 2: PRO PLAN ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
$proEmail = 'nolifeprogrammer1@gmail.com';
cleanupSchool($db, $proEmail);

$proSchoolId = createSchool($db, [
    'name'    => 'ΑΘΛΗΤΙΚΟΣ ΟΜΙΛΟΣ ΚΑΡΑΤΕ ΘΕΣΣΑΛΟΝΙΚΗΣ',
    'afm'     => '987654321',
    'address' => 'Βασιλίσσης Όλγας 15',
    'city'    => 'Θεσσαλονίκη',
    'phone'   => '6970223930',
    'email'   => $proEmail,
    'plan_id' => 2,
]);
$proUserId = createOwner($db, $proSchoolId, 'ΔΗΜΗΤΡΗΣ ΝΙΚΟΛΑΟΥ', $proEmail);
echo "✓ Σχολή δημιουργήθηκε (ID: $proSchoolId)\n";
echo "  Login: $proEmail / password\n";

$proDepts = createDepartments($db, $proSchoolId, 'taekwondo');
echo "✓ Τμήματα: " . count($proDepts) . " δημιουργήθηκαν\n";

$proAthletes = insertAthletes(
    $db, $proSchoolId, 80,
    $ALLOWED_EMAILS, $ALLOWED_PHONES,
    $proDepts,
    $FIRST_MALE, $FIRST_FEMALE, $SURNAMES,
    $FATHER_NAMES, $MOTHER_NAMES,
    $CITIES_PRO, $ADDRESSES_PRO
);
echo "✓ Αθλητές: " . count($proAthletes) . " εισήχθησαν\n";

insertSubscriptions($db, $proSchoolId, $proAthletes, true);
echo "✓ Συνδρομές: εισήχθησαν (paid/overdue/χωρίς + ιστορικό)\n";

$rCount = insertNotificationRules($db, $proSchoolId, 'ΑΘΛΗΤΙΚΟΣ ΟΜΙΛΟΣ ΚΑΡΑΤΕ ΘΕΣΣΑΛΟΝΙΚΗΣ', true);
echo "✓ Κανόνες ειδοποιήσεων: $rCount εισήχθησαν\n";

insertTransactions($db, $proSchoolId, $proAthletes);
echo "✓ Οικονομικές κινήσεις: εισήχθησαν (12 μήνες ιστορικό εσόδων/εξόδων)\n";

insertCompetitions($db, $proSchoolId, $proAthletes);
echo "✓ Αγωνιστικά: 6 διοργανώσεις + συμμετοχές αθλητών\n";

insertUsageLog($db, $proSchoolId, $proUserId, $ALLOWED_EMAILS, $ALLOWED_PHONES, 215);
echo "✓ Ιστορικό ειδοποιήσεων: εισήχθη (40 email + 215 SMS)\n";
echo "  → 215 SMS > όριο 200 → Το overage popup θα εμφανιστεί!\n\n";

// ─────────────────────────────────────────────────────────────────────
//  ΣΥΝΟΨΗ
// ─────────────────────────────────────────────────────────────────────
echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║                         ΣΥΝΟΨΗ                              ║\n";
echo "╠══════════════════════════════════════════════════════════════╣\n";
echo "║  BASIC Σχολή                                                 ║\n";
echo "║  Όνομα  : ΣΧΟΛΗ ΠΟΛΕΜΙΚΩΝ ΤΕΧΝΩΝ ΑΘΗΝΩΝ                    ║\n";
echo "║  Email  : $basicEmail                 ║\n";
echo "║  Pass   : password                                           ║\n";
echo "║  Plan   : Basic (ID=1)                                       ║\n";
echo "║  ID     : $basicSchoolId                                                 ║\n";
echo "║  Αθλητές: 59  │  Τμήματα: 4  │  Κανόνες: 5                 ║\n";
echo "╠══════════════════════════════════════════════════════════════╣\n";
echo "║  PRO Σχολή                                                   ║\n";
echo "║  Όνομα  : ΑΘΛΗΤΙΚΟΣ ΟΜΙΛΟΣ ΚΑΡΑΤΕ ΘΕΣΣΑΛΟΝΙΚΗΣ             ║\n";
echo "║  Email  : $proEmail               ║\n";
echo "║  Pass   : password                                           ║\n";
echo "║  Plan   : Pro (ID=2)                                         ║\n";
echo "║  ID     : $proSchoolId                                                 ║\n";
echo "║  Αθλητές: 80  │  Τμήματα: 4  │  Κανόνες: 6                 ║\n";
echo "║  Οικονομικά: 12 μήνες  │  Αγωνιστικά: 6 διοργανώσεις       ║\n";
echo "║  SMS: 215 / 200 (υπέρβαση → popup εμφανίζεται!)             ║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n\n";
echo "Χρησιμοποιήθηκαν ΜΟΝΟ τα εγκεκριμένα emails/phones.\n";
echo "Δεν στάλθηκε κανένα πραγματικό SMS ή email.\n";
echo "</pre>\n";
