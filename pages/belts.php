<?php
/**
 * pages/belts.php — Feature removed (belt/exam system disabled)
 */
require_once __DIR__ . '/../includes/config.php';
requireLogin();
flash('Η λειτουργία Ζωνών & Εξετάσεων δεν είναι διαθέσιμη.', 'warning');
redirect(APP_URL . '/dashboard/');
exit;

// ─── LEGACY CODE BELOW — kept for reference only, never executed ───
require_once __DIR__ . '/../includes/layout.php';

requireLogin();
renderPaymentWall();

$db  = getDB();
$sid = (int) schoolId();
$tab = (string)($_GET['tab'] ?? 'belts');

function beltsPageHasColumn(PDO $db, string $table, string $column): bool {
    static $cache = [];
    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) return $cache[$key];
    try {
        $stmt = $db->prepare("SHOW COLUMNS FROM $table LIKE ?");
        $stmt->execute([$column]);
        $cache[$key] = (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $cache[$key] = false;
    }
    return $cache[$key];
}

function beltsPageGetSportStyles(string $sport): array {
    $styles = [
        'taekwondo_wtf'    => [],
        'taekwondo_itf'    => [],
        'karate_shotokan'  => [],
        'karate_kyokushin' => [],
        'taekwondo' => ['wtf'=>'WT / WTF (World Taekwondo)','itf'=>'ITF (International TF)','gtf'=>'GTF (Global TF)'],
        'karate'    => ['shotokan'=>'Shotokan (JKA/SKI)','kyokushin'=>'Kyokushin / Knockdown','goju_ryu'=>'Goju-Ryu','shito_ryu'=>'Shito-Ryu','wado_ryu'=>'Wado-Ryu','uechi_ryu'=>'Uechi-Ryu'],
        'judo'      => [],
        'bjj'       => ['ibjjf'=>'IBJJF / Adult','ibjjf_junior'=>'IBJJF / Junior','ibjjf_child'=>'IBJJF / Child (4-15)'],
        'kickboxing'=> ['wako'=>'WAKO','iska'=>'ISKA','wpka'=>'WPKA / K1 Style'],
        'mma'=>[],'boxing'=>[],
        'wrestling'  => [],
        'pankration' => [],
        'sambo'      => [],
        'other'=>[],
    ];
    return $styles[$sport] ?? [];
}

function beltsPageGetBeltsByStyle(string $sport, string $style = ''): array {

    $TKD_WTF = [
        '10 Γκουπ — Λευκή',
        '9 Γκουπ — Κίτρινη',
        '8 Γκουπ — Κίτρινη / Πράσινο ρίγα',
        '7 Γκουπ — Πράσινη',
        '6 Γκουπ — Πράσινη / Μπλε ρίγα',
        '5 Γκουπ — Μπλε',
        '4 Γκουπ — Μπλε / Κόκκινο ρίγα',
        '3 Γκουπ — Κόκκινη',
        '2 Γκουπ — Κόκκινη / Μαύρο ρίγα',
        '1 Γκουπ — Κόκκινη / 2 Μαύρα τόξα',
        '1ο Νταν — Μαύρη (Il Dan)',
        '2ο Νταν — Μαύρη (Yi Dan)',
        '3ο Νταν — Μαύρη (Sam Dan)',
        '4ο Νταν — Μαύρη (Sa Dan)',
        '5ο Νταν — Μαύρη — Μάστερ (Oh Dan)',
        '6ο Νταν — Κόκκινη / Μαύρη — Μάστερ (Yuk Dan)',
        '7ο Νταν — Κόκκινη / Μαύρη — Μάστερ (Chil Dan)',
        '8ο Νταν — Κόκκινη / Μαύρη — Μεγάλος Μάστερ (Pal Dan)',
        '9ο Νταν — Κόκκινη — Μεγάλος Μάστερ (Ku Dan)',
    ];

    $TKD_ITF = [
        '10 CUP — Λευκή Ζώνη',
        '9 CUP — Μισή Κίτρινη Ζώνη',
        '8 CUP — Κίτρινη Ζώνη',
        '7 CUP — Μισή Πράσινη Ζώνη',
        '6 CUP — Πράσινη Ζώνη',
        '5 CUP — Μισή Μπλε Ζώνη',
        '4 CUP — Μπλε Ζώνη',
        '3 CUP — Μισή Κόκκινη Ζώνη',
        '2 CUP — Κόκκινη Ζώνη',
        '1 CUP — Μισή Μαύρη Ζώνη',
        '1 Dan — Μαύρη Ζώνη (Chodan)',
        '2 Dan — Μαύρη Ζώνη (Yidan)',
        '3 Dan — Μαύρη Ζώνη (Samdan)',
        '4 Dan — Μαύρη Ζώνη (Sadan)',
        '5 Dan — Μαύρη Ζώνη (Ohdan)',
        '6 Dan — Μαύρη Ζώνη (Yukdan)',
        '7 Dan — Μαύρη Ζώνη (Childan)',
        '8 Dan — Μαύρη Ζώνη (Paldan)',
        '9 Dan — Μαύρη Ζώνη (Kudan)',
    ];

    $KAR_SHOTOKAN = [
        '9ο Κιου — Λευκή',
        '8ο Κιου — Κίτρινη',
        '7ο Κιου — Πορτοκαλί',
        '6ο Κιου — Πράσινη',
        '5ο Κιου — Μωβ',
        '4ο Κιου — Μωβ / 1 ρίγα',
        '3ο Κιου — Καφέ',
        '2ο Κιου — Καφέ / 1 ρίγα',
        '1ο Κιου — Καφέ / 2 ρίγες',
        '1ο Νταν — Μαύρη (Shodan)',
        '2ο Νταν — Μαύρη (Nidan)',
        '3ο Νταν — Μαύρη (Sandan)',
        '4ο Νταν — Μαύρη (Yondan)',
        '5ο Νταν — Μαύρη (Godan)',
        '6ο Νταν — Μαύρη (Rokudan)',
        '7ο Νταν — Μαύρη (Shichidan)',
        '8ο Νταν — Μαύρη (Hachidan)',
        '9ο Νταν — Μαύρη (Kudan)',
        '10ο Νταν — Μαύρη (Judan)',
    ];

    $KAR_KYOKUSHIN = [
        '10ο Κιου — Λευκή',
        '9ο Κιου — Πορτοκαλί',
        '8ο Κιου — Πορτοκαλί / 1 ρίγα',
        '7ο Κιου — Μπλε',
        '6ο Κιου — Μπλε / 1 ρίγα',
        '5ο Κιου — Κίτρινη',
        '4ο Κιου — Κίτρινη / 1 ρίγα',
        '3ο Κιου — Πράσινη',
        '2ο Κιου — Πράσινη / 1 ρίγα',
        '1ο Κιου — Καφέ',
        '1ο Νταν — Μαύρη (Shodan)',
        '2ο Νταν — Μαύρη (Nidan)',
        '3ο Νταν — Μαύρη (Sandan)',
        '4ο Νταν — Μαύρη (Yondan)',
        '5ο Νταν — Μαύρη (Godan)',
        '6ο Νταν — Μαύρη (Rokudan)',
        '7ο Νταν — Μαύρη (Shichidan)',
        '8ο Νταν — Μαύρη (Hachidan)',
        '9ο Νταν — Μαύρη (Kudan)',
    ];

    $KAR_GOJU = [
        '10ο Κιου — Λευκή',
        '9ο Κιου — Λευκή / 1 ρίγα',
        '8ο Κιου — Κίτρινη',
        '7ο Κιου — Κίτρινη / 1 ρίγα',
        '6ο Κιου — Πράσινη',
        '5ο Κιου — Πράσινη / 1 ρίγα',
        '4ο Κιου — Μπλε',
        '3ο Κιου — Μπλε / 1 ρίγα',
        '2ο Κιου — Καφέ',
        '1ο Κιου — Καφέ / 1 ρίγα',
        '1ο Νταν — Μαύρη (Shodan)',
        '2ο Νταν — Μαύρη (Nidan)',
        '3ο Νταν — Μαύρη (Sandan)',
        '4ο Νταν — Μαύρη (Yondan)',
        '5ο Νταν — Μαύρη (Godan)',
        '6ο Νταν — Μαύρη (Rokudan)',
        '7ο Νταν — Κόκκινη / Λευκή (Shichidan)',
        '8ο Νταν — Κόκκινη / Λευκή (Hachidan)',
        '9ο Νταν — Κόκκινη (Kudan)',
        '10ο Νταν — Κόκκινη (Judan)',
    ];

    $KAR_SHITO = [
        '9ο Κιου — Λευκή',
        '8ο Κιου — Κίτρινη',
        '7ο Κιου — Πορτοκαλί',
        '6ο Κιου — Πράσινη',
        '5ο Κιου — Μπλε',
        '4ο Κιου — Μπλε / 1 ρίγα',
        '3ο Κιου — Καφέ',
        '2ο Κιου — Καφέ / 1 ρίγα',
        '1ο Κιου — Καφέ / 2 ρίγες',
        '1ο Νταν — Μαύρη (Shodan)',
        '2ο Νταν — Μαύρη (Nidan)',
        '3ο Νταν — Μαύρη (Sandan)',
        '4ο Νταν — Μαύρη (Yondan)',
        '5ο Νταν — Μαύρη (Godan)',
        '6ο Νταν — Κόκκινη / Λευκή (Rokudan)',
        '7ο Νταν — Κόκκινη / Λευκή (Shichidan)',
        '8ο Νταν — Κόκκινη / Λευκή (Hachidan)',
        '9ο Νταν — Κόκκινη (Kudan)',
        '10ο Νταν — Κόκκινη (Judan)',
    ];

    $KAR_WADO = [
        '9ο Κιου — Λευκή',
        '8ο Κιου — Κίτρινη',
        '7ο Κιου — Πορτοκαλί',
        '6ο Κιου — Πράσινη',
        '5ο Κιου — Μπλε',
        '4ο Κιου — Μωβ',
        '3ο Κιου — Καφέ',
        '2ο Κιου — Καφέ / 1 ρίγα',
        '1ο Κιου — Καφέ / 2 ρίγες',
        '1ο Νταν — Μαύρη (Shodan)',
        '2ο Νταν — Μαύρη (Nidan)',
        '3ο Νταν — Μαύρη (Sandan)',
        '4ο Νταν — Μαύρη (Yondan)',
        '5ο Νταν — Μαύρη (Godan)',
        '6ο Νταν — Μαύρη (Rokudan)',
        '7ο Νταν — Μαύρη — Μάστερ (Shichidan)',
        '8ο Νταν — Μαύρη — Μάστερ (Hachidan)',
        '9ο Νταν — Μαύρη — Γκραντ Μάστερ (Kudan)',
    ];

    $KAR_UECHI = [
        '10ο Κιου — Λευκή',
        '9ο Κιου — Κίτρινη',
        '8ο Κιου — Πράσινη',
        '7ο Κιου — Μπλε',
        '6ο Κιου — Μπλε / 1 ρίγα',
        '5ο Κιου — Καφέ',
        '4ο Κιου — Καφέ / 1 ρίγα',
        '3ο Κιου — Καφέ / 2 ρίγες',
        '2ο Κιου — Καφέ / 3 ρίγες',
        '1ο Κιου — Καφέ / 4 ρίγες',
        '1ο Νταν — Μαύρη (Shodan)',
        '2ο Νταν — Μαύρη (Nidan)',
        '3ο Νταν — Μαύρη (Sandan)',
        '4ο Νταν — Μαύρη (Yondan)',
        '5ο Νταν — Μαύρη (Godan)',
        '6ο Νταν — Μαύρη (Rokudan)',
        '7ο Νταν — Μαύρη (Shichidan)',
        '8ο Νταν — Μαύρη (Hachidan)',
        '9ο Νταν — Μαύρη (Kudan)',
        '10ο Νταν — Μαύρη — Γκραντ Μάστερ (Judan)',
    ];

    $JUDO = [
        '6ο Κιου — Λευκή',
        '5ο Κιου — Κίτρινη',
        '4ο Κιου — Πορτοκαλί',
        '3ο Κιου — Πράσινη',
        '2ο Κιου — Μπλε',
        '1ο Κιου — Καφέ',
        '1ο Νταν — Μαύρη (Shodan)',
        '2ο Νταν — Μαύρη (Nidan)',
        '3ο Νταν — Μαύρη (Sandan)',
        '4ο Νταν — Μαύρη (Yondan)',
        '5ο Νταν — Μαύρη (Godan)',
        '6ο Νταν — Κόκκινη / Λευκή (Rokudan)',
        '7ο Νταν — Κόκκινη / Λευκή (Shichidan)',
        '8ο Νταν — Κόκκινη / Λευκή (Hachidan)',
        '9ο Νταν — Κόκκινη (Kudan)',
        '10ο Νταν — Κόκκινη (Judan)',
    ];

    $BJJ_ADULT = [
        'Λευκή','Λευκή / 1 ρίγα','Λευκή / 2 ρίγες','Λευκή / 3 ρίγες','Λευκή / 4 ρίγες',
        'Μπλε','Μπλε / 1 ρίγα','Μπλε / 2 ρίγες','Μπλε / 3 ρίγες','Μπλε / 4 ρίγες',
        'Μωβ','Μωβ / 1 ρίγα','Μωβ / 2 ρίγες','Μωβ / 3 ρίγες','Μωβ / 4 ρίγες',
        'Καφέ','Καφέ / 1 ρίγα','Καφέ / 2 ρίγες','Καφέ / 3 ρίγες','Καφέ / 4 ρίγες',
        'Μαύρη','Μαύρη / 1 ρίγα','Μαύρη / 2 ρίγες','Μαύρη / 3 ρίγες','Μαύρη / 4 ρίγες','Μαύρη / 5 ρίγες','Μαύρη / 6 ρίγες',
        'Κόκκινη / Μαύρη — 7ο Νταν (Coral)','Κόκκινη / Μαύρη — 8ο Νταν (Coral)',
        'Κόκκινη / Λευκή — 9ο Νταν','Κόκκινη — 10ο Νταν',
    ];

    $BJJ_JUNIOR = [
        'Γκρι','Γκρι / Λευκή ρίγα','Γκρι / Μαύρη ρίγα',
        'Κίτρινη','Κίτρινη / Λευκή ρίγα','Κίτρινη / Μαύρη ρίγα',
        'Πορτοκαλί','Πορτοκαλί / Λευκή ρίγα','Πορτοκαλί / Μαύρη ρίγα',
        'Πράσινη','Πράσινη / Λευκή ρίγα','Πράσινη / Μαύρη ρίγα',
    ];

    $BJJ_CHILD = [
        'Λευκή','Λευκή / Γκρι ρίγα',
        'Γκρι','Γκρι / Λευκή ρίγα','Γκρι / Μαύρη ρίγα',
        'Κίτρινη','Κίτρινη / Λευκή ρίγα','Κίτρινη / Μαύρη ρίγα',
        'Πορτοκαλί','Πορτοκαλί / Λευκή ρίγα','Πορτοκαλί / Μαύρη ρίγα',
        'Πράσινη','Πράσινη / Λευκή ρίγα','Πράσινη / Μαύρη ρίγα',
    ];

    $KICKBOXING = [
        'Λευκή','Κίτρινη','Κίτρινη / Πορτοκαλί ρίγα','Πορτοκαλί','Πορτοκαλί / Πράσινη ρίγα',
        'Πράσινη','Πράσινη / Μπλε ρίγα','Μπλε','Μπλε / Καφέ ρίγα','Καφέ','Καφέ / Μαύρη ρίγα',
        'Μαύρη','Μαύρη / 1 ρίγα (1ο Νταν)','Μαύρη / 2 ρίγες (2ο Νταν)','Μαύρη / 3 ρίγες (3ο Νταν)',
        'Μαύρη / 4 ρίγες (4ο Νταν)','Μαύρη / 5 ρίγες (5ο Νταν)',
    ];

    if ($sport === 'taekwondo_wtf')    return $TKD_WTF;
    if ($sport === 'taekwondo_itf')    return $TKD_ITF;
    if ($sport === 'karate_shotokan')  return $KAR_SHOTOKAN;
    if ($sport === 'karate_kyokushin') return $KAR_KYOKUSHIN;

    if ($sport === 'taekwondo') {
        if ($style === 'itf') return $TKD_ITF;
        if ($style === 'gtf') return $TKD_WTF;
        return $TKD_WTF;
    }

    if ($sport === 'karate') {
        if ($style === 'kyokushin') return $KAR_KYOKUSHIN;
        if ($style === 'goju_ryu')  return $KAR_GOJU;
        if ($style === 'shito_ryu') return $KAR_SHITO;
        if ($style === 'wado_ryu')  return $KAR_WADO;
        if ($style === 'uechi_ryu') return $KAR_UECHI;
        return $KAR_SHOTOKAN;
    }

    if ($sport === 'judo')      return $JUDO;
    if ($sport === 'bjj') {
        if ($style === 'ibjjf_junior') return $BJJ_JUNIOR;
        if ($style === 'ibjjf_child')  return $BJJ_CHILD;
        return $BJJ_ADULT;
    }
    if ($sport === 'kickboxing') return $KICKBOXING;

    if ($sport === 'wrestling')  return ['Αρχάριος','Προχωρημένος Α','Προχωρημένος Β','Ανταγωνιστικός','Εθνικό Επίπεδο','Διεθνές Επίπεδο'];
    if ($sport === 'pankration') return ['Αρχάριος','Προχωρημένος Α','Προχωρημένος Β','Ανταγωνιστικός','Εθνικό Επίπεδο','Διεθνές Επίπεδο'];
    if ($sport === 'sambo')      return ['Αρχάριος','Προχωρημένος Α','Προχωρημένος Β','Ανταγωνιστικός','Εθνικό Επίπεδο','Διεθνές Επίπεδο'];
    if ($sport === 'mma')        return ['Αρχάριος','Προχωρημένος','Ανταγωνιστικός','Επαγγελματίας'];
    if ($sport === 'boxing')     return ['Αρχάριος','Ερασιτέχνης','Ανταγωνιστικός','Επαγγελματίας'];

    return ['Επίπεδο 1','Επίπεδο 2','Επίπεδο 3','Επίπεδο 4','Επίπεδο 5'];
}

function beltsPageSportHasBelts(string $sport): bool {
    return in_array($sport, [
        'taekwondo_wtf','taekwondo_itf',
        'karate_shotokan','karate_kyokushin',
        'taekwondo','karate','judo','bjj','kickboxing','mma','boxing',
        'wrestling','pankration','sambo',
    ], true);
}

if (!function_exists('fmtD')) {
    function fmtD(?string $d): string {
        if (!$d || $d === '0000-00-00') return '—';
        try { return (new DateTime($d))->format('d/m/Y'); } catch (Throwable $e) { return '—'; }
    }
}

if (!function_exists('sportLabelLocal')) {
    function sportLabelLocal(string $sport): string {
        $map = [
            'taekwondo_wtf'    => 'Taekwondo (WTF/WT)',
            'taekwondo_itf'    => 'Taekwondo (ITF)',
            'karate_shotokan'  => 'Karate Shotokan',
            'karate_kyokushin' => 'Karate Kyokushin',
            'bjj'              => 'BJJ',
            'judo'             => 'Judo',
            'kickboxing'       => 'Kickboxing',
            'boxing'           => 'Boxing',
            'mma'              => 'MMA',
            'wrestling'        => 'Πάλη',
            'pankration'       => 'Παγκράτιο',
            'sambo'            => 'Sambo',
            'other'            => 'Άλλο',
            'taekwondo'        => 'Taekwondo',
            'karate'           => 'Karate',
        ];
        if (isset($map[$sport])) return $map[$sport];
        return function_exists('sportLabel') ? sportLabel($sport) : $sport;
    }
}

/**
 * FIX: Sync logic rewritten to allow multiple rows per participant.
 * Strategy: DELETE all existing rows for this participant, then INSERT fresh ones.
 * This avoids the single-row assumption that breaks partial payments.
 *
 * For 'upsert': delete old rows and insert one canonical row.
 * The notes field encodes payment state:
 *   - fully paid:    exam_fee:{participantId}:paid
 *   - partial:       exam_fee:{participantId}:partial  (amount = what was collected so far)
 *   - pending/none:  exam_fee:{participantId}:pending
 *
 * IMPORTANT: When called from toggle_fee_paid we still use the simple paid/pending approach.
 * The SUM() read logic in the JSON endpoint handles partial rows accumulated over time.
 */
function beltsPageSyncExamFeeTransaction(
    PDO    $db,
    string $action,
    int    $schoolId,
    int    $participantId,
    int    $examId      = 0,
    int    $athleteId   = 0,
    float  $amount      = 0.0,
    string $examDate    = '',
    string $athleteName = '',
    bool   $isPaid      = false
): void {
    $baseMarker = 'exam_fee:' . $participantId;

    if ($action === 'delete') {
        $db->prepare("DELETE FROM transactions WHERE school_id = ? AND notes LIKE ?")
           ->execute([$schoolId, $baseMarker . '%']);
        return;
    }

    if ($amount <= 0) return;

    $txDate = $examDate !== '' ? $examDate : date('Y-m-d');
    $desc   = 'Τέλος Εξέτασης #' . $examId . ($athleteName !== '' ? ' — ' . $athleteName : '');
    // FIX: use exact prefix match (baseMarker:%) not wildcard both sides
    $notes  = $isPaid ? $baseMarker . ':paid' : $baseMarker . ':pending';

    // FIX: find by exact prefix to avoid cross-participant collisions
    $find = $db->prepare(
        "SELECT id FROM transactions WHERE school_id = ? AND notes LIKE ? LIMIT 1"
    );
    $find->execute([$schoolId, $baseMarker . ':%']);
    $txId = (int)($find->fetchColumn() ?: 0);

    if ($txId > 0) {
        // Update the single canonical row
        $db->prepare(
            "UPDATE transactions SET amount=?, transaction_date=?, description=?, athlete_id=?, notes=? WHERE id=?"
        )->execute([$amount, $txDate, $desc, $athleteId > 0 ? $athleteId : null, $notes, $txId]);

        // Delete any stale extra rows for this participant (e.g. leftover :partial rows)
        $db->prepare(
            "DELETE FROM transactions WHERE school_id = ? AND notes LIKE ? AND id != ?"
        )->execute([$schoolId, $baseMarker . ':%', $txId]);
        return;
    }

    $db->prepare(
        "INSERT INTO transactions
            (school_id, type, category, amount, description, transaction_date, payment_method, athlete_id, notes)
         VALUES (?, 'income', 'Εξετάσεις Ζωνών', ?, ?, ?, 'cash', ?, ?)"
    )->execute([$schoolId, $amount, $desc, $txDate, $athleteId > 0 ? $athleteId : null, $notes]);
}

$hasSportStyle      = beltsPageHasColumn($db, 'athletes', 'sport_style');
$hasFeeAmount       = beltsPageHasColumn($db, 'belt_exam_participants', 'fee_amount');
$hasFeePaid         = beltsPageHasColumn($db, 'belt_exam_participants', 'fee_paid');
$hasExamIdInHistory = beltsPageHasColumn($db, 'belt_history', 'exam_id');

// ── JSON endpoint: live exam data ──
// FIX: participants now carry paid_amount from transactions SUM (not fee_paid flag)
if (isset($_GET['json_exam']) && (int)$_GET['json_exam'] > 0) {
    $jExamId = (int)$_GET['json_exam'];
    $jSumExpected = $hasFeeAmount ? 'COALESCE(p.fee_amount,e.cost)' : 'e.cost';

    $jExam = $db->prepare(
        "SELECT e.*,
                COUNT(p.id) AS participant_count,
                COALESCE(SUM({$jSumExpected}),0) AS total_fee_expected,
                COALESCE((
                    SELECT SUM(t.amount)
                    FROM transactions t
                    WHERE t.school_id = e.school_id
                      AND t.notes LIKE 'exam_fee:%'
                      AND t.notes NOT LIKE '%:pending%'
                      AND EXISTS (
                          SELECT 1 FROM belt_exam_participants px
                          WHERE px.exam_id = e.id
                            AND t.notes LIKE CONCAT('exam_fee:', px.id, ':%')
                      )
                ), 0) AS total_fee_collected
         FROM belt_exams e
         LEFT JOIN belt_exam_participants p ON p.exam_id=e.id
         WHERE e.id=? AND e.school_id=?
         GROUP BY e.id"
    );
    $jExam->execute([$jExamId, $sid]);
    $jRow = $jExam->fetch(PDO::FETCH_ASSOC);

    $jParts = [];
    if ($jRow) {
        // FIX: fetch paid_amount per participant from transactions using SUM
        // Notes NOT LIKE '%:pending%' means only paid/partial rows count as collected
        $jp = $db->prepare(
            "SELECT p.id, p.exam_id, p.athlete_id, p.belt_before, p.belt_after, p.result,
                    " . ($hasFeeAmount ? 'p.fee_amount' : 'NULL AS fee_amount') . ",
                    " . ($hasFeePaid   ? 'p.fee_paid'   : '0 AS fee_paid') . ",
                    a.full_name,
                    COALESCE((
                        SELECT SUM(t.amount)
                        FROM transactions t
                        WHERE t.school_id = e.school_id
                          AND t.notes LIKE CONCAT('exam_fee:', p.id, ':%')
                          AND t.notes NOT LIKE '%:pending%'
                    ), 0) AS paid_amount
             FROM belt_exam_participants p
             JOIN belt_exams e ON e.id = p.exam_id
             JOIN athletes a ON a.id = p.athlete_id
             WHERE p.exam_id = ?
             ORDER BY a.full_name"
        );
        $jp->execute([$jExamId]);
        $jParts = $jp->fetchAll(PDO::FETCH_ASSOC);
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['exam' => $jRow, 'participants' => $jParts], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = $_POST;
    $action = (string)($a['_action'] ?? '');
    try {
        if ($action === 'save_exam') {
            $id       = (int)($a['id'] ?? 0);
            $examDate = (string)($a['exam_date'] ?? date('Y-m-d'));
            $cost     = (float)($a['cost'] ?? 0);
            $location = trim((string)($a['location'] ?? ''));
            $notes    = trim((string)($a['notes'] ?? ''));
            if ($id > 0) {
                $db->prepare("UPDATE belt_exams SET exam_date=?,cost=?,location=?,notes=? WHERE id=? AND school_id=?")
                   ->execute([$examDate,$cost,$location,$notes,$id,$sid]);
                flash('Η εξέταση ενημερώθηκε.');
            } else {
                $db->prepare("INSERT INTO belt_exams (school_id,exam_date,cost,location,notes) VALUES (?,?,?,?,?)")
                   ->execute([$sid,$examDate,$cost,$location,$notes]);
                flash('Η εξέταση δημιουργήθηκε.');
            }
        }

        if ($action === 'add_participant') {
            $examId     = (int)($a['exam_id'] ?? 0);
            $athleteId  = (int)($a['athlete_id'] ?? 0);
            $beltBefore = trim((string)($a['belt_before'] ?? ''));
            $beltAfter  = trim((string)($a['belt_after'] ?? ''));
            $result     = ((string)($a['result'] ?? 'pass')) === 'fail' ? 'fail' : 'pass';
            $feeAmount  = $hasFeeAmount && isset($a['fee_amount']) && $a['fee_amount'] !== ''
                          ? (float)$a['fee_amount'] : null;
            $feePaid    = $hasFeePaid && isset($a['fee_paid']) ? 1 : 0;

            $examCheck = $db->prepare("SELECT id,exam_date,cost FROM belt_exams WHERE id=? AND school_id=? LIMIT 1");
            $examCheck->execute([$examId,$sid]);
            $examRow = $examCheck->fetch(PDO::FETCH_ASSOC);

            $athCheckSql = "SELECT id,full_name,belt"
                . ($hasSportStyle ? ",sport_style" : ",'' AS sport_style")
                . " FROM athletes WHERE id=? AND school_id=? LIMIT 1";
            $athCheck = $db->prepare($athCheckSql);
            $athCheck->execute([$athleteId,$sid]);
            $athRow = $athCheck->fetch(PDO::FETCH_ASSOC);

            if (!$examRow || !$athRow) throw new RuntimeException('Μη έγκυρη εξέταση ή αθλητής.');

            $db->beginTransaction();

            $findPart = $db->prepare("SELECT id FROM belt_exam_participants WHERE exam_id=? AND athlete_id=? LIMIT 1");
            $findPart->execute([$examId,$athleteId]);
            $participantId = (int)($findPart->fetchColumn() ?: 0);

            if ($participantId > 0) {
                if ($hasFeeAmount && $hasFeePaid) {
                    $db->prepare("UPDATE belt_exam_participants SET belt_before=?,belt_after=?,result=?,fee_amount=?,fee_paid=? WHERE id=?")
                       ->execute([$beltBefore,$beltAfter,$result,$feeAmount,$feePaid,$participantId]);
                } elseif ($hasFeeAmount) {
                    $db->prepare("UPDATE belt_exam_participants SET belt_before=?,belt_after=?,result=?,fee_amount=? WHERE id=?")
                       ->execute([$beltBefore,$beltAfter,$result,$feeAmount,$participantId]);
                } elseif ($hasFeePaid) {
                    $db->prepare("UPDATE belt_exam_participants SET belt_before=?,belt_after=?,result=?,fee_paid=? WHERE id=?")
                       ->execute([$beltBefore,$beltAfter,$result,$feePaid,$participantId]);
                } else {
                    $db->prepare("UPDATE belt_exam_participants SET belt_before=?,belt_after=?,result=? WHERE id=?")
                       ->execute([$beltBefore,$beltAfter,$result,$participantId]);
                }
            } else {
                if ($hasFeeAmount && $hasFeePaid) {
                    $db->prepare("INSERT INTO belt_exam_participants (exam_id,athlete_id,belt_before,belt_after,result,fee_amount,fee_paid) VALUES (?,?,?,?,?,?,?)")
                       ->execute([$examId,$athleteId,$beltBefore,$beltAfter,$result,$feeAmount,$feePaid]);
                } elseif ($hasFeeAmount) {
                    $db->prepare("INSERT INTO belt_exam_participants (exam_id,athlete_id,belt_before,belt_after,result,fee_amount) VALUES (?,?,?,?,?,?)")
                       ->execute([$examId,$athleteId,$beltBefore,$beltAfter,$result,$feeAmount]);
                } elseif ($hasFeePaid) {
                    $db->prepare("INSERT INTO belt_exam_participants (exam_id,athlete_id,belt_before,belt_after,result,fee_paid) VALUES (?,?,?,?,?,?)")
                       ->execute([$examId,$athleteId,$beltBefore,$beltAfter,$result,$feePaid]);
                } else {
                    $db->prepare("INSERT INTO belt_exam_participants (exam_id,athlete_id,belt_before,belt_after,result) VALUES (?,?,?,?,?)")
                       ->execute([$examId,$athleteId,$beltBefore,$beltAfter,$result]);
                }
                $participantId = (int)$db->lastInsertId();
            }

            if ($result === 'pass' && $beltAfter !== '') {
                $currentBelt = (string)($athRow['belt'] ?? '');
                if ($currentBelt !== $beltAfter) {
                    $db->prepare("UPDATE athletes SET belt=?,belt_date=? WHERE id=? AND school_id=?")
                       ->execute([$beltAfter,date('Y-m-d'),$athleteId,$sid]);
                    if ($hasExamIdInHistory) {
                        $db->prepare("INSERT INTO belt_history (athlete_id,belt_from,belt_to,changed_at,notes,exam_id) VALUES (?,?,?,?,?,?)")
                           ->execute([$athleteId,$currentBelt,$beltAfter,date('Y-m-d'),'Εξέταση #'.$examId,$examId]);
                    } else {
                        $db->prepare("INSERT INTO belt_history (athlete_id,belt_from,belt_to,changed_at,notes) VALUES (?,?,?,?,?)")
                           ->execute([$athleteId,$currentBelt,$beltAfter,date('Y-m-d'),'Εξέταση #'.$examId]);
                    }
                }
            }

            $effectiveFee = $feeAmount ?? (float)($examRow['cost'] ?? 0);
            if ($effectiveFee > 0 && $participantId > 0) {
                beltsPageSyncExamFeeTransaction(
                    $db, 'upsert', $sid,
                    $participantId, $examId, $athleteId,
                    $effectiveFee,
                    (string)($examRow['exam_date'] ?? date('Y-m-d')),
                    (string)($athRow['full_name'] ?? ''),
                    $hasFeePaid && $feePaid === 1
                );
            } elseif ($participantId > 0) {
                beltsPageSyncExamFeeTransaction($db, 'delete', $sid, $participantId);
            }

            $db->commit();
            flash('Η συμμετοχή καταχωρήθηκε.');
        }

        if ($action === 'toggle_fee_paid') {
            $participantId = (int)($a['participant_id'] ?? 0);
            $feePaid       = isset($a['fee_paid']) ? (int)$a['fee_paid'] : 0;

            if ($hasFeePaid && $participantId > 0) {
                $db->prepare(
                    "UPDATE belt_exam_participants SET fee_paid=?
                     WHERE id=? AND exam_id IN (SELECT id FROM belt_exams WHERE school_id=?)"
                )->execute([$feePaid,$participantId,$sid]);

                $selectFee  = $hasFeeAmount ? 'p.fee_amount,' : 'NULL AS fee_amount,';
                $selectPaid = $hasFeePaid   ? 'p.fee_paid,'   : '0 AS fee_paid,';

                $rowStmt = $db->prepare(
                    "SELECT p.id,p.athlete_id,p.exam_id,{$selectFee}{$selectPaid}
                            e.exam_date,e.cost,a.full_name
                     FROM belt_exam_participants p
                     JOIN belt_exams e ON e.id=p.exam_id
                     JOIN athletes a ON a.id=p.athlete_id
                     WHERE p.id=? AND e.school_id=? LIMIT 1"
                );
                $rowStmt->execute([$participantId,$sid]);
                $row = $rowStmt->fetch(PDO::FETCH_ASSOC);

                if ($row) {
                    $effectiveFee = $row['fee_amount'] !== null ? (float)$row['fee_amount'] : (float)$row['cost'];
                    if ($effectiveFee > 0) {
                        beltsPageSyncExamFeeTransaction(
                            $db, 'upsert', $sid,
                            $participantId,
                            (int)$row['exam_id'],
                            (int)$row['athlete_id'],
                            $effectiveFee,
                            (string)$row['exam_date'],
                            (string)$row['full_name'],
                            $feePaid === 1
                        );
                    }
                }
            }

            flash($feePaid === 1 ? 'Το τέλος σημειώθηκε ως πληρωμένο.' : 'Το τέλος σημειώθηκε ως ανεξόφλητο.');
        }

        if ($action === 'delete_exam') {
            $examId = (int)($a['exam_id'] ?? 0);
            if ($examId > 0) {
                $parts = $db->prepare("SELECT id FROM belt_exam_participants WHERE exam_id=?");
                $parts->execute([$examId]);
                foreach ($parts->fetchAll(PDO::FETCH_COLUMN) as $pid) {
                    beltsPageSyncExamFeeTransaction($db, 'delete', $sid, (int)$pid);
                }
                $db->prepare("DELETE bep FROM belt_exam_participants bep
                              JOIN belt_exams be ON be.id = bep.exam_id
                              WHERE bep.exam_id=? AND be.school_id=?")->execute([$examId, $sid]);
                $db->prepare("DELETE FROM belt_exams WHERE id=? AND school_id=?")->execute([$examId, $sid]);
                flash('Η εξέταση διαγράφηκε.');
            }
        }

        if ($action === 'remove_participant') {
            $participantId = (int)($a['participant_id'] ?? 0);
            if ($participantId > 0) {
                $check = $db->prepare(
                    "SELECT p.id FROM belt_exam_participants p
                     JOIN belt_exams e ON e.id=p.exam_id
                     WHERE p.id=? AND e.school_id=? LIMIT 1"
                );
                $check->execute([$participantId, $sid]);
                if ($check->fetch()) {
                    beltsPageSyncExamFeeTransaction($db, 'delete', $sid, $participantId);
                    $db->prepare("DELETE FROM belt_exam_participants WHERE id=?")->execute([$participantId]);
                    flash('Ο αθλητής αφαιρέθηκε από την εξέταση.');
                }
            }
        }

        if ($action === 'edit_exam') {
            $id       = (int)($a['exam_id'] ?? 0);
            $examDate = (string)($a['exam_date'] ?? date('Y-m-d'));
            $cost     = (float)($a['cost'] ?? 0);
            $location = trim((string)($a['location'] ?? ''));
            $notes    = trim((string)($a['notes'] ?? ''));
            if ($id > 0) {
                $db->prepare("UPDATE belt_exams SET exam_date=?,cost=?,location=?,notes=? WHERE id=? AND school_id=?")
                   ->execute([$examDate,$cost,$location,$notes,$id,$sid]);
                flash('Η εξέταση ενημερώθηκε.');
            }
        }

        if ($action === 'update_belt') {
            $athleteId = (int)($a['athlete_id'] ?? 0);
            $newBelt   = trim((string)($a['belt'] ?? ''));
            $beltDate  = (string)($a['belt_date'] ?? date('Y-m-d'));
            $notes     = trim((string)($a['notes'] ?? 'Χειροκίνητη ενημέρωση'));
            $style     = trim((string)($a['sport_style'] ?? ''));

            $findAthSql = "SELECT belt" . ($hasSportStyle ? ",sport_style" : '')
                . " FROM athletes WHERE id=? AND school_id=? LIMIT 1";
            $findAth = $db->prepare($findAthSql);
            $findAth->execute([$athleteId,$sid]);
            $ath = $findAth->fetch(PDO::FETCH_ASSOC);

            if (!$ath) throw new RuntimeException('Ο αθλητής δεν βρέθηκε.');

            $oldBelt = (string)($ath['belt'] ?? '');

            if ($hasSportStyle) {
                $db->prepare("UPDATE athletes SET belt=?,belt_date=?,sport_style=? WHERE id=? AND school_id=?")
                   ->execute([$newBelt,$beltDate,$style!==''?$style:null,$athleteId,$sid]);
            } else {
                $db->prepare("UPDATE athletes SET belt=?,belt_date=? WHERE id=? AND school_id=?")
                   ->execute([$newBelt,$beltDate,$athleteId,$sid]);
            }

            if ($oldBelt !== $newBelt) {
                if ($hasExamIdInHistory) {
                    $db->prepare("INSERT INTO belt_history (athlete_id,belt_from,belt_to,changed_at,notes,exam_id) VALUES (?,?,?,?,?,NULL)")
                       ->execute([$athleteId,$oldBelt,$newBelt,$beltDate,$notes]);
                } else {
                    $db->prepare("INSERT INTO belt_history (athlete_id,belt_from,belt_to,changed_at,notes) VALUES (?,?,?,?,?)")
                       ->execute([$athleteId,$oldBelt,$newBelt,$beltDate,$notes]);
                }
            }

            flash('Η ζώνη ενημερώθηκε.');
        }
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        flash('Σφάλμα: '.$e->getMessage(), 'error');
    }

    $redirect = APP_URL.'/pages/belts.php?tab='.rawurlencode($tab);
    if (isset($a['exam_id']) && (int)$a['exam_id'] > 0) $redirect .= '&exam='.(int)$a['exam_id'];
    redirect($redirect);
}

$athleteSql = "SELECT id,full_name,sport,belt"
    . ($hasSportStyle ? ",sport_style" : ",'' AS sport_style")
    . " FROM athletes WHERE school_id=? AND active=1 ORDER BY full_name";
$athleteStmt = $db->prepare($athleteSql);
$athleteStmt->execute([$sid]);
$athleteListAll = $athleteStmt->fetchAll(PDO::FETCH_ASSOC);

$sumExpectedExpr  = $hasFeeAmount ? 'COALESCE(p.fee_amount,e.cost)' : 'e.cost';

$examsStmt = $db->prepare(
    "SELECT e.*,
            COUNT(p.id) AS participant_count,
            COALESCE(SUM({$sumExpectedExpr}),0) AS total_fee_expected,
            COALESCE((
                SELECT SUM(t.amount)
                FROM transactions t
                WHERE t.school_id = e.school_id
                  AND t.notes LIKE 'exam_fee:%'
                  AND t.notes NOT LIKE '%:pending%'
                  AND EXISTS (
                      SELECT 1 FROM belt_exam_participants px
                      WHERE px.exam_id = e.id
                        AND t.notes LIKE CONCAT('exam_fee:', px.id, ':%')
                  )
            ), 0) AS total_fee_collected
     FROM belt_exams e
     LEFT JOIN belt_exam_participants p ON p.exam_id=e.id
     WHERE e.school_id=?
     GROUP BY e.id
     ORDER BY e.exam_date DESC,e.id DESC"
);
$examsStmt->execute([$sid]);
$examList = $examsStmt->fetchAll(PDO::FETCH_ASSOC);

$beltStatsStmt = $db->prepare(
    "SELECT belt,COUNT(*) AS cnt FROM athletes WHERE school_id=? AND active=1
     GROUP BY belt ORDER BY cnt DESC,belt ASC"
);
$beltStatsStmt->execute([$sid]);
$beltStatsList = $beltStatsStmt->fetchAll(PDO::FETCH_ASSOC);

$totalAthletes = count($athleteListAll);
$totalExams    = count($examList);
$totalBelts    = count($beltStatsList);

$totalPassStmt = $db->prepare(
    "SELECT COUNT(*) FROM belt_exam_participants p
     JOIN belt_exams e ON e.id=p.exam_id
     WHERE e.school_id=? AND p.result='pass'"
);
$totalPassStmt->execute([$sid]);
$totalPass = (int)$totalPassStmt->fetchColumn();

$totalRevenueStmt = $db->prepare(
    "SELECT COALESCE(SUM(amount),0) FROM transactions
     WHERE school_id=? AND category='Εξετάσεις Ζωνών'
       AND notes NOT LIKE '%:pending%'"
);
$totalRevenueStmt->execute([$sid]);
$totalExamRevenue = (float)$totalRevenueStmt->fetchColumn();

// FIX: Build participant JS data with paid_amount from transactions (SUM per participant)
$examJsData  = [];
$examPartsJs = [];
foreach ($examList as $e) {
    $examJsData[(int)$e['id']] = [
        'id'           => (int)$e['id'],
        'date'         => (string)$e['exam_date'],
        'date_fmt'     => fmtD((string)$e['exam_date']),
        'location'     => (string)($e['location'] ?? ''),
        'cost'         => (float)($e['cost'] ?? 0),
        'notes'        => (string)($e['notes'] ?? ''),
        'expected'     => (float)($e['total_fee_expected'] ?? 0),
        'collected'    => (float)($e['total_fee_collected'] ?? 0),
        'participants' => (int)($e['participant_count'] ?? 0),
    ];
}

if ($examList) {
    $examIds      = array_map(static fn(array $row): int => (int)$row['id'], $examList);
    $placeholders = implode(',', array_fill(0, count($examIds), '?'));

    // FIX: include paid_amount per participant from transactions (source of truth)
    $partStmt = $db->prepare(
        "SELECT p.id, p.exam_id, p.athlete_id, p.belt_before, p.belt_after, p.result,
                " . ($hasFeeAmount ? 'p.fee_amount' : 'NULL AS fee_amount') . ",
                " . ($hasFeePaid   ? 'p.fee_paid'   : '0 AS fee_paid') . ",
                a.full_name,
                COALESCE((
                    SELECT SUM(t.amount)
                    FROM transactions t
                    WHERE t.school_id = e.school_id
                      AND t.notes LIKE CONCAT('exam_fee:', p.id, ':%')
                      AND t.notes NOT LIKE '%:pending%'
                ), 0) AS paid_amount
         FROM belt_exam_participants p
         JOIN belt_exams e ON e.id = p.exam_id
         JOIN athletes a ON a.id = p.athlete_id
         WHERE p.exam_id IN ($placeholders)
         ORDER BY a.full_name ASC"
    );
    $partStmt->execute($examIds);
    foreach ($partStmt->fetchAll(PDO::FETCH_ASSOC) as $p) {
        $examPartsJs[(int)$p['exam_id']][] = [
            'id'          => (int)$p['id'],
            'exam_id'     => (int)$p['exam_id'],
            'athlete_id'  => (int)$p['athlete_id'],
            'full_name'   => (string)$p['full_name'],
            'belt_before' => (string)($p['belt_before'] ?? ''),
            'belt_after'  => (string)($p['belt_after'] ?? ''),
            'result'      => (string)($p['result'] ?? 'pass'),
            'fee_amount'  => $p['fee_amount'] !== null ? (float)$p['fee_amount'] : null,
            'fee_paid'    => (int)($p['fee_paid'] ?? 0),
            'paid_amount' => (float)($p['paid_amount'] ?? 0), // FIX: from transactions SUM
        ];
    }
}

$allSports      = ['taekwondo_wtf','taekwondo_itf','karate_shotokan','karate_kyokushin','taekwondo','karate','judo','bjj','kickboxing','mma','boxing','wrestling','pankration','sambo','other'];
$allStylesBelts = [];
$allSportStyles = [];
foreach ($allSports as $sport) {
    $styles = beltsPageGetSportStyles($sport);
    $allSportStyles[$sport] = $styles;
    if ($styles) {
        foreach (array_keys($styles) as $styleKey) {
            $allStylesBelts[$sport][$styleKey] = beltsPageGetBeltsByStyle($sport, $styleKey);
        }
        $allStylesBelts[$sport][''] = beltsPageGetBeltsByStyle($sport, (string)array_key_first($styles));
    } else {
        $allStylesBelts[$sport][''] = beltsPageGetBeltsByStyle($sport, '');
    }
}

$csrfTok = csrf();
renderHead('Ζώνες & Εξετάσεις');
?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.2/chart.umd.min.js"></script>
<style>
input,input:hover,input:focus,select,select:hover,select:focus,textarea,textarea:hover,textarea:focus{box-shadow:none!important;-webkit-box-shadow:none!important;background-image:none!important;}
input:-webkit-autofill,input:-webkit-autofill:hover,input:-webkit-autofill:focus{-webkit-box-shadow:0 0 0 1000px #1a1f2e inset!important;-webkit-text-fill-color:var(--text,#e2e8f0)!important;}
.topbar{position:relative!important;top:auto!important;z-index:auto!important}
@media(max-width:900px){#menuBtn{display:inline-flex!important;min-width:44px!important;min-height:44px!important;align-items:center!important;justify-content:center!important;font-size:1.2rem!important;cursor:pointer!important}.sidebar{position:fixed!important;top:0!important;left:0!important;bottom:0!important;width:min(280px,80vw)!important;z-index:9999!important;transform:translateX(-110%)!important;transition:transform .28s cubic-bezier(.2,.8,.2,1)!important;overflow-y:auto}.sidebar.open{transform:translateX(0)!important;box-shadow:6px 0 40px rgba(0,0,0,.6)!important}.main-content{margin-left:0!important;width:100%!important}}
#dm-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:9998;cursor:pointer}#dm-overlay.on{display:block}
@keyframes fadeUp{from{opacity:0;transform:translateY(14px)}to{opacity:1;transform:translateY(0)}}@keyframes fadeIn{from{opacity:0}to{opacity:1}}
.page-body{animation:fadeIn .35s ease both;padding:1rem!important;overflow-x:hidden}
.anim-1{opacity:0;animation:fadeUp .42s ease-out .05s both}.anim-2{opacity:0;animation:fadeUp .42s ease-out .12s both}.anim-3{opacity:0;animation:fadeUp .42s ease-out .19s both}.anim-4{opacity:0;animation:fadeUp .42s ease-out .26s both}
@media(prefers-reduced-motion:reduce){.page-body,.anim-1,.anim-2,.anim-3,.anim-4{animation:none!important;opacity:1}}
html,body{height:auto!important;max-width:100%;overflow-x:hidden}
.stat-cards-row{display:grid;grid-template-columns:repeat(2,1fr);gap:.65rem;margin-bottom:1rem}
.stat-card{border-radius:14px;padding:.7rem .85rem;display:flex;flex-direction:row;align-items:center;gap:.65rem}
.stat-icon{width:38px;height:38px;min-width:38px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:1rem;flex-shrink:0}
.icon-blue{background:rgba(59,130,246,.15);color:#3b82f6}.icon-green{background:rgba(45,198,83,.15);color:#2dc653}.icon-gold{background:rgba(240,165,0,.15);color:#f0a500}.icon-red{background:rgba(230,57,70,.15);color:#e63946}.icon-purple{background:rgba(148,103,189,.15);color:#9467bd}
.stat-text{display:flex;flex-direction:column;gap:.05rem;min-width:0}
.stat-lbl{font-size:clamp(.72rem,1.8vw,.8rem)!important;color:var(--muted,#8892b0);font-weight:600;line-height:1.2}
.stat-val{font-size:clamp(1.1rem,2.8vw,1.5rem)!important;font-weight:800;line-height:1.1}
@media(max-width:980px){.stat-cards-row{grid-template-columns:repeat(3,1fr)}}
@media(max-width:620px){.stat-cards-row{grid-template-columns:repeat(2,1fr);gap:.5rem}}
.card{border-radius:18px;background:#0d1017!important;border:1px solid #1a2030!important}
.card-header{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.5rem;padding:.9rem 1.1rem;border-bottom:1px solid var(--border,#1e2536)}
.card-title{font-size:clamp(1rem,3.5vw,1.1rem)!important;font-weight:800;display:flex;align-items:center;gap:.45rem}
.page-header{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.75rem;margin-bottom:1rem}
.page-header h2{font-size:clamp(1.15rem,4vw,1.5rem)!important;font-weight:800;display:flex;align-items:center;gap:.5rem;margin:0}
@media(max-width:900px){.page-body{padding:1rem!important}}
@media(max-width:700px){.page-body{padding:.85rem!important}}
@media(max-width:480px){.page-body{padding:.75rem!important}.card{border-radius:14px;overflow:hidden;box-sizing:border-box;width:100%}}
.tabs{display:flex;gap:.35rem;overflow:auto;margin-bottom:1rem;border-bottom:1px solid var(--border,#1e2536);padding-bottom:.5rem}
.tabs a{padding:.55rem .9rem;border-radius:10px;text-decoration:none;font-weight:800;color:var(--muted,#8892b0);white-space:nowrap;font-size:clamp(.88rem,3vw,.95rem)!important;transition:background .15s,color .15s}
.tabs a.active{background:rgba(230,57,70,.1);color:#e63946}
.tabs a:hover:not(.active){background:rgba(255,255,255,.05);color:var(--text,#e2e8f0)}
.toolbar{display:flex;flex-wrap:wrap;gap:.55rem;align-items:center;padding:.9rem 1rem;border-bottom:1px solid var(--border,#1e2536)}
.search-bar{position:relative;flex:1 1 240px;min-width:0}
.search-bar i{position:absolute;left:.85rem;top:50%;transform:translateY(-50%);color:var(--muted,#8892b0);pointer-events:none;z-index:2;font-size:.9rem}
.search-bar input{display:block;width:100%;height:44px;padding:0 .75rem 0 2.5rem!important;border-radius:10px!important;font-size:clamp(.9rem,3.5vw,.97rem)!important;background-color:#1a1f2e!important;background-image:none!important;-webkit-appearance:none;appearance:none;border:1px solid #2a3147!important;color:var(--text,#e2e8f0);box-sizing:border-box;box-shadow:none!important;outline:none!important;}
.search-bar input:focus{border-color:#e63946!important;box-shadow:0 0 0 3px rgba(230,57,70,.18)!important;}
.filter-select-wrap{position:relative;height:44px;flex:1;min-width:130px;max-width:200px;display:flex;align-items:stretch;}
.filter-select-wrap select{width:100%;height:44px;padding:0 2rem 0 .75rem;font-size:clamp(.88rem,3.5vw,.95rem)!important;background-color:#1a1f2e!important;background-image:none!important;border:1px solid #2a3147!important;border-radius:10px;color:var(--text,#e2e8f0);-webkit-appearance:none;appearance:none;box-sizing:border-box;box-shadow:none!important;cursor:pointer;}
.filter-select-wrap select:focus{border-color:#e63946!important;box-shadow:0 0 0 3px rgba(230,57,70,.18)!important;}
.filter-select-wrap::after{content:'\f078';font-family:'Font Awesome 6 Free';font-weight:900;position:absolute;right:.65rem;top:50%;transform:translateY(-50%);color:var(--muted,#8892b0);font-size:.65rem;pointer-events:none;}
@media(max-width:700px){.toolbar{display:grid!important;grid-template-columns:1fr 1fr!important;gap:.55rem!important;}.search-bar{grid-column:1/-1!important;width:100%!important}.filter-select-wrap{width:100%!important;min-width:0!important;max-width:none!important;flex:none!important;}}
.table-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch}.table-wrap table{width:100%;border-collapse:collapse}
.table-wrap th{font-size:clamp(.76rem,2.5vw,.84rem)!important;font-weight:800;text-transform:uppercase;letter-spacing:.05em;white-space:nowrap;padding:.6rem .9rem;color:var(--muted,#8892b0);border-bottom:1px solid var(--border,#1e2536)}
.table-wrap td{font-size:clamp(.9rem,3vw,.98rem)!important;padding:.7rem .9rem;vertical-align:middle;border-bottom:1px solid rgba(255,255,255,.05)}
.table-wrap tbody tr{transition:background .15s}.table-wrap tbody tr:hover{background:rgba(255,255,255,.03)}
.btn{min-height:38px;font-size:clamp(.88rem,3vw,.95rem)!important;font-weight:700!important;display:inline-flex;align-items:center;gap:.4rem;border-radius:10px;transition:all .18s;text-decoration:none;padding:.45rem .9rem;cursor:pointer;border:none;white-space:nowrap}
.btn:active{transform:scale(.97)}
.btn-sm{min-height:34px;padding:.35rem .75rem}
.btn-primary{background:#e63946;color:#fff}.btn-primary:hover{background:#c92e3a}
.btn-secondary{background:rgba(255,255,255,.08);color:var(--text,#e2e8f0);border:1px solid var(--border,#1e2536)}
.btn-gold{background:rgba(240,165,0,.12);color:#f0a500;border:1px solid rgba(240,165,0,.28)}
.btn-blue{background:rgba(59,130,246,.12);color:#3b82f6;border:1px solid rgba(59,130,246,.28)}
.btn-purple{background:rgba(168,85,247,.12);color:#a855f7;border:1px solid rgba(168,85,247,.28)}
.btn-green{background:rgba(45,198,83,.12);color:#2dc653;border:1px solid rgba(45,198,83,.28)}
.form-control,.inp,.sel,.txt{font-size:clamp(.92rem,3.5vw,1rem)!important;min-height:44px;padding:.65rem .9rem;border-radius:10px!important;transition:border-color .2s,box-shadow .2s;width:100%;background:var(--input-bg,#0f1219);border:1px solid var(--border,#1e2536);color:var(--text,#e2e8f0)}
.form-control:focus,.inp:focus,.sel:focus,.txt:focus{outline:none;border-color:#e63946!important;box-shadow:0 0 0 3px rgba(230,57,70,.15)!important}
.txt{min-height:90px;resize:vertical}
.form-label{font-size:clamp(.92rem,3.5vw,1rem)!important;font-weight:700;display:block;margin-bottom:.4rem}
.form-hint{font-size:clamp(.76rem,2.5vw,.82rem)!important;color:var(--muted,#8892b0);margin-top:.3rem;display:flex;align-items:flex-start;gap:.3rem;line-height:1.4}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:.9rem}.form-grid .span-2{grid-column:1/-1}
@media(max-width:700px){.form-grid{grid-template-columns:1fr!important}}
.badge{display:inline-flex;align-items:center;gap:.3rem;padding:.2rem .55rem;border-radius:20px;font-size:.78rem;font-weight:700;white-space:nowrap}
.badge-ok{background:rgba(45,198,83,.12);color:#2dc653;border:1px solid rgba(45,198,83,.3)}
.badge-warn{background:rgba(240,165,0,.12);color:#f0a500;border:1px solid rgba(240,165,0,.3)}
.badge-soft{background:rgba(255,255,255,.08);color:var(--text,#e2e8f0);border:1px solid var(--border,#1e2536)}
.badge-fail{background:rgba(230,57,70,.12);color:#e63946;border:1px solid rgba(230,57,70,.3)}
.badge-partial{background:rgba(59,130,246,.12);color:#3b82f6;border:1px solid rgba(59,130,246,.3)}
.belt-form{display:flex;gap:.4rem;flex-wrap:wrap;align-items:center}
.belt-form .sel,.belt-form .inp{font-size:clamp(.84rem,3vw,.9rem)!important;min-height:36px;padding:.4rem .65rem;border-radius:8px}
.belt-form .style-sel{max-width:190px}.belt-form .belt-sel{max-width:250px}.belt-form .date-sel{max-width:150px}
.style-badge{display:inline-flex;align-items:center;gap:.35rem;padding:.22rem .55rem;border-radius:20px;font-size:.72rem;font-weight:800;background:rgba(168,85,247,.12);color:#a855f7;border:1px solid rgba(168,85,247,.25)}
.modal-backdrop{display:none;position:fixed;inset:0;background:rgba(0,0,0,.72);backdrop-filter:blur(6px);z-index:10500;align-items:center;justify-content:center;padding:1rem}
.modal-backdrop.open{display:flex}
.modal-box{
    width:100%;max-width:640px;max-height:92vh;overflow:auto;
    background:var(--card-bg,#131929);border:1.5px solid var(--border,#1e2536);
    border-radius:22px;box-shadow:0 24px 80px rgba(0,0,0,.6);
    animation:fadeUp .28s ease both;
    scrollbar-width:thin;
    scrollbar-color:#e63946 rgba(255,255,255,0.1);
}
.modal-box::-webkit-scrollbar{width:8px;height:8px}
.modal-box::-webkit-scrollbar-track{background:rgba(255,255,255,0.1);border-radius:10px}
.modal-box::-webkit-scrollbar-thumb{background:#e63946;border-radius:10px}
.modal-box::-webkit-scrollbar-thumb:hover{background:#ff6b7a}
.modal-head{display:flex;align-items:center;justify-content:space-between;gap:.75rem;padding:1.1rem 1.25rem;border-bottom:1px solid var(--border,#1e2536)}
.modal-title{font-size:1.05rem;font-weight:800;display:flex;align-items:center;gap:.55rem}
.modal-body{padding:1.1rem 1.25rem}
.modal-foot{padding:1rem 1.25rem;border-top:1px solid var(--border,#1e2536);display:flex;justify-content:flex-end;gap:.6rem;flex-wrap:wrap}
.summary-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:.6rem;padding:1rem;border-bottom:1px solid var(--border,#1e2536)}
@media(max-width:700px){.summary-grid{grid-template-columns:1fr 1fr}}
.summary-card{background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.08);border-radius:14px;padding:.8rem;text-align:center}
.summary-card .k{font-size:.72rem;text-transform:uppercase;letter-spacing:.04em;color:var(--muted,#8892b0);font-weight:800;margin-bottom:.25rem}
.summary-card .v{font-size:1.05rem;font-weight:900}
.two-col{display:grid;grid-template-columns:1.1fr .9fr;gap:1rem}
@media(max-width:900px){.two-col{grid-template-columns:1fr}}
.chart-container{max-width:100%;margin:0 auto;padding:0 0.5rem}
.chart-container canvas{max-width:100%;height:auto;margin:0 auto}
@media (max-width: 600px){.chart-container{max-width:280px}}
.nav-item,.sidebar .nav-item,.sidebar a.nav-item{min-height:46px!important;font-size:clamp(.92rem,3vw,1rem)!important;font-weight:600!important;padding:.65rem .9rem!important;border-radius:10px!important;display:flex!important;align-items:center!important;gap:.7rem!important;transition:background .15s,color .15s!important;text-decoration:none!important}
.nav-item .icon,.sidebar .nav-item .icon{width:22px!important;text-align:center!important;font-size:1rem!important;flex-shrink:0!important}
.sidebar .nav-item span,.sidebar a.nav-item span{font-size:clamp(.92rem,3vw,1rem)!important;font-weight:600!important}
.sidebar-school,.sidebar .sidebar-school{margin:.25rem 1rem!important;padding:0!important;display:flex!important;align-items:center!important;font-weight:700!important;font-size:clamp(.82rem,3vw,.92rem)!important;color:var(--text,#f0f2ff)!important;white-space:normal!important;overflow:visible!important;overflow-wrap:anywhere!important;background:none!important;border:none!important;box-shadow:none!important}
.sidebar .nav-section-label,.nav-section-label{font-size:.72rem!important;font-weight:800!important;text-transform:uppercase!important;letter-spacing:.08em!important;color:var(--muted,#8892b0)!important}
.center-empty{padding:2rem 1rem;text-align:center;color:var(--muted,#8892b0);font-size:clamp(.9rem,3vw,.98rem)!important}
.muted{color:var(--muted,#8892b0)}
.table-wrap table,.table-wrap th,.table-wrap td{border:1px solid rgba(255,255,255,0.1);border-collapse:collapse}
.table-wrap th{background:rgba(0,0,0,0.2);font-weight:800}
.table-wrap td{background:transparent}
.action-btn-text span{display:none}
@media(max-width:700px){
    .action-btn-text span{display:inline}
}

@media(max-width:700px){
    .exam-actions-wrap{
        display:grid!important;
        grid-template-columns:1fr 1fr!important;
        gap:.35rem!important;
    }
    .exam-actions-wrap .btn{
        justify-content:center!important;
        font-size:.78rem!important;
        padding:.4rem .5rem!important;
        min-height:36px!important;
    }
    .exam-actions-wrap .btn-delete-exam{
        grid-column:auto!important;
    }
}
/* fee sub-line in payments modal */
.fee-sub{font-size:.76rem;margin-top:.18rem;display:flex;align-items:center;gap:.25rem;font-weight:600}
.fee-sub.paid{color:#2dc653}.fee-sub.partial{color:#3b82f6}.fee-sub.pending{color:#f0a500}
</style>
<body>
<div class="app-layout">
<?php renderSidebar('belts'); ?>
<div id="dm-overlay"></div>
<div class="main-content">
<?php renderTopbar('Ζώνες &amp; Εξετάσεις'); ?>
<div class="page-body">

<div class="stat-cards-row anim-1">
    <div class="stat-card card"><div class="stat-icon icon-red"><i class="fa-solid fa-clipboard-check"></i></div><div class="stat-text"><div class="stat-lbl">Εξετάσεις</div><div class="stat-val"><?= $totalExams ?></div></div></div>
    <div class="stat-card card"><div class="stat-icon icon-purple"><i class="fa-solid fa-euro-sign"></i></div><div class="stat-text"><div class="stat-lbl">Έσοδα εξετάσεων</div><div class="stat-val" style="font-size:clamp(1.1rem,3vw,1.6rem)!important"><?= h(number_format($totalExamRevenue,0,',','.')) ?>€</div></div></div>
</div>

<div class="tabs anim-2">
    <a href="?tab=belts" class="<?= $tab === 'belts' ? 'active' : '' ?>"><i class="fa-solid fa-ribbon"></i> Ζώνες</a>
    <a href="?tab=exams" class="<?= $tab === 'exams' ? 'active' : '' ?>"><i class="fa-solid fa-clipboard-check"></i> Εξετάσεις</a>
    <a href="?tab=stats" class="<?= $tab === 'stats' ? 'active' : '' ?>"><i class="fa-solid fa-chart-pie"></i> Στατιστικά</a>
</div>

<?php if ($tab === 'belts'): ?>
<div class="card anim-3">
    <div class="card-header">
        <div class="card-title"><i class="fa-solid fa-ribbon" style="color:#f0a500"></i> Ζώνες αθλητών</div>
        <div style="font-size:clamp(.82rem,2vw,.9rem);color:var(--muted,#8892b0)" id="beltCountLabel"><?= $totalAthletes ?> αθλητές</div>
    </div>
    <div class="toolbar">
        <div class="search-bar"><i class="fa-solid fa-magnifying-glass"></i><input type="search" id="beltSearch" placeholder="Αναζήτηση αθλητή..."></div>
        <div class="filter-select-wrap" style="max-width:220px">
            <select id="beltSportFilter">
                <option value="">Όλα τα αθλήματα</option>
                <optgroup label="Taekwondo">
                    <option value="taekwondo_wtf">Taekwondo (WTF/WT)</option>
                    <option value="taekwondo_itf">Taekwondo (ITF)</option>
                </optgroup>
                <optgroup label="Karate">
                    <option value="karate_shotokan">Karate Shotokan</option>
                    <option value="karate_kyokushin">Karate Kyokushin</option>
                </optgroup>
                <optgroup label="Πολεμικές Τέχνες">
                    <option value="bjj">BJJ</option>
                    <option value="judo">Judo</option>
                    <option value="kickboxing">Kickboxing</option>
                    <option value="boxing">Boxing</option>
                    <option value="mma">MMA</option>
                    <option value="wrestling">Πάλη</option>
                    <option value="pankration">Παγκράτιο</option>
                    <option value="sambo">Sambo</option>
                </optgroup>
                <option value="other">Άλλο</option>
            </select>
        </div>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr><th>Αθλητής</th><th>Άθλημα</th><th>Στυλ</th><th>Τρέχουσα ζώνη</th><th>Αλλαγή</th></tr>
            </thead>
            <tbody id="beltRows">
            <?php foreach ($athleteListAll as $ath):
                $sport        = (string)($ath['sport'] ?? 'other');
                $style        = (string)($ath['sport_style'] ?? '');
                $styles       = beltsPageGetSportStyles($sport);
                $styleLabel   = $styles[$style] ?? '';
                $currentBelts = beltsPageGetBeltsByStyle($sport, $style);
            ?>
                <tr class="belt-row" data-name="<?= h(mb_strtolower((string)$ath['full_name'])) ?>" data-sport="<?= h($sport) ?>">
                    <td><a href="<?= APP_URL ?>/pages/athletes.php?view=<?= (int)$ath['id'] ?>" style="font-weight:800;text-decoration:none;color:var(--text,#e2e8f0)"><?= h($ath['full_name']) ?></a></td>
                    <td style="color:var(--muted,#8892b0)"><?= h(sportLabelLocal($sport)) ?></td>
                    <td><?php if ($styleLabel !== ''): ?><span class="style-badge"><i class="fa-solid fa-shield-halved"></i><?= h($styleLabel) ?></span><?php else: ?><span style="color:var(--muted,#8892b0)">—</span><?php endif; ?></td>
                    <td><strong><?= h((string)($ath['belt'] ?? '—')) ?></strong></td>
                    <td>
                        <?php if (beltsPageSportHasBelts($sport)): ?>
                        <form method="POST" class="belt-form">
                            <input type="hidden" name="_action" value="update_belt">
                            <input type="hidden" name="csrf_token" value="<?= h($csrfTok) ?>">
                            <input type="hidden" name="athlete_id" value="<?= (int)$ath['id'] ?>">
                            <?php if ($hasSportStyle && $styles): ?>
                                <select name="sport_style" class="sel style-sel js-style-select" data-sport="<?= h($sport) ?>" data-target="belt_<?= (int)$ath['id'] ?>">
                                    <option value="">— Στυλ —</option>
                                    <?php foreach ($styles as $sv=>$sl): ?>
                                        <option value="<?= h($sv) ?>" <?= $style === $sv ? 'selected' : '' ?>><?= h($sl) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            <?php elseif ($hasSportStyle): ?>
                                <input type="hidden" name="sport_style" value="">
                            <?php endif; ?>
                            <select name="belt" id="belt_<?= (int)$ath['id'] ?>" class="sel belt-sel" data-sport="<?= h($sport) ?>">
                                <?php foreach ($currentBelts as $belt): ?>
                                    <option value="<?= h($belt) ?>" <?= ((string)($ath['belt'] ?? '') === $belt) ? 'selected' : '' ?>><?= h($belt) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <input type="hidden" name="belt_date" value="<?= h(date('Y-m-d')) ?>">
                            <button type="submit" class="btn btn-secondary btn-sm" title="Αποθήκευση"><i class="fa-solid fa-floppy-disk"></i> Αποθήκευση</button>
                        </form>
                        <?php else: ?>
                            <span style="color:var(--muted,#8892b0);font-size:.88rem">Δεν εφαρμόζεται</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php if (!$athleteListAll): ?>
        <div class="center-empty"><div style="font-size:2rem;margin-bottom:.5rem;opacity:.35"><i class="fa-solid fa-ribbon"></i></div>Δεν υπάρχουν ενεργοί αθλητές.</div>
        <?php endif; ?>
    </div>
    <div id="beltEmpty" class="center-empty" style="display:none">Δεν βρέθηκαν αθλητές.</div>
</div>
<?php endif; ?>

<?php if ($tab === 'exams'): ?>
<div class="page-header anim-3">
    <h2><i class="fa-solid fa-clipboard-check" style="color:#e63946"></i> Εξετάσεις <span style="font-size:clamp(.82rem,3vw,.9rem);font-weight:600;color:var(--muted,#8892b0);margin-left:.4rem">(<?= $totalExams ?>)</span></h2>
    <button type="button" class="btn btn-primary" style="font-size:clamp(.95rem,3vw,1.05rem)!important;font-weight:800!important;padding:.65rem 1.4rem!important;min-height:44px!important;border-radius:12px!important" data-open="examModal"><i class="fa-solid fa-plus"></i> Νέα εξέταση</button>
</div>
<div class="card anim-3">
    <div class="toolbar">
        <div class="search-bar"><i class="fa-solid fa-magnifying-glass"></i><input type="search" id="examSearch" placeholder="Αναζήτηση με ημερομηνία ή τοποθεσία..."></div>
    </div>
    <div class="table-wrap">
         <table>
            <thead>
                 <tr><th>Εξέταση</th><th>Αθλητές</th><th>Οικονομικά</th><th>Ενέργειες</th></tr>
            </thead>
            <tbody id="examRows">
            <?php if (!$examList): ?>
                 <tr><td colspan="4"><div class="center-empty"><div style="font-size:2rem;margin-bottom:.5rem;opacity:.35"><i class="fa-solid fa-clipboard-check"></i></div>Δεν υπάρχουν εξετάσεις ακόμα.</div></td></tr>
            <?php endif; ?>
            <?php foreach ($examList as $exam):
                $pending = (float)$exam['total_fee_expected'] - (float)$exam['total_fee_collected'];
            ?>
                <tr class="exam-row" data-q="<?= h(mb_strtolower((string)$exam['exam_date'].' '.(string)($exam['location'] ?? ''))) ?>">
                    <td>
                        <div style="font-weight:800"><?= fmtD((string)$exam['exam_date']) ?></div>
                        <?php if (!empty($exam['location'])): ?><div style="font-size:clamp(.75rem,2.5vw,.8rem)!important;color:var(--muted,#8892b0);margin-top:.1rem"><i class="fa-solid fa-location-dot"></i> <?= h((string)$exam['location']) ?></div><?php endif; ?>
                    </td>
                    <td><span class="badge badge-soft"><i class="fa-solid fa-users"></i> <?= (int)$exam['participant_count'] ?></span></td>
                    <td>
                        <?php if ((int)$exam['participant_count'] === 0): ?>
                            <span style="color:var(--muted,#8892b0)">—</span>
                        <?php elseif ($pending > 0.0001): ?>
                            <span class="badge badge-warn"><i class="fa-solid fa-clock"></i> <?= formatMoney($pending) ?> εκκρ.</span>
                        <?php else: ?>
                            <span class="badge badge-ok"><i class="fa-solid fa-check"></i> Εξοφλημένο</span>
                        <?php endif; ?>
                    </td>
<td>
                        <div class="exam-actions-wrap" style="display:flex;gap:.35rem;flex-wrap:wrap">
                            <button type="button" class="btn btn-blue btn-sm" onclick="openAddParticipant(<?= (int)$exam['id'] ?>)"><i class="fa-solid fa-user-plus"></i> Αθλητής</button>
                            <button type="button" class="btn btn-purple btn-sm" onclick="openStats(<?= (int)$exam['id'] ?>)"><i class="fa-solid fa-chart-pie"></i> Στατιστικά</button>
                            <button type="button" class="btn btn-secondary btn-sm" onclick="openEditExam(<?= (int)$exam['id'] ?>)">
                                <i class="fa-solid fa-pen"></i> Επεξεργασία
                            </button>
                            <button type="button" class="btn btn-sm btn-delete-exam" style="background:rgba(230,57,70,.12);color:#e63946;border:1px solid rgba(230,57,70,.3)" onclick="confirmDeleteExam(<?= (int)$exam['id'] ?>, '<?= h(fmtD((string)$exam['exam_date'])) ?>')">
                                <i class="fa-solid fa-trash"></i> Διαγραφή
                            </button>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php if ($tab === 'stats'): ?>
<div class="two-col anim-3">
    <div class="card">
        <div class="card-header"><div class="card-title"><i class="fa-solid fa-chart-pie" style="color:#e63946"></i> Κατανομή ζωνών</div></div>
        <div class="chart-container" style="padding:1rem"><canvas id="beltChart" height="260"></canvas></div>
    </div>
    <div class="card">
        <div class="card-header"><div class="card-title"><i class="fa-solid fa-list" style="color:#f0a500"></i> Αναλυτικά</div></div>
        <div class="table-wrap">
            <table>
                <thead>
                     <tr><th>#</th><th>Ζώνη</th><th>Αθλητές</th></tr>
                </thead>
                <tbody>
                <?php if (!$beltStatsList): ?> <tr><td colspan="3"><div class="center-empty">Δεν υπάρχουν δεδομένα.</div></td></tr><?php endif; ?>
                <?php foreach ($beltStatsList as $i=>$row): ?>
                     <tr><td style="color:var(--muted,#8892b0);font-weight:700"><?= (int)$i + 1 ?></td><td><strong><?= h((string)$row['belt']) ?></strong></td><td><span class="badge badge-soft"><?= (int)$row['cnt'] ?> αθλητές</span></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

</div></div></div>

<!-- EXAM MODAL -->
<div class="modal-backdrop" id="examModal">
    <div class="modal-box">
        <div class="modal-head">
            <div class="modal-title"><i class="fa-solid fa-clipboard-check" style="color:#2dc653"></i> Νέα εξέταση</div>
            <button type="button" class="btn btn-secondary btn-sm" data-close="examModal"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form method="POST">
            <input type="hidden" name="_action" value="save_exam">
            <input type="hidden" name="csrf_token" value="<?= h($csrfTok) ?>">
            <div class="modal-body">
                <div class="form-grid">
                    <div><label class="form-label">Ημερομηνία</label><input type="date" name="exam_date" class="inp" required value="<?= h(date('Y-m-d')) ?>"></div>
                    <div><label class="form-label">Τέλος / αθλητή (€)</label><input type="number" step="0.01" min="0" name="cost" class="inp" value="0"></div>
                    <div class="span-2"><label class="form-label">Τοποθεσία</label><input type="text" name="location" class="inp" placeholder="π.χ. Αθήνα"></div>
                    <div class="span-2"><label class="form-label">Σημειώσεις</label><textarea name="notes" class="txt"></textarea></div>
                </div>
            </div>
            <div class="modal-foot">
                <button type="button" class="btn btn-secondary" data-close="examModal"><i class="fa-solid fa-xmark"></i> Ακύρωση</button>
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i> Δημιουργία</button>
            </div>
        </form>
    </div>
</div>

<!-- ADD PARTICIPANT MODAL -->
<div class="modal-backdrop" id="addParticipantModal">
    <div class="modal-box">
        <div class="modal-head">
            <div class="modal-title"><i class="fa-solid fa-user-plus" style="color:#3b82f6"></i> Προσθήκη αθλητή</div>
            <button type="button" class="btn btn-secondary btn-sm" data-close="addParticipantModal"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form method="POST">
            <input type="hidden" name="_action" value="add_participant">
            <input type="hidden" name="csrf_token" value="<?= h($csrfTok) ?>">
            <input type="hidden" name="exam_id" id="ap_exam_id">
            <input type="hidden" name="result" value="pass">
            <div class="modal-body">
                <div id="ap_exam_label" style="font-size:clamp(.84rem,3vw,.9rem)!important;color:var(--muted,#8892b0);margin-bottom:.75rem"></div>
                <div style="background:rgba(240,165,0,.07);border:1px solid rgba(240,165,0,.28);border-radius:12px;padding:.65rem 1rem;margin-bottom:1rem;font-size:.85rem;color:#c8a84b;display:flex;align-items:flex-start;gap:.5rem;line-height:1.5">
                    <i class="fa-solid fa-circle-info" style="color:#f0a500;flex-shrink:0;margin-top:.1rem"></i>
                    <span>Το τέλος εξέτασης θα καταχωρηθεί αυτόματα σαν <strong style="color:#f0a500">εκκρεμής πληρωμή</strong> στις Πληρωμές και στην καρτέλα του αθλητή. Τσεκάρετε «Πληρωμένο» αν εξοφλήθηκε επί τόπου.</span>
                </div>
                <div class="form-grid">
                    <div class="span-2">
                        <label class="form-label">Αθλητής</label>
                        <select name="athlete_id" id="ap_athlete_id" class="sel" required>
                            <option value="">— Επιλέξτε αθλητή —</option>
                            <?php foreach ($athleteListAll as $ath): ?>
                                <option value="<?= (int)$ath['id'] ?>"
                                    data-name="<?= h((string)$ath['full_name']) ?>"
                                    data-sport="<?= h((string)$ath['sport']) ?>"
                                    data-style="<?= h((string)($ath['sport_style'] ?? '')) ?>"
                                    data-belt="<?= h((string)($ath['belt'] ?? '')) ?>"
                                ><?= h((string)$ath['full_name']) ?> — <?= h(sportLabelLocal((string)$ath['sport'])) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php if ($hasSportStyle): ?>
                    <div class="span-2" id="ap_style_wrap" style="display:none">
                        <label class="form-label">Στυλ / Οργανισμός</label>
                        <select id="ap_style" class="sel"></select>
                    </div>
                    <?php endif; ?>
                    <div>
                        <label class="form-label">Ζώνη από</label>
                        <select name="belt_before" id="ap_belt_before" class="sel" required>
                            <option value="">— Επιλέξτε αθλητή —</option>
                        </select>
                    </div>
                    <div>
                        <label class="form-label">Ζώνη σε</label>
                        <select name="belt_after" id="ap_belt_after" class="sel" required>
                            <option value="">— Επιλέξτε αθλητή —</option>
                        </select>
                    </div>
                    <?php if ($hasFeeAmount): ?>
                    <div class="span-2">
                        <label class="form-label">Τέλος εξέτασης (€)</label>
                        <input type="number" step="0.01" min="0" name="fee_amount" id="ap_fee_amount" class="inp" value="0">
                    </div>
                    <?php endif; ?>
                    <?php if ($hasFeePaid): ?>
                    <div class="span-2" style="display:flex;align-items:center;gap:.7rem;padding:.8rem 1rem;border:1px solid rgba(45,198,83,.2);border-radius:12px;background:rgba(45,198,83,.06)">
                        <input type="checkbox" name="fee_paid" id="ap_fee_paid" value="1" style="width:18px;height:18px;accent-color:#2dc653;flex-shrink:0">
                        <label for="ap_fee_paid" style="font-weight:800;color:#2dc653;font-size:clamp(.9rem,3vw,.97rem)!important;cursor:pointer"><i class="fa-solid fa-check-circle" style="margin-right:.3rem"></i>Το τέλος έχει ήδη πληρωθεί</label>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="modal-foot">
                <button type="button" class="btn btn-secondary" data-close="addParticipantModal"><i class="fa-solid fa-xmark"></i> Ακύρωση</button>
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i> Καταχώρηση</button>
            </div>
        </form>
    </div>
</div>

<!-- EXAM INFO MODAL -->
<div class="modal-backdrop" id="examInfoModal">
    <div class="modal-box">
        <div class="modal-head">
            <div class="modal-title"><i class="fa-solid fa-money-bill-wave" style="color:#f0a500"></i> <span id="examInfoTitle">Πληρωμές εξέτασης</span></div>
            <button type="button" class="btn btn-secondary btn-sm" data-close="examInfoModal"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div id="examInfoContent"></div>
    </div>
</div>

<!-- STATS MODAL -->
<div class="modal-backdrop" id="statsModal">
    <div class="modal-box" style="max-width:560px">
        <div class="modal-head">
            <div class="modal-title"><i class="fa-solid fa-chart-pie" style="color:#a855f7"></i> <span id="statsTitle">Στατιστικά</span></div>
            <button type="button" class="btn btn-secondary btn-sm" data-close="statsModal"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-body" id="statsBody"></div>
    </div>
</div>

<!-- EDIT EXAM MODAL -->
<div class="modal-backdrop" id="editExamModal">
    <div class="modal-box">
        <div class="modal-head">
            <div class="modal-title"><i class="fa-solid fa-pen" style="color:#f0a500"></i> Επεξεργασία Εξέτασης</div>
            <button type="button" class="btn btn-secondary btn-sm" data-close="editExamModal"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form method="POST">
            <input type="hidden" name="_action" value="edit_exam">
            <input type="hidden" name="csrf_token" value="<?= h($csrfTok) ?>">
            <input type="hidden" name="exam_id" id="editExamId">
            <div class="modal-body">
                <div class="form-grid">
                    <div><label class="form-label">Ημερομηνία</label><input type="date" name="exam_date" id="editExamDate" class="inp" required></div>
                    <div><label class="form-label">Τέλος / αθλητή (€)</label><input type="number" step="0.01" min="0" name="cost" id="editExamCost" class="inp" value="0"></div>
                    <div class="span-2"><label class="form-label">Τοποθεσία</label><input type="text" name="location" id="editExamLocation" class="inp" placeholder="π.χ. Αθήνα"></div>
                    <div class="span-2"><label class="form-label">Σημειώσεις</label><textarea name="notes" id="editExamNotes" class="txt"></textarea></div>
                </div>
            </div>
            <div class="modal-foot">
                <button type="button" class="btn btn-secondary" data-close="editExamModal"><i class="fa-solid fa-xmark"></i> Ακύρωση</button>
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i> Αποθήκευση</button>
            </div>
        </form>
    </div>
</div>

<!-- DELETE EXAM CONFIRM MODAL -->
<div class="modal-backdrop" id="deleteExamModal">
    <div class="modal-box" style="max-width:420px">
        <div class="modal-head">
            <div class="modal-title" style="color:#e63946"><i class="fa-solid fa-trash"></i> Διαγραφή Εξέτασης</div>
            <button type="button" class="btn btn-secondary btn-sm" data-close="deleteExamModal"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-body">
            <p style="margin:0 0 .5rem;font-size:.97rem">Να διαγραφεί η εξέταση <strong id="deleteExamLabel"></strong>;</p>
            <p style="margin:0;font-size:.85rem;color:#e63946;font-weight:700"><i class="fa-solid fa-triangle-exclamation"></i> Θα διαγραφούν και όλοι οι συμμετέχοντες και οι σχετικές πληρωμές.</p>
        </div>
        <div class="modal-foot">
            <button type="button" class="btn btn-secondary" data-close="deleteExamModal"><i class="fa-solid fa-xmark"></i> Ακύρωση</button>
            <form method="POST" style="display:inline">
                <input type="hidden" name="_action" value="delete_exam">
                <input type="hidden" name="csrf_token" value="<?= h($csrfTok) ?>">
                <input type="hidden" name="exam_id" id="deleteExamIdInput">
                <button type="submit" class="btn" style="background:#e63946;color:#fff"><i class="fa-solid fa-trash"></i> Διαγραφή</button>
            </form>
        </div>
    </div>
</div>

<!-- REMOVE PARTICIPANT CONFIRM MODAL -->
<div class="modal-backdrop" id="removePartModal">
    <div class="modal-box" style="max-width:420px">
        <div class="modal-head">
            <div class="modal-title" style="color:#e63946"><i class="fa-solid fa-user-minus"></i> Αφαίρεση Αθλητή</div>
            <button type="button" class="btn btn-secondary btn-sm" data-close="removePartModal"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-body">
            <p style="margin:0 0 .5rem;font-size:.97rem">Αφαίρεση <strong id="removePartLabel"></strong> από την εξέταση;</p>
            <p style="margin:0;font-size:.85rem;color:#f0a500;font-weight:700"><i class="fa-solid fa-triangle-exclamation"></i> Τυχόν εκκρεμές τέλος εξέτασης θα διαγραφεί.</p>
        </div>
        <div class="modal-foot">
            <button type="button" class="btn btn-secondary" data-close="removePartModal"><i class="fa-solid fa-xmark"></i> Ακύρωση</button>
            <form method="POST" style="display:inline">
                <input type="hidden" name="_action" value="remove_participant">
                <input type="hidden" name="csrf_token" value="<?= h($csrfTok) ?>">
                <input type="hidden" name="participant_id" id="removePartIdInput">
                <button type="submit" class="btn" style="background:#e63946;color:#fff"><i class="fa-solid fa-user-minus"></i> Αφαίρεση</button>
            </form>
        </div>
    </div>
</div>

<script>
(function(){
    var sb=document.getElementById('sidebar'),ov=document.getElementById('dm-overlay'),mb=document.getElementById('menuBtn');
    if(!sb||!mb)return;
    function open(){sb.classList.add('open');ov&&ov.classList.add('on');document.body.style.overflow='hidden';}
    function close(){sb.classList.remove('open');ov&&ov.classList.remove('on');document.body.style.overflow='';}
    mb.onclick=function(e){e.stopPropagation();sb.classList.contains('open')?close():open();};
    ov&&ov.addEventListener('click',close);
    sb.querySelectorAll('a.nav-item').forEach(function(l){l.addEventListener('click',function(){if(window.innerWidth<=900)setTimeout(close,80);});});
    document.addEventListener('keydown',function(e){if(e.key==='Escape')close();});
    window.addEventListener('resize',function(){if(window.innerWidth>900){sb.classList.remove('open');ov&&ov.classList.remove('on');document.body.style.overflow='';}});
})();
</script>

<script>
const EXAM_DATA         = <?= json_encode($examJsData,   JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;
const EXAM_PARTICIPANTS = <?= json_encode($examPartsJs,   JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;
const BELTS_BY_STYLE    = <?= json_encode($allStylesBelts,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;
const SPORT_STYLES      = <?= json_encode($allSportStyles,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;
const HAS_SPORT_STYLE   = <?= json_encode($hasSportStyle) ?>;
const HAS_FEE_AMOUNT    = <?= json_encode($hasFeeAmount) ?>;
const HAS_FEE_PAID      = <?= json_encode($hasFeePaid) ?>;
const CSRF              = <?= json_encode($csrfTok, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;
const APP_URL_JS        = <?= json_encode(APP_URL,  JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;

function esc(s){return String(s??'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}
function money(v){return Number(v||0).toLocaleString('el-GR',{minimumFractionDigits:2,maximumFractionDigits:2})+'€';}

function beltsFor(sport,style){
    if(!BELTS_BY_STYLE[sport])return[];
    if(style&&BELTS_BY_STYLE[sport][style])return BELTS_BY_STYLE[sport][style];
    if(BELTS_BY_STYLE[sport][''])return BELTS_BY_STYLE[sport][''];
    const keys=Object.keys(BELTS_BY_STYLE[sport]);
    return keys.length?BELTS_BY_STYLE[sport][keys[0]]:[];
}

function getNextBeltValue(belts,currentBelt){
    if(!Array.isArray(belts)||!belts.length)return'';
    const cur=String(currentBelt||'').trim();
    const idx=belts.findIndex(v=>String(v).trim()===cur);
    if(idx===-1)return belts[0];
    if(idx<belts.length-1)return belts[idx+1];
    return belts[idx];
}

function fillBeltSelect(selectEl,belts,selectedValue,emptyLabel='— Δεν υπάρχουν ζώνες —'){
    if(!selectEl)return;
    if(!Array.isArray(belts)||!belts.length){selectEl.innerHTML=`<option value="">${emptyLabel}</option>`;return;}
    const wanted=String(selectedValue||'').trim();
    selectEl.innerHTML=belts.map(v=>{const val=String(v||'').trim();return`<option value="${esc(val)}"${val===wanted?' selected':''}>${esc(val)}</option>`;}).join('');
    if(selectEl.value!==wanted)selectEl.value=wanted||belts[0];
}

function rebuildParticipantBelts(){
    const athleteSel=document.getElementById('ap_athlete_id');
    const opt=athleteSel?athleteSel.options[athleteSel.selectedIndex]:null;
    if(!opt||!opt.value)return;
    const sport=opt.dataset.sport||'other';
    const athleteStyle=opt.dataset.style||'';
    const currentBelt=String(opt.dataset.belt||'').trim();
    let appliedStyle=athleteStyle;
    if(HAS_SPORT_STYLE){
        const styleWrap=document.getElementById('ap_style_wrap');
        const styleSel=document.getElementById('ap_style');
        if(styleWrap&&styleSel&&styleWrap.style.display!=='none')appliedStyle=styleSel.value||athleteStyle||'';
    }
    const belts=beltsFor(sport,appliedStyle);
    const nextBelt=getNextBeltValue(belts,currentBelt);
    const beforeSel=document.getElementById('ap_belt_before');
    const afterSel=document.getElementById('ap_belt_after');
    fillBeltSelect(beforeSel,belts,currentBelt||(belts[0]||''));
    fillBeltSelect(afterSel,belts,nextBelt||(belts[0]||''));
    if(!currentBelt&&belts.length){beforeSel.value=belts[0];afterSel.value=belts.length>1?belts[1]:belts[0];}
}

function openModal(id){const el=document.getElementById(id);if(el){el.classList.add('open');document.body.style.overflow='hidden';}}
function closeModal(id){const el=document.getElementById(id);if(el){el.classList.remove('open');document.body.style.overflow='';}}

document.addEventListener('click',function(e){
    const openBtn=e.target.closest('[data-open]');
    if(openBtn)openModal(openBtn.dataset.open);
    const closeBtn=e.target.closest('[data-close]');
    if(closeBtn)closeModal(closeBtn.dataset.close);
    if(e.target.classList.contains('modal-backdrop'))closeModal(e.target.id);
});
document.addEventListener('keydown',function(e){
    if(e.key==='Escape')document.querySelectorAll('.modal-backdrop.open').forEach(m=>closeModal(m.id));
});

const beltSearch=document.getElementById('beltSearch');
const beltSportFilter=document.getElementById('beltSportFilter');
if(beltSearch&&beltSportFilter){
    const rows=Array.from(document.querySelectorAll('.belt-row'));
    const empty=document.getElementById('beltEmpty');
    const count=document.getElementById('beltCountLabel');
    const filterBelts=()=>{
        const q=beltSearch.value.trim().toLowerCase();
        const sport=beltSportFilter.value;
        let visible=0;
        rows.forEach(row=>{const ok=(!q||row.dataset.name.includes(q))&&(!sport||row.dataset.sport===sport);row.style.display=ok?'':'none';if(ok)visible++;});
        if(empty)empty.style.display=visible?'none':'block';
        if(count)count.textContent=visible+' αθλητές';
    };
    beltSearch.addEventListener('input',filterBelts);
    beltSearch.addEventListener('keydown',function(e){
        if(e.key==='Enter'){
            e.preventDefault();
            beltSearch.blur();
        }
    });
    beltSportFilter.addEventListener('change',filterBelts);
}

const examSearch=document.getElementById('examSearch');
if(examSearch){
    examSearch.addEventListener('input',function(){
        const q=this.value.trim().toLowerCase();
        document.querySelectorAll('.exam-row').forEach(row=>{row.style.display=!q||row.dataset.q.includes(q)?'':'none';});
    });
    examSearch.addEventListener('keydown',function(e){
        if(e.key==='Enter'){
            e.preventDefault();
            examSearch.blur();
        }
    });
}

document.querySelectorAll('.js-style-select').forEach(sel=>{
    sel.addEventListener('change',function(){
        const sport=this.dataset.sport;
        const target=document.getElementById(this.dataset.target);
        if(!target)return;
        const values=beltsFor(sport,this.value);
        const current=target.value;
        target.innerHTML=values.map(v=>`<option value="${esc(v)}"${v===current?' selected':''}>${esc(v)}</option>`).join('');
    });
});

function openAddParticipant(id){
    const exam=EXAM_DATA[id];
    if(!exam)return;
    document.getElementById('ap_exam_id').value=id;
    document.getElementById('ap_exam_label').textContent=exam.date_fmt+(exam.location?' — '+exam.location:'');
    const athleteSel=document.getElementById('ap_athlete_id');
    athleteSel.value='';
    document.getElementById('ap_belt_before').innerHTML='<option value="">— Επιλέξτε αθλητή —</option>';
    document.getElementById('ap_belt_after').innerHTML='<option value="">— Επιλέξτε αθλητή —</option>';
    if(HAS_FEE_AMOUNT)document.getElementById('ap_fee_amount').value=Number(exam.cost||0).toFixed(2);
    if(HAS_FEE_PAID)document.getElementById('ap_fee_paid').checked=false;
    if(HAS_SPORT_STYLE){document.getElementById('ap_style_wrap').style.display='none';document.getElementById('ap_style').innerHTML='';}
    openModal('addParticipantModal');
}

const apAthleteSel=document.getElementById('ap_athlete_id');
if(apAthleteSel){
    apAthleteSel.addEventListener('change',function(){
        const opt=this.options[this.selectedIndex];
        if(!opt||!opt.value)return;
        const sport=opt.dataset.sport||'other';
        const style=opt.dataset.style||'';
        if(HAS_SPORT_STYLE){
            const styleWrap=document.getElementById('ap_style_wrap');
            const styleSel=document.getElementById('ap_style');
            const styles=SPORT_STYLES[sport]||{};
            const keys=Object.keys(styles);
            if(keys.length){
                styleWrap.style.display='';
                styleSel.innerHTML=keys.map(k=>`<option value="${esc(k)}"${k===style?' selected':''}>${esc(styles[k])}</option>`).join('');
                if(style&&keys.includes(style))styleSel.value=style;
                else styleSel.value=keys[0];
            }else{styleWrap.style.display='none';styleSel.innerHTML='';}
        }
        rebuildParticipantBelts();
    });
}

const apStyleSel=document.getElementById('ap_style');
if(apStyleSel)apStyleSel.addEventListener('change',()=>rebuildParticipantBelts());

function openEditExam(id){
    const exam=EXAM_DATA[id];
    if(!exam)return;
    document.getElementById('editExamId').value=id;
    document.getElementById('editExamDate').value=exam.date;
    document.getElementById('editExamCost').value=exam.cost;
    document.getElementById('editExamLocation').value=exam.location;
    document.getElementById('editExamNotes').value=exam.notes;
    openModal('editExamModal');
}

function confirmDeleteExam(id,label){
    document.getElementById('deleteExamIdInput').value=id;
    document.getElementById('deleteExamLabel').textContent=label;
    openModal('deleteExamModal');
}

function confirmRemoveParticipant(participantId,name){
    document.getElementById('removePartIdInput').value=participantId;
    document.getElementById('removePartLabel').textContent=name;
    openModal('removePartModal');
}

// ─── FIX: openExamInfo now uses paid_amount from transactions (via JSON endpoint) ───
function openExamInfo(id){
    fetch(APP_URL_JS+'/pages/belts.php?json_exam='+id)
        .then(r=>r.json())
        .then(data=>{
            if(data.exam){
                const e=data.exam;
                EXAM_DATA[id]={
                    id:Number(e.id),date:e.exam_date,
                    date_fmt:e.exam_date?e.exam_date.split('-').reverse().join('/'):'',
                    location:e.location||'',cost:Number(e.cost||0),
                    notes:e.notes||'',
                    expected:Number(e.total_fee_expected||0),
                    collected:Number(e.total_fee_collected||0),
                    participants:Number(e.participant_count||0)
                };
                EXAM_PARTICIPANTS[id]=(data.participants||[]).map(p=>({
                    id:Number(p.id),exam_id:Number(p.exam_id),
                    athlete_id:Number(p.athlete_id),full_name:p.full_name,
                    belt_before:p.belt_before||'',belt_after:p.belt_after||'',
                    result:p.result||'pass',
                    fee_amount:p.fee_amount!==null&&p.fee_amount!==undefined?Number(p.fee_amount):null,
                    fee_paid:Number(p.fee_paid||0),
                    paid_amount:Number(p.paid_amount||0) // FIX: from transactions SUM
                }));
            }
            _renderExamInfo(id);
        })
        .catch(()=>_renderExamInfo(id));
}

// ─── FIX: _renderExamInfo uses paid_amount (transactions) not fee_paid flag ───
function _renderExamInfo(id){
    const exam=EXAM_DATA[id];
    const parts=EXAM_PARTICIPANTS[id]||[];
    if(!exam)return;
    document.getElementById('examInfoTitle').textContent=exam.date_fmt+(exam.location?' — '+exam.location:'');

    // FIX: calculate totals from transactions paid_amount, not fee_paid flag
    let liveExpected=0, liveCollected=0;
    parts.forEach(p=>{
        const fee=p.fee_amount!==null&&p.fee_amount!==undefined?Number(p.fee_amount):Number(exam.cost||0);
        const paid=Number(p.paid_amount||0);
        liveExpected+=fee;
        liveCollected+=paid;
    });
    const livePending=Math.max(0,liveExpected-liveCollected);

    let html=`<div class="summary-grid">
        <div class="summary-card"><div class="k">Αναμενόμενο</div><div class="v">${money(liveExpected)}</div></div>
        <div class="summary-card"><div class="k">Εισπραγμένο</div><div class="v" style="color:#2dc653">${money(liveCollected)}</div></div>
        <div class="summary-card"><div class="k">Εκκρεμές</div><div class="v" style="color:${livePending>0.0001?'#f0a500':'#2dc653'}">${livePending>0.0001?money(livePending):'✓'}</div></div>
    </div>`;
    if(exam.notes)html+=`<div class="modal-body" style="padding-top:.8rem;padding-bottom:.2rem"><div style="font-size:clamp(.84rem,3vw,.9rem)!important;color:var(--muted,#8892b0)"><i class="fa-regular fa-note-sticky"></i> ${esc(exam.notes)}</div></div>`;
    if(!parts.length){
        html+=`<div class="center-empty">Δεν υπάρχουν συμμετέχοντες ακόμα.</div>`;
    }else{
        html+=`<div class="table-wrap"><table><thead><tr><th>Αθλητής</th><th>Ζώνη</th><th>Τέλος</th><th>Κατάσταση</th><th></th></tr></thead><tbody>`;
        parts.forEach(p=>{
            const fee=p.fee_amount!==null&&p.fee_amount!==undefined?Number(p.fee_amount):Number(exam.cost||0);
            // FIX: use paid_amount from transactions as source of truth
            const paidAmount=Number(p.paid_amount||0);
            const pendingAmount=Math.max(0,fee-paidAmount);

            // Determine 3-state status
            let payStatus; // 'paid' | 'partial' | 'pending'
            if(pendingAmount<=0.001){payStatus='paid';}
            else if(paidAmount>0.001){payStatus='partial';}
            else{payStatus='pending';}

            let feeCell=`<span style="font-weight:700">${money(fee)}</span>`;
            if(fee>0){
                if(payStatus==='paid'){
                    feeCell+=`<div class="fee-sub paid"><i class="fa-solid fa-check" style="font-size:.65rem"></i> Πληρώθηκε: <strong>${money(fee)}</strong></div>`;
                }else if(payStatus==='partial'){
                    feeCell+=`<div class="fee-sub partial"><i class="fa-solid fa-circle-half-stroke" style="font-size:.65rem"></i> Πληρώθηκε: <strong>${money(paidAmount)}</strong></div>`;
                    feeCell+=`<div class="fee-sub pending"><i class="fa-solid fa-clock" style="font-size:.65rem"></i> Εκκρεμεί: <strong>${money(pendingAmount)}</strong></div>`;
                }else{
                    feeCell+=`<div class="fee-sub pending"><i class="fa-solid fa-clock" style="font-size:.65rem"></i> Εκκρεμεί: <strong>${money(fee)}</strong></div>`;
                }
            }

            // Status toggle button uses fee_paid flag for DB write,
            // but display reflects transactions paid_amount
            let statusCell;
            if(HAS_FEE_PAID){
                const isPaidFlag=parseInt(p.fee_paid,10)===1;
                // Toggle to the opposite of current fee_paid flag
                statusCell=`<form method="POST" style="display:inline">
                    <input type="hidden" name="_action" value="toggle_fee_paid">
                    <input type="hidden" name="csrf_token" value="${esc(CSRF)}">
                    <input type="hidden" name="participant_id" value="${p.id}">
                    <input type="hidden" name="exam_id" value="${id}">
                    <input type="hidden" name="fee_paid" value="${isPaidFlag?0:1}">
                    <button type="submit" class="btn btn-sm" style="${
                        payStatus==='paid'
                          ?'background:rgba(45,198,83,.12);color:#2dc653;border:1px solid rgba(45,198,83,.3)'
                          :payStatus==='partial'
                            ?'background:rgba(59,130,246,.12);color:#3b82f6;border:1px solid rgba(59,130,246,.3)'
                            :'background:rgba(240,165,0,.12);color:#f0a500;border:1px solid rgba(240,165,0,.3)'
                    }">${
                        payStatus==='paid'
                          ?'<i class="fa-solid fa-check-circle"></i> Πληρωμένο'
                          :payStatus==='partial'
                            ?'<i class="fa-solid fa-circle-half-stroke"></i> Μερικώς'
                            :'<i class="fa-solid fa-clock"></i> Εκκρεμεί'
                    }</button>
                </form>`;
            }else{
                statusCell=payStatus==='paid'
                    ?'<span class="badge badge-ok"><i class="fa-solid fa-check"></i> Πληρωμένο</span>'
                    :payStatus==='partial'
                      ?'<span class="badge badge-partial"><i class="fa-solid fa-circle-half-stroke"></i> Μερικώς</span>'
                      :'<span class="badge badge-warn"><i class="fa-solid fa-clock"></i> Εκκρεμεί</span>';
            }

            html+=`<tr>
                <td><a href="${APP_URL_JS}/pages/athletes.php?view=${p.athlete_id}" style="text-decoration:none;font-weight:800;color:var(--text,#e2e8f0)">${esc(p.full_name)}</a></td>
                <td>${esc(p.belt_before||'—')} → <strong>${esc(p.belt_after||'—')}</strong></td>
                <td>${feeCell}</td>
                <td>${statusCell}</td>
                <td><button type="button" class="btn btn-sm" style="background:rgba(230,57,70,.12);color:#e63946;border:1px solid rgba(230,57,70,.3)" onclick="closeModal('examInfoModal');confirmRemoveParticipant(${p.id},'${esc(p.full_name)}')" title="Αφαίρεση"><i class="fa-solid fa-user-minus"></i></button></td>
            </tr>`;
        });
        html+=`</tbody></table></div>`;
    }
    document.getElementById('examInfoContent').innerHTML=html;
    openModal('examInfoModal');
}

// ─── FIX: openStats also uses paid_amount from transactions ───
function openStats(id){
    fetch(APP_URL_JS+'/pages/belts.php?json_exam='+id)
        .then(r=>r.json())
        .then(data=>{
            const e=data.exam||null;
            if(e){
                EXAM_DATA[id]={
                    id:Number(e.id),date:e.exam_date,
                    date_fmt:e.exam_date?e.exam_date.split('-').reverse().join('/'):'',
                    location:e.location||'',cost:Number(e.cost||0),
                    notes:e.notes||'',
                    expected:Number(e.total_fee_expected||0),
                    collected:Number(e.total_fee_collected||0),
                    participants:Number(e.participant_count||0)
                };
                EXAM_PARTICIPANTS[id]=(data.participants||[]).map(p=>({
                    id:Number(p.id),exam_id:Number(p.exam_id),
                    athlete_id:Number(p.athlete_id),full_name:p.full_name,
                    belt_before:p.belt_before||'',belt_after:p.belt_after||'',
                    result:p.result||'pass',
                    fee_amount:p.fee_amount!==null&&p.fee_amount!==undefined?Number(p.fee_amount):null,
                    fee_paid:Number(p.fee_paid||0),
                    paid_amount:Number(p.paid_amount||0)
                }));
            }
            _renderStats(id);
        })
        .catch(()=>_renderStats(id));
}

// ─── FIX: _renderStats uses paid_amount (transactions) not fee_paid flag ───
function _renderStats(id){
    const exam=EXAM_DATA[id];
    const parts=EXAM_PARTICIPANTS[id]||[];
    if(!exam)return;

    // FIX: calculate from transactions paid_amount
    let liveExpected=0, liveCollected=0, countPaid=0, countPartial=0, countPending=0;
    parts.forEach(p=>{
        const fee=p.fee_amount!==null&&p.fee_amount!==undefined?Number(p.fee_amount):Number(exam.cost||0);
        const paid=Number(p.paid_amount||0);
        const pending=Math.max(0,fee-paid);
        liveExpected+=fee;
        liveCollected+=paid;
        if(pending<=0.001){countPaid++;}
        else if(paid>0.001){countPartial++;}
        else{countPending++;}
    });
    const livePending=Math.max(0,liveExpected-liveCollected);
    const rate=liveExpected>0?Math.round((liveCollected/liveExpected)*100):0;

    document.getElementById('statsTitle').textContent=exam.date_fmt+(exam.location?' — '+exam.location:'');
    document.getElementById('statsBody').innerHTML=
        `<div class="summary-grid" style="border-bottom:none;padding:0;margin-bottom:1rem">
            <div class="summary-card"><div class="k">Σύνολο αθλητών</div><div class="v">${parts.length}</div></div>
            <div class="summary-card"><div class="k">Πληρωμένοι</div><div class="v" style="color:#2dc653">${countPaid}</div></div>
            <div class="summary-card"><div class="k">Μερικώς</div><div class="v" style="color:#3b82f6">${countPartial}</div></div>
            <div class="summary-card"><div class="k">Αναμενόμενο</div><div class="v">${money(liveExpected)}</div></div>
            <div class="summary-card"><div class="k">Εισπραγμένο</div><div class="v" style="color:#2dc653">${money(liveCollected)}</div></div>
            <div class="summary-card"><div class="k">Ποσοστό είσπραξης</div><div class="v" style="color:${rate>=100?'#2dc653':(rate>=60?'#f0a500':'#e63946')}">${rate}%</div></div>
        </div>
        <div class="chart-container" style="max-width:260px;margin:0 auto"><canvas id="statsChart"></canvas></div>
    `;
    openModal('statsModal');
    setTimeout(()=>{
        const canvas=document.getElementById('statsChart');
        if(!canvas)return;
        new Chart(canvas.getContext('2d'),{
            type:'doughnut',
            data:{
                labels:['Πληρωμένοι','Μερικώς','Εκκρεμείς'],
                datasets:[{
                    data:[countPaid,countPartial,countPending],
                    backgroundColor:['#2dc653','#3b82f6','#f0a500'],
                    borderColor:'rgba(0,0,0,.35)',
                    borderWidth:2
                }]
            },
            options:{plugins:{legend:{position:'bottom'}}}
        });
    },30);
}

<?php if ($tab === 'stats' && $beltStatsList): ?>
(()=>{
    const ctx=document.getElementById('beltChart');
    if(!ctx)return;
    new Chart(ctx.getContext('2d'),{type:'doughnut',data:{labels:<?= json_encode(array_column($beltStatsList,'belt'),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>,datasets:[{data:<?= json_encode(array_map('intval',array_column($beltStatsList,'cnt'))) ?>,backgroundColor:['#e63946','#f4a535','#2dc653','#3a86ff','#a855f7','#fb923c','#06b6d4','#84cc16','#f43f5e','#10b981'],borderColor:'rgba(0,0,0,.35)',borderWidth:2}]},options:{plugins:{legend:{position:'bottom'}}}});
})();
<?php endif; ?>
</script>
</body>
</html>