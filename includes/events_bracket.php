<?php
/**
 * ============================================================
 * includes/events_bracket.php — Phase 2 bracket & scheduling engine
 * ============================================================
 * PURPOSE:
 *   Generate pools / brackets, assign rings & times, record
 *   scores, propagate winners, publish standings.
 *
 * DEPENDS ON:  includes/events.php (which loads config.php)
 *
 * SECTIONS:
 *   1. Seeding
 *   2. Bracket generation (single_elim, double_elim, pool_ko, round_robin)
 *   3. Winner propagation (single-elim)
 *   4. Ring scheduler
 *   5. Match scoring & result recording
 *   6. Standings + result publication
 *   7. Live-day queries (referee page, venue display)
 * ============================================================
 */

require_once __DIR__ . '/events.php';


// ══════════════════════════════════════════════════════════════
// 1. SEEDING
// ══════════════════════════════════════════════════════════════

/** All approved registrations for a category, in current seed order. */
function bracketCategoryRegs(int $categoryId): array {
    // NOTE: athletes.belt doesn't exist in the base schema, so we drop
    // it from the SELECT to avoid 500s. Payment/status columns come
    // from the registration row itself and power the export.
    $st = getDB()->prepare("
        SELECT r.id, r.athlete_id, r.registering_school_id, r.seed, r.pool_id,
               r.status, r.payment_status,
               a.full_name AS athlete_name,
               s.name AS school_name
        FROM event_registrations r
        LEFT JOIN athletes a ON a.id = r.athlete_id
        LEFT JOIN schools s  ON s.id = r.registering_school_id
        WHERE r.category_id = ?
          AND r.status IN ('approved','checked_in')
        ORDER BY (r.seed IS NULL), r.seed, s.name, a.full_name
    ");
    $st->execute([$categoryId]);
    return $st->fetchAll();
}

function bracketSetSeed(int $regId, int $categoryId, ?int $seed): void {
    $st = getDB()->prepare("UPDATE event_registrations SET seed = ? WHERE id = ? AND category_id = ?");
    $st->execute([$seed, $regId, $categoryId]);
}

/** Auto-seed. Modes: 'random', 'club_snake' (spread same-club apart), 'belt' (higher belt = higher seed). */
function bracketAutoSeed(int $categoryId, string $mode = 'club_snake'): int {
    $regs = bracketCategoryRegs($categoryId);
    if (!$regs) return 0;

    if ($mode === 'random') {
        shuffle($regs);
    } elseif ($mode === 'belt') {
        // higher-listed belt = higher seed. Uses string comparison as a rough proxy.
        usort($regs, fn($a, $b) => strcmp((string)$b['belt'], (string)$a['belt']));
    } else {
        // club_snake: interleave athletes across clubs so #1 and #2 of a club aren't in the same half
        $byClub = [];
        foreach ($regs as $r) $byClub[$r['school_name'] ?? '—'][] = $r;
        foreach ($byClub as &$g) shuffle($g);
        $ordered = [];
        while (!empty($byClub)) {
            foreach ($byClub as $club => &$g) {
                $ordered[] = array_shift($g);
                if (empty($g)) unset($byClub[$club]);
            }
        }
        $regs = $ordered;
    }

    $db = getDB();
    $upd = $db->prepare("UPDATE event_registrations SET seed = ? WHERE id = ?");
    $i = 1;
    foreach ($regs as $r) { $upd->execute([$i, (int)$r['id']]); $i++; }
    auditLog('event_seeded', 'event_category', $categoryId, "mode=$mode n=" . ($i - 1));
    return $i - 1;
}


// ══════════════════════════════════════════════════════════════
// 2. BRACKET GENERATION
// ══════════════════════════════════════════════════════════════

/** Standard tournament seeding order for a power-of-2 bracket. */
function bracketSeedingOrder(int $bracketSize): array {
    if ($bracketSize < 2) return [1];
    $arr = [1, 2];
    while (count($arr) < $bracketSize) {
        $next = [];
        $sum = count($arr) * 2 + 1;
        foreach ($arr as $s) { $next[] = $s; $next[] = $sum - $s; }
        $arr = $next;
    }
    return $arr;
}

function bracketNextPow2(int $n): int {
    $p = 1;
    while ($p < max($n, 1)) $p <<= 1;
    return $p;
}

function bracketRoundLabel(int $matchesInRound): string {
    return match(true) {
        $matchesInRound === 1 => 'Τελικός',
        $matchesInRound === 2 => 'Ημιτελικοί',
        $matchesInRound <= 4  => 'Προημιτελικοί',
        $matchesInRound <= 8  => 'Round of 16',
        $matchesInRound <= 16 => 'Round of 32',
        default               => 'Round of ' . ($matchesInRound * 2),
    };
}

/** Wipe existing bracket for a category. Safe: only touches non-completed matches. */
function bracketReset(int $eventId, int $categoryId): void {
    $db = getDB();
    // Guard: refuse if any match is completed (avoid destroying results)
    $chk = $db->prepare("SELECT COUNT(*) FROM event_matches WHERE category_id = ? AND status = 'completed'");
    $chk->execute([$categoryId]);
    if ((int)$chk->fetchColumn() > 0) {
        throw new RuntimeException('Υπάρχουν ολοκληρωμένοι αγώνες σε αυτήν την κατηγορία. Δεν μπορώ να ξαναφτιάξω το bracket χωρίς να χαθούν.');
    }
    $db->prepare("DELETE FROM event_matches WHERE event_id = ? AND category_id = ?")
       ->execute([$eventId, $categoryId]);
    $db->prepare("DELETE FROM event_pools WHERE event_id = ? AND category_id = ?")
       ->execute([$eventId, $categoryId]);
    $db->prepare("UPDATE event_registrations SET pool_id = NULL WHERE category_id = ?")
       ->execute([$categoryId]);
}

/** Generate the full bracket/pool set for a category. Returns count of matches created. */
function bracketGenerate(int $eventId, int $categoryId): int {
    $cat = eventCategoryGet($categoryId);
    if (!$cat || (int)$cat['event_id'] !== $eventId) throw new RuntimeException('Άκυρη κατηγορία.');

    bracketReset($eventId, $categoryId);
    $regs = bracketCategoryRegs($categoryId);
    if (count($regs) < 2) throw new RuntimeException('Χρειάζονται τουλάχιστον 2 συμμετέχοντες.');

    return match($cat['format']) {
        'single_elim' => bracketGenSingleElim($eventId, $categoryId, $regs),
        'pool_ko'     => bracketGenPoolKo($eventId, $categoryId, $regs, (int)$cat['pool_size']),
        'round_robin' => bracketGenRoundRobin($eventId, $categoryId, $regs),
        'pool_only'   => bracketGenPoolOnly($eventId, $categoryId, $regs, (int)$cat['pool_size']),
        'group_weight'=> bracketGenGroupByWeight(
                            $eventId, $categoryId, $regs,
                            (int)$cat['pool_size'],
                            (float)($cat['weight_margin_kg'] ?? 0)),
        'double_elim' => bracketGenSingleElim($eventId, $categoryId, $regs), // TODO Phase 3
        'exhibition'  => 0,
        default       => 0,
    };
}

/**
 * GROUP_BASED matchmaking: sort athletes by their latest recorded
 * weight and split them into round-robin pools of size $poolSize.
 * If $marginKg > 0, force a new pool whenever the weight jump from
 * one athlete to the next exceeds $marginKg — so athletes fight
 * roughly comparable opponents even without hardcoded categories.
 *
 * Athletes without any weight_history row go into a final pool
 * marked 'Unranked' and still get round-robin matches among
 * themselves.
 *
 * Returns the count of matches created.
 */
function bracketGenGroupByWeight(int $eventId, int $categoryId, array $regs, int $poolSize, float $marginKg = 0.0): int {
    if (!$regs) return 0;
    $poolSize = max(2, $poolSize);

    // Fetch latest weight per athlete in a single query
    $ids = array_filter(array_map(fn($r) => (int)$r['athlete_id'], $regs));
    $weights = [];
    if ($ids) {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $wq = getDB()->prepare("
            SELECT wh.athlete_id, wh.weight
              FROM weight_history wh
              JOIN (
                    SELECT athlete_id, MAX(recorded_at) AS m
                      FROM weight_history
                     WHERE athlete_id IN ($ph)
                     GROUP BY athlete_id
                   ) latest
                ON latest.athlete_id = wh.athlete_id AND latest.m = wh.recorded_at
        ");
        $wq->execute($ids);
        foreach ($wq->fetchAll() as $row) {
            $weights[(int)$row['athlete_id']] = (float)$row['weight'];
        }
    }

    // Split regs into ranked (has weight) + unranked
    $ranked = []; $unranked = [];
    foreach ($regs as $r) {
        $w = $weights[(int)$r['athlete_id']] ?? null;
        if ($w !== null) { $r['_weight'] = $w; $ranked[] = $r; }
        else             { $unranked[] = $r; }
    }
    usort($ranked, fn($a, $b) => $a['_weight'] <=> $b['_weight']);

    // Build pools honoring poolSize and (optionally) marginKg splits
    $pools = [];
    $current = [];
    $prevWeight = null;
    foreach ($ranked as $r) {
        if ($marginKg > 0 && $prevWeight !== null && ($r['_weight'] - $prevWeight) > $marginKg && $current) {
            $pools[] = $current;
            $current = [];
        }
        $current[] = $r;
        $prevWeight = $r['_weight'];
        if (count($current) >= $poolSize) {
            $pools[] = $current;
            $current = [];
            $prevWeight = null;
        }
    }
    if ($current) $pools[] = $current;
    if ($unranked) $pools[] = $unranked;

    // Persist pools + round-robin matches
    $db = getDB();
    $poolInsert = $db->prepare("INSERT INTO event_pools (event_id, category_id, name, format, display_order) VALUES (?,?,?, 'round_robin', ?)");
    $regUpdate  = $db->prepare("UPDATE event_registrations SET pool_id = ? WHERE id = ?");
    $matchIns   = $db->prepare("INSERT INTO event_matches
        (event_id, category_id, pool_id, round_label, bracket_position, ring_number,
         red_registration_id, blue_registration_id, result_type, status)
        VALUES (?,?,?,?,?,1,?,?, 'pending','scheduled')");

    $pos = 1;
    foreach ($pools as $i => $poolRegs) {
        if (!$poolRegs) continue;
        $poolName = ($poolRegs === $unranked) ? 'Unranked' : 'Group ' . chr(65 + $i);
        $poolInsert->execute([$eventId, $categoryId, $poolName, $i]);
        $poolId = (int)$db->lastInsertId();
        foreach ($poolRegs as $r) $regUpdate->execute([$poolId, (int)$r['id']]);
        $n = count($poolRegs);
        for ($a = 0; $a < $n; $a++) {
            for ($b = $a + 1; $b < $n; $b++) {
                $matchIns->execute([
                    $eventId, $categoryId, $poolId,
                    $poolName . ' R', $pos,
                    (int)$poolRegs[$a]['id'], (int)$poolRegs[$b]['id'],
                ]);
                $pos++;
            }
        }
    }
    return $pos - 1;
}

function bracketGenSingleElim(int $eventId, int $categoryId, array $regs): int {
    $db = getDB();
    $n  = count($regs);
    $bracketSize = bracketNextPow2($n);
    $byes        = $bracketSize - $n;

    // Order regs by seed then pad with nulls (byes) at proper positions
    $seedOrder = bracketSeedingOrder($bracketSize);   // e.g. [1,8,4,5,2,7,3,6] for 8
    // Place regs at positions of seeds 1..n; higher seeds become byes
    $bySeed = [];
    $i = 1;
    foreach ($regs as $r) { $bySeed[$i++] = $r; }

    $slots = [];
    foreach ($seedOrder as $seedNum) {
        $slots[] = $bySeed[$seedNum] ?? null;   // null = bye
    }

    // Round 1 = pairs of slots [0,1],[2,3],...
    $matches = [];
    $round1Count = $bracketSize / 2;
    for ($i = 0; $i < $round1Count; $i++) {
        $red  = $slots[$i * 2]     ?? null;
        $blue = $slots[$i * 2 + 1] ?? null;
        $matches[] = [
            'round' => 1,
            'pos'   => $i + 1,
            'red'   => $red,
            'blue'  => $blue,
        ];
    }

    // Insert all rounds (round 1 with real players; later rounds empty slots)
    $ins = $db->prepare("INSERT INTO event_matches
        (event_id, category_id, round_label, bracket_position, ring_number,
         red_registration_id, blue_registration_id, winner_registration_id, result_type, status)
        VALUES (?,?,?,?,1,?,?,?,?,?)");

    $globalPos   = 1;
    $insertedIds = [];

    // Round 1
    foreach ($matches as $m) {
        $red    = $m['red'];
        $blue   = $m['blue'];
        $redId  = $red  ? (int)$red['id']  : null;
        $blueId = $blue ? (int)$blue['id'] : null;

        // Auto-walkover if one side is bye
        $winner = null; $rt = 'pending'; $status = 'scheduled';
        if ($redId && !$blueId) { $winner = $redId;  $rt = 'walkover'; $status = 'completed'; }
        elseif (!$redId && $blueId) { $winner = $blueId; $rt = 'walkover'; $status = 'completed'; }

        $lbl = bracketRoundLabel($round1Count);
        $ins->execute([$eventId, $categoryId, $lbl, $globalPos, $redId, $blueId, $winner, $rt, $status]);
        $insertedIds[1][] = (int)$db->lastInsertId();
        $globalPos++;
    }

    // Rounds 2..N
    $prevCount = $round1Count;
    $round = 2;
    while ($prevCount >= 2) {
        $thisCount = $prevCount / 2;
        $lbl = bracketRoundLabel($thisCount);
        for ($i = 0; $i < $thisCount; $i++) {
            $ins->execute([$eventId, $categoryId, $lbl, $globalPos, null, null, null, 'pending', 'scheduled']);
            $insertedIds[$round][] = (int)$db->lastInsertId();
            $globalPos++;
        }
        $prevCount = $thisCount;
        $round++;
    }

    // Propagate any auto-walkover winners into round 2 immediately
    foreach ($insertedIds[1] as $idx => $matchId) {
        $m = $db->query("SELECT * FROM event_matches WHERE id = $matchId")->fetch();
        if ($m['status'] === 'completed' && $m['winner_registration_id']) {
            bracketPropagateWinner((int)$matchId);
        }
    }

    return $globalPos - 1;
}

function bracketGenPoolKo(int $eventId, int $categoryId, array $regs, int $poolSize): int {
    $db = getDB();
    $poolSize = max(3, $poolSize);
    $n = count($regs);
    $numPools = max(1, (int)ceil($n / $poolSize));

    // Snake-distribute across pools by seed
    $pools = array_fill(0, $numPools, []);
    $dir = 1; $col = 0;
    foreach ($regs as $r) {
        $pools[$col][] = $r;
        $col += $dir;
        if ($col >= $numPools) { $col = $numPools - 1; $dir = -1; }
        elseif ($col < 0)      { $col = 0;             $dir = 1;  }
    }

    // Create pools and assign registration.pool_id
    $poolInsert = $db->prepare("INSERT INTO event_pools (event_id, category_id, name, format, display_order) VALUES (?,?,?, 'round_robin', ?)");
    $regUpdate  = $db->prepare("UPDATE event_registrations SET pool_id = ? WHERE id = ?");
    $matchIns   = $db->prepare("INSERT INTO event_matches
        (event_id, category_id, pool_id, round_label, bracket_position, ring_number,
         red_registration_id, blue_registration_id, result_type, status)
        VALUES (?,?,?,?,?,1,?,?, 'pending','scheduled')");

    $pos = 1;
    foreach ($pools as $i => $poolRegs) {
        $poolName = 'Pool ' . chr(65 + $i);
        $poolInsert->execute([$eventId, $categoryId, $poolName, $i]);
        $poolId = (int)$db->lastInsertId();
        foreach ($poolRegs as $r) $regUpdate->execute([$poolId, (int)$r['id']]);

        // Round-robin bouts within pool
        $matchIdx = 1;
        for ($a = 0; $a < count($poolRegs); $a++) {
            for ($b = $a + 1; $b < count($poolRegs); $b++) {
                $matchIns->execute([$eventId, $categoryId, $poolId, "{$poolName} · R{$matchIdx}", $pos,
                                    (int)$poolRegs[$a]['id'], (int)$poolRegs[$b]['id']]);
                $matchIdx++; $pos++;
            }
        }
    }

    // KO placeholders for top-2 per pool (Phase 2: just create empty KO round for organiser to fill)
    $koFinalists = $numPools * 2;
    $koSize = bracketNextPow2($koFinalists);
    while ($koSize >= 2) {
        $lbl = bracketRoundLabel($koSize / 2);
        for ($i = 0; $i < $koSize / 2; $i++) {
            $matchIns->execute([$eventId, $categoryId, null, "KO · $lbl", $pos, null, null]);
            $pos++;
        }
        $koSize /= 2;
    }

    return $pos - 1;
}

function bracketGenRoundRobin(int $eventId, int $categoryId, array $regs): int {
    $db = getDB();
    $poolInsert = $db->prepare("INSERT INTO event_pools (event_id, category_id, name, format, display_order) VALUES (?,?,?, 'round_robin', 0)");
    $poolInsert->execute([$eventId, $categoryId, 'RR']);
    $poolId = (int)$db->lastInsertId();

    $upd = $db->prepare("UPDATE event_registrations SET pool_id = ? WHERE id = ?");
    foreach ($regs as $r) $upd->execute([$poolId, (int)$r['id']]);

    $ins = $db->prepare("INSERT INTO event_matches
        (event_id, category_id, pool_id, round_label, bracket_position, ring_number,
         red_registration_id, blue_registration_id, result_type, status)
        VALUES (?,?,?,?,?,1,?,?, 'pending','scheduled')");
    $pos = 1;
    for ($a = 0; $a < count($regs); $a++) {
        for ($b = $a + 1; $b < count($regs); $b++) {
            $ins->execute([$eventId, $categoryId, $poolId, "RR · A{$a}vB{$b}", $pos, (int)$regs[$a]['id'], (int)$regs[$b]['id']]);
            $pos++;
        }
    }
    return $pos - 1;
}

function bracketGenPoolOnly(int $eventId, int $categoryId, array $regs, int $poolSize): int {
    // Same as pool_ko but without the KO placeholders
    $db = getDB();
    $poolSize = max(3, $poolSize);
    $n = count($regs);
    $numPools = max(1, (int)ceil($n / $poolSize));
    $pools = array_fill(0, $numPools, []);
    $dir = 1; $col = 0;
    foreach ($regs as $r) {
        $pools[$col][] = $r;
        $col += $dir;
        if ($col >= $numPools) { $col = $numPools - 1; $dir = -1; }
        elseif ($col < 0)      { $col = 0;             $dir = 1; }
    }
    $poolInsert = $db->prepare("INSERT INTO event_pools (event_id, category_id, name, format, display_order) VALUES (?,?,?, 'round_robin', ?)");
    $regUpdate  = $db->prepare("UPDATE event_registrations SET pool_id = ? WHERE id = ?");
    $matchIns   = $db->prepare("INSERT INTO event_matches
        (event_id, category_id, pool_id, round_label, bracket_position, ring_number,
         red_registration_id, blue_registration_id, result_type, status)
        VALUES (?,?,?,?,?,1,?,?, 'pending','scheduled')");
    $pos = 1;
    foreach ($pools as $i => $poolRegs) {
        $poolName = 'Pool ' . chr(65 + $i);
        $poolInsert->execute([$eventId, $categoryId, $poolName, $i]);
        $poolId = (int)$db->lastInsertId();
        foreach ($poolRegs as $r) $regUpdate->execute([$poolId, (int)$r['id']]);
        for ($a = 0; $a < count($poolRegs); $a++) {
            for ($b = $a + 1; $b < count($poolRegs); $b++) {
                $matchIns->execute([$eventId, $categoryId, $poolId, "{$poolName} R", $pos,
                                    (int)$poolRegs[$a]['id'], (int)$poolRegs[$b]['id']]);
                $pos++;
            }
        }
    }
    return $pos - 1;
}


// ══════════════════════════════════════════════════════════════
// 3. WINNER PROPAGATION (single-elim only)
// ══════════════════════════════════════════════════════════════

/** Push winner of $matchId to the parent bracket slot. No-op for pool bouts. */
function bracketPropagateWinner(int $matchId): void {
    $db = getDB();
    $st = $db->prepare("SELECT * FROM event_matches WHERE id = ?");
    $st->execute([$matchId]);
    $m = $st->fetch();
    if (!$m || $m['pool_id']) return; // pool matches don't propagate this way
    if (!$m['winner_registration_id']) return;

    // Find next round match: same category, no pool, round_label of next round.
    // Simpler: use bracket_position math — round-1 matches 1..K, round-2 M matches K+1..K+K/2, etc.
    // Compute where this position falls.
    $catId = (int)$m['category_id'];
    $allNoPool = $db->prepare("SELECT id, bracket_position FROM event_matches WHERE category_id = ? AND pool_id IS NULL ORDER BY bracket_position");
    $allNoPool->execute([$catId]);
    $list = $allNoPool->fetchAll();
    if (!$list) return;

    $positions = array_column($list, 'bracket_position');
    $idsByPos  = [];
    foreach ($list as $row) $idsByPos[(int)$row['bracket_position']] = (int)$row['id'];

    // total matches → derive round sizes
    $total = count($list);
    // total = R1 + R2 + ... + 1 = R1 * 2 - 1 for a perfect bracket → R1 = (total+1)/2
    $r1 = ($total + 1) / 2;
    if ($r1 < 1 || $r1 != (int)$r1) return; // not a clean single-elim

    // Determine round of current match by its bracket_position (positions are 1..total, consecutive)
    $pos = (int)$m['bracket_position'];
    $roundStart = 1; $roundSize = (int)$r1; $round = 1;
    while ($pos > $roundStart + $roundSize - 1) {
        $roundStart += $roundSize;
        $roundSize  /= 2;
        $round++;
        if ($roundSize < 1) return;
    }
    if ($roundSize <= 1) return; // this was the final

    $indexInRound = $pos - $roundStart;              // 0-based
    $nextIndex    = intdiv($indexInRound, 2);        // pair up
    $nextPos      = $roundStart + $roundSize + $nextIndex;
    if (!isset($idsByPos[$nextPos])) return;
    $nextId = $idsByPos[$nextPos];

    // Fill red if left child of pair, blue if right
    $slot = ($indexInRound % 2 === 0) ? 'red_registration_id' : 'blue_registration_id';
    $upd  = $db->prepare("UPDATE event_matches SET {$slot} = ? WHERE id = ? AND {$slot} IS NULL");
    $upd->execute([(int)$m['winner_registration_id'], $nextId]);
}


// ══════════════════════════════════════════════════════════════
// 4. RING SCHEDULER
// ══════════════════════════════════════════════════════════════

/**
 * Simple greedy scheduler:
 *   - Iterates matches in bracket_position order
 *   - Assigns earliest-available ring + time
 *   - Enforces min rest between an athlete's back-to-back bouts
 */
function bracketSchedule(int $eventId, int $categoryId, DateTime $start, int $slotMinutes = 15, int $restMinutes = 20): int {
    $db = getDB();
    $ev = eventGet($eventId);
    if (!$ev) throw new RuntimeException('Event not found.');
    $rings = max(1, (int)$ev['ring_count']);

    // Get matches (unscheduled or already scheduled — we overwrite)
    $ms = $db->prepare("SELECT * FROM event_matches WHERE event_id = ? AND category_id = ? AND status NOT IN ('completed','cancelled') ORDER BY bracket_position");
    $ms->execute([$eventId, $categoryId]);
    $matches = $ms->fetchAll();
    if (!$matches) return 0;

    // Per-ring next free slot, per-athlete earliest next slot
    $ringNext = array_fill(1, $rings, (clone $start)->getTimestamp());
    $athNext  = [];   // registration_id → epoch

    $upd = $db->prepare("UPDATE event_matches SET ring_number = ?, scheduled_at = ? WHERE id = ?");
    $count = 0;

    foreach ($matches as $m) {
        $red  = (int)($m['red_registration_id']  ?? 0);
        $blue = (int)($m['blue_registration_id'] ?? 0);
        // Skip if no players yet (empty bracket slot pending winners)
        if (!$red && !$blue) continue;

        $athReady = max($athNext[$red] ?? 0, $athNext[$blue] ?? 0);

        // Pick ring with earliest availability that respects athlete rest
        $bestRing = 1; $bestTime = PHP_INT_MAX;
        for ($r = 1; $r <= $rings; $r++) {
            $t = max($ringNext[$r], $athReady);
            if ($t < $bestTime) { $bestTime = $t; $bestRing = $r; }
        }
        $slotEnd = $bestTime + $slotMinutes * 60;
        $ringNext[$bestRing] = $slotEnd;
        if ($red)  $athNext[$red]  = $slotEnd + $restMinutes * 60;
        if ($blue) $athNext[$blue] = $slotEnd + $restMinutes * 60;

        $upd->execute([$bestRing, date('Y-m-d H:i:s', $bestTime), (int)$m['id']]);
        $count++;
    }

    auditLog('event_scheduled', 'event_category', $categoryId, "start={$start->format('Y-m-d H:i')} rings=$rings n=$count");
    return $count;
}


// ══════════════════════════════════════════════════════════════
// 5. MATCH SCORING
// ══════════════════════════════════════════════════════════════

function bracketMatchGet(int $matchId): ?array {
    $st = getDB()->prepare("
        SELECT m.*,
               ra.full_name AS red_name,  rs.name AS red_school,
               ba.full_name AS blue_name, bs.name AS blue_school,
               c.name AS cat_name
        FROM event_matches m
        LEFT JOIN event_registrations rr ON rr.id = m.red_registration_id
        LEFT JOIN athletes ra ON ra.id = rr.athlete_id
        LEFT JOIN schools rs  ON rs.id = rr.registering_school_id
        LEFT JOIN event_registrations br ON br.id = m.blue_registration_id
        LEFT JOIN athletes ba ON ba.id = br.athlete_id
        LEFT JOIN schools bs  ON bs.id = br.registering_school_id
        LEFT JOIN event_categories c ON c.id = m.category_id
        WHERE m.id = ? LIMIT 1
    ");
    $st->execute([$matchId]);
    return $st->fetch() ?: null;
}

function bracketScore(int $matchId, int $redScore, int $blueScore, string $resultType = 'points', ?int $winnerRegId = null, string $notes = ''): void {
    $db = getDB();
    $m  = bracketMatchGet($matchId);
    if (!$m) throw new RuntimeException('Match not found.');

    if ($winnerRegId === null) {
        if ($resultType === 'walkover' || $resultType === 'dq') {
            // must be provided
            throw new RuntimeException('Απαιτείται νικητής για walkover/DQ.');
        }
        $winnerRegId = $redScore > $blueScore ? (int)$m['red_registration_id']
                     : ($blueScore > $redScore ? (int)$m['blue_registration_id'] : null);
    }

    if (!in_array($resultType, ['points','ippon','waza','ko','dq','walkover','draw'], true)) $resultType = 'points';

    $db->prepare("UPDATE event_matches
        SET red_score = ?, blue_score = ?, result_type = ?, winner_registration_id = ?,
            status = 'completed', notes = ?
        WHERE id = ?")
       ->execute([$redScore, $blueScore, $resultType, $winnerRegId, mb_substr($notes, 0, 500), $matchId]);

    bracketPropagateWinner($matchId);
    auditLog('event_match_scored', 'event_match', $matchId, "red=$redScore blue=$blueScore rt=$resultType");
}

function bracketSetLive(int $matchId, bool $live): void {
    getDB()->prepare("UPDATE event_matches SET status = ? WHERE id = ?")
       ->execute([$live ? 'live' : 'scheduled', $matchId]);
}


// ══════════════════════════════════════════════════════════════
// 6. STANDINGS & PUBLICATION
// ══════════════════════════════════════════════════════════════

/** Compute pool standings: wins > point_diff > points_for. */
function bracketPoolStandings(int $poolId): array {
    $db = getDB();
    $matches = $db->prepare("SELECT * FROM event_matches WHERE pool_id = ? AND status = 'completed'");
    $matches->execute([$poolId]);
    $rows = $matches->fetchAll();

    $agg = [];   // reg_id → [wins, losses, pf, pa]
    $init = fn() => ['wins' => 0, 'losses' => 0, 'pf' => 0, 'pa' => 0];
    foreach ($rows as $m) {
        $rid = (int)$m['red_registration_id']; $bid = (int)$m['blue_registration_id'];
        if (!isset($agg[$rid])) $agg[$rid] = $init();
        if (!isset($agg[$bid])) $agg[$bid] = $init();
        $agg[$rid]['pf'] += (int)$m['red_score'];   $agg[$rid]['pa'] += (int)$m['blue_score'];
        $agg[$bid]['pf'] += (int)$m['blue_score'];  $agg[$bid]['pa'] += (int)$m['red_score'];
        if ((int)$m['winner_registration_id'] === $rid) { $agg[$rid]['wins']++; $agg[$bid]['losses']++; }
        elseif ((int)$m['winner_registration_id'] === $bid) { $agg[$bid]['wins']++; $agg[$rid]['losses']++; }
    }

    // Hydrate with names
    $ids = array_keys($agg);
    if (!$ids) return [];
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $names = $db->prepare("SELECT r.id, a.full_name AS athlete_name, s.name AS school_name
                           FROM event_registrations r
                           LEFT JOIN athletes a ON a.id = r.athlete_id
                           LEFT JOIN schools s ON s.id = r.registering_school_id
                           WHERE r.id IN ($ph)");
    $names->execute($ids);
    $meta = [];
    foreach ($names->fetchAll() as $n) $meta[(int)$n['id']] = $n;

    $out = [];
    foreach ($agg as $rid => $st) {
        $out[] = array_merge($meta[$rid] ?? ['id' => $rid], $st, ['diff' => $st['pf'] - $st['pa']]);
    }
    usort($out, function($a, $b) {
        if ($a['wins']  !== $b['wins'])  return $b['wins']  - $a['wins'];
        if ($a['diff']  !== $b['diff'])  return $b['diff']  - $a['diff'];
        return $b['pf'] - $a['pf'];
    });
    return $out;
}

/** Publish final results for a category: assign medals from bracket final + semis, insert into event_results. */
function bracketPublishResults(int $eventId, int $categoryId): int {
    $db = getDB();
    // Wipe old results for this category
    $db->prepare("DELETE FROM event_results WHERE event_id = ? AND category_id = ?")->execute([$eventId, $categoryId]);

    $matches = $db->prepare("SELECT * FROM event_matches WHERE event_id = ? AND category_id = ? AND pool_id IS NULL AND status = 'completed' ORDER BY bracket_position DESC");
    $matches->execute([$eventId, $categoryId]);
    $done = $matches->fetchAll();
    if (!$done) throw new RuntimeException('Δεν υπάρχουν ολοκληρωμένοι bracket αγώνες για αυτήν την κατηγορία.');

    $ins = $db->prepare("INSERT INTO event_results (event_id, category_id, registration_id, place, medal, points) VALUES (?,?,?,?,?,?)");

    // Final = highest bracket_position among non-pool completed matches
    $final = $done[0];
    $winner = (int)$final['winner_registration_id'];
    $loser  = ($winner === (int)$final['red_registration_id'])
              ? (int)$final['blue_registration_id']
              : (int)$final['red_registration_id'];

    if ($winner) $ins->execute([$eventId, $categoryId, $winner, 1, 'gold',   10]);
    if ($loser)  $ins->execute([$eventId, $categoryId, $loser,  2, 'silver', 7]);

    // Bronze: two semifinal losers
    // Semifinals = the two matches whose winners populate the final. Grab positions final_pos - 2 and final_pos - 1.
    $finalPos = (int)$final['bracket_position'];
    $semiA = null; $semiB = null;
    foreach ($done as $m) {
        if ((int)$m['bracket_position'] === $finalPos - 2) $semiA = $m;
        if ((int)$m['bracket_position'] === $finalPos - 1) $semiB = $m;
    }
    foreach ([$semiA, $semiB] as $semi) {
        if (!$semi) continue;
        $sw = (int)$semi['winner_registration_id'];
        $sl = ($sw === (int)$semi['red_registration_id'])
              ? (int)$semi['blue_registration_id']
              : (int)$semi['red_registration_id'];
        if ($sl) $ins->execute([$eventId, $categoryId, $sl, 3, 'bronze', 5]);
    }

    auditLog('event_results_published', 'event_category', $categoryId);
    return 4;
}

function bracketResultsFor(int $eventId, ?int $categoryId = null): array {
    $sql = "SELECT r.*, c.name AS cat_name, a.full_name AS athlete_name, s.name AS school_name
            FROM event_results r
            LEFT JOIN event_categories c ON c.id = r.category_id
            LEFT JOIN event_registrations reg ON reg.id = r.registration_id
            LEFT JOIN athletes a ON a.id = reg.athlete_id
            LEFT JOIN schools s  ON s.id = reg.registering_school_id
            WHERE r.event_id = ?";
    $args = [$eventId];
    if ($categoryId) { $sql .= " AND r.category_id = ?"; $args[] = $categoryId; }
    $sql .= " ORDER BY c.display_order, c.id, r.place";
    $st = getDB()->prepare($sql);
    $st->execute($args);
    return $st->fetchAll();
}


// ══════════════════════════════════════════════════════════════
// 7. LIVE-DAY QUERIES
// ══════════════════════════════════════════════════════════════

/** Matches for a given ring (or all rings if null), for the live-scoring/display screens. */
function bracketMatchesForRing(int $eventId, ?int $ring = null, int $limit = 30): array {
    $sql = "SELECT m.*, c.name AS cat_name,
                   ra.full_name AS red_name,  rs.name AS red_school,
                   ba.full_name AS blue_name, bs.name AS blue_school
            FROM event_matches m
            LEFT JOIN event_categories c ON c.id = m.category_id
            LEFT JOIN event_registrations rr ON rr.id = m.red_registration_id
            LEFT JOIN athletes ra ON ra.id = rr.athlete_id
            LEFT JOIN schools rs  ON rs.id = rr.registering_school_id
            LEFT JOIN event_registrations br ON br.id = m.blue_registration_id
            LEFT JOIN athletes ba ON ba.id = br.athlete_id
            LEFT JOIN schools bs  ON bs.id = br.registering_school_id
            WHERE m.event_id = ?";
    $args = [$eventId];
    if ($ring) { $sql .= " AND m.ring_number = ?"; $args[] = $ring; }
    $sql .= " AND m.status IN ('scheduled','live') ORDER BY m.ring_number, m.scheduled_at, m.bracket_position LIMIT " . (int)$limit;
    $st = getDB()->prepare($sql);
    $st->execute($args);
    return $st->fetchAll();
}

/** Complete bracket for a category with match & athlete data, suitable for rendering. */
function bracketFull(int $eventId, int $categoryId): array {
    $st = getDB()->prepare("
        SELECT m.*,
               ra.full_name AS red_name, rs.name AS red_school,
               ba.full_name AS blue_name, bs.name AS blue_school
        FROM event_matches m
        LEFT JOIN event_registrations rr ON rr.id = m.red_registration_id
        LEFT JOIN athletes ra ON ra.id = rr.athlete_id
        LEFT JOIN schools rs  ON rs.id = rr.registering_school_id
        LEFT JOIN event_registrations br ON br.id = m.blue_registration_id
        LEFT JOIN athletes ba ON ba.id = br.athlete_id
        LEFT JOIN schools bs  ON bs.id = br.registering_school_id
        WHERE m.event_id = ? AND m.category_id = ?
        ORDER BY (m.pool_id IS NULL), m.pool_id, m.bracket_position
    ");
    $st->execute([$eventId, $categoryId]);
    return $st->fetchAll();
}
