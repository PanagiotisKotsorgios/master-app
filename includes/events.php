<?php
/**
 * ============================================================
 * includes/events.php — Events Subsystem Helpers
 * ============================================================
 * PURPOSE:
 *   Shared logic for the events subsystem (πρωταθλήματα,
 *   φιλικά, camps, seminars). Every /pages/event_*.php and
 *   /events/*.php file loads this.
 *
 * SECTIONS:
 *   1. Slug generation & canonical URLs
 *   2. Event CRUD & fetch helpers (organiser scope + public)
 *   3. Category helpers
 *   4. Eligibility & registration
 *   5. Payment helpers
 *   6. Public discovery (search, filters)
 *   7. Parent linkage (which events a child is in)
 *   8. Uploads (safe path + private-file serving)
 *   9. Notifications wiring
 * ============================================================
 */

require_once __DIR__ . '/config.php';

// ══════════════════════════════════════════════════════════════
// 1. SLUG & URLS
// ══════════════════════════════════════════════════════════════

/** Greek-safe slugify. Falls back to id if slug is empty. */
function eventsSlugify(string $s): string {
    $map = [
        'α'=>'a','β'=>'v','γ'=>'g','δ'=>'d','ε'=>'e','ζ'=>'z','η'=>'i','θ'=>'th','ι'=>'i',
        'κ'=>'k','λ'=>'l','μ'=>'m','ν'=>'n','ξ'=>'x','ο'=>'o','π'=>'p','ρ'=>'r','σ'=>'s','ς'=>'s',
        'τ'=>'t','υ'=>'y','φ'=>'f','χ'=>'ch','ψ'=>'ps','ω'=>'o','ά'=>'a','έ'=>'e','ή'=>'i','ί'=>'i',
        'ό'=>'o','ύ'=>'y','ώ'=>'o','ϊ'=>'i','ϋ'=>'y','ΐ'=>'i','ΰ'=>'y',
    ];
    $s = mb_strtolower($s, 'UTF-8');
    $s = strtr($s, $map);
    $s = preg_replace('~[^a-z0-9]+~', '-', $s);
    $s = trim($s, '-');
    return $s !== '' ? substr($s, 0, 100) : 'event';
}

function eventPublicUrl(array $ev): string {
    return rtrim(APP_URL, '/') . '/events/view.php?slug=' . urlencode($ev['slug']);
}

function eventManageUrl(int $eventId): string {
    return rtrim(APP_URL, '/') . '/pages/event_manage.php?id=' . (int)$eventId;
}


// ══════════════════════════════════════════════════════════════
// 2. EVENT FETCH
// ══════════════════════════════════════════════════════════════

function eventGet(int $id): ?array {
    $st = getDB()->prepare("SELECT * FROM events WHERE id = ? LIMIT 1");
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function eventGetBySlug(string $slug): ?array {
    $st = getDB()->prepare("SELECT * FROM events WHERE slug = ? LIMIT 1");
    $st->execute([$slug]);
    return $st->fetch() ?: null;
}

/** All events organised by the current school. */
function eventsMineForSchool(int $schoolId, ?string $status = null): array {
    $sql = "SELECT * FROM events WHERE organiser_school_id = ?";
    $args = [$schoolId];
    if ($status) { $sql .= " AND status = ?"; $args[] = $status; }
    $sql .= " ORDER BY starts_at DESC, id DESC";
    $st = getDB()->prepare($sql);
    $st->execute($args);
    return $st->fetchAll();
}

/** Insert a new draft event. Returns new event id. */
function eventCreate(array $data, int $schoolId, int $userId): int {
    $db = getDB();

    $slugBase = eventsSlugify($data['title'] ?? 'event');
    // ensure unique
    $slug = $slugBase;
    $n = 2;
    while (true) {
        $exists = $db->prepare("SELECT 1 FROM events WHERE slug = ? LIMIT 1");
        $exists->execute([$slug]);
        if (!$exists->fetchColumn()) break;
        $slug = $slugBase . '-' . $n++;
        if ($n > 200) { $slug = $slugBase . '-' . bin2hex(random_bytes(3)); break; }
    }

    $cols = [
        'slug' => $slug,
        'organiser_school_id' => $schoolId,
        'federation_id' => $data['federation_id'] ?? null,
        'type' => in_array($data['type'] ?? '', ['championship','friendly','camp','seminar','meeting','exam'], true) ? $data['type'] : 'friendly',
        'title' => mb_substr(trim($data['title'] ?? ''), 0, 200),
        'subtitle' => mb_substr(trim($data['subtitle'] ?? ''), 0, 255) ?: null,
        'description' => trim($data['description'] ?? '') ?: null,
        'sport' => trim($data['sport'] ?? '') ?: null,
        'sport_style' => trim($data['sport_style'] ?? '') ?: null,
        'visibility' => in_array($data['visibility'] ?? '', ['public','unlisted','invite_only'], true) ? $data['visibility'] : 'public',
        'status' => 'draft',
        'venue_name' => trim($data['venue_name'] ?? '') ?: null,
        'venue_address' => trim($data['venue_address'] ?? '') ?: null,
        'venue_url' => trim($data['venue_url'] ?? '') ?: null,
        'starts_at' => $data['starts_at'] ?? null,
        'ends_at' => $data['ends_at'] ?? null,
        'registration_opens_at' => $data['registration_opens_at'] ?? null,
        'registration_closes_at' => $data['registration_closes_at'] ?? null,
        'payment_due_at' => $data['payment_due_at'] ?? null,
        'max_participants' => isset($data['max_participants']) && $data['max_participants'] !== '' ? (int)$data['max_participants'] : null,
        'ring_count' => max(1, (int)($data['ring_count'] ?? 1)),
        'fee_model' => in_array($data['fee_model'] ?? '', ['per_athlete','per_team','flat','free'], true) ? $data['fee_model'] : 'per_athlete',
        'fee_amount' => (float)($data['fee_amount'] ?? 0),
        'late_fee_amount' => (float)($data['late_fee_amount'] ?? 0),
        'late_fee_starts_at' => $data['late_fee_starts_at'] ?? null,
        'refund_policy' => trim($data['refund_policy'] ?? '') ?: null,
        'payment_methods' => eventsNormalizePaymentMethods($data['payment_methods'] ?? ['bank','iris','cash']),
        'bank_iban' => trim($data['bank_iban'] ?? '') ?: null,
        'bank_beneficiary' => trim($data['bank_beneficiary'] ?? '') ?: null,
        'bank_name' => trim($data['bank_name'] ?? '') ?: null,
        'bank_reference_template' => trim($data['bank_reference_template'] ?? '') ?: 'MASTER-EV{event_id}-CL{school_id}',
        'contact_email' => trim($data['contact_email'] ?? '') ?: null,
        'contact_phone' => trim($data['contact_phone'] ?? '') ?: null,
        'created_by' => $userId,
    ];

    $fields = array_keys($cols);
    $placeholders = implode(',', array_fill(0, count($fields), '?'));
    $sql = "INSERT INTO events (" . implode(',', $fields) . ") VALUES ($placeholders)";
    $st = $db->prepare($sql);
    $st->execute(array_values($cols));

    $newId = (int)$db->lastInsertId();
    auditLog('event_created', 'event', $newId, $cols['title']);

    // If admin has set a non-zero default creation fee, apply it to this
    // event and create the pending payment row. Safe to fail silently:
    // the columns may not exist yet on a pre-migration deploy.
    try {
        $feeDefault = eventCreationFeeDefault();
        if ($feeDefault > 0) {
            $db->prepare("UPDATE events
                             SET creation_fee_amount = ?,
                                 creation_fee_status = 'unpaid'
                           WHERE id = ?")
               ->execute([$feeDefault, $newId]);
            eventCreationFeeCreatePayment($newId, $schoolId, $feeDefault);
        }
    } catch (Throwable $e) {
        error_log('[MAster] event creation-fee wiring skipped: ' . $e->getMessage());
    }

    return $newId;
}

/** Update editable fields of an event (organiser scope). */
function eventUpdate(int $id, array $data, int $schoolId): void {
    $ev = eventGet($id);
    if (!$ev || (int)$ev['organiser_school_id'] !== $schoolId) {
        throw new RuntimeException('Δεν έχετε δικαίωμα επεξεργασίας.');
    }
    $editable = [
        'title','subtitle','description','type','sport','sport_style','visibility','status',
        'venue_name','venue_address','venue_url','starts_at','ends_at',
        'registration_opens_at','registration_closes_at','payment_due_at',
        'max_participants','ring_count','fee_model','fee_amount',
        'late_fee_amount','late_fee_starts_at','refund_policy',
        'bank_iban','bank_beneficiary','bank_name','bank_reference_template',
        'contact_email','contact_phone',
    ];
    $set = []; $args = [];
    foreach ($editable as $k) {
        if (!array_key_exists($k, $data)) continue;
        $v = $data[$k];
        if (in_array($k, ['title','subtitle','venue_name','venue_address','venue_url','bank_iban','bank_beneficiary','bank_name','contact_email','contact_phone','refund_policy','bank_reference_template','description','sport','sport_style'], true)) {
            $v = trim((string)$v);
            $v = $v === '' ? null : $v;
        }
        if (in_array($k, ['fee_amount','late_fee_amount'], true))   $v = (float)$v;
        if (in_array($k, ['max_participants','ring_count'], true))  $v = ($v === '' || $v === null) ? null : (int)$v;
        if ($k === 'type' && !in_array($v, ['championship','friendly','camp','seminar','meeting','exam'], true)) continue;
        if ($k === 'visibility' && !in_array($v, ['public','unlisted','invite_only'], true)) continue;
        if ($k === 'status' && !in_array($v, ['draft','open','closed','in_progress','completed','cancelled'], true)) continue;
        if ($k === 'fee_model' && !in_array($v, ['per_athlete','per_team','flat','free'], true)) continue;
        $set[] = "$k = ?";
        $args[] = $v;
    }
    if (array_key_exists('payment_methods', $data)) {
        $set[] = 'payment_methods = ?';
        $args[] = eventsNormalizePaymentMethods($data['payment_methods']);
    }
    if (!$set) return;
    $args[] = $id;
    $st = getDB()->prepare("UPDATE events SET " . implode(',', $set) . " WHERE id = ?");
    $st->execute($args);
    auditLog('event_updated', 'event', $id);
}

function eventsNormalizePaymentMethods($input): string {
    $valid = ['bank','iris','viva','stripe','cash'];
    if (is_string($input)) $input = array_map('trim', explode(',', $input));
    if (!is_array($input)) return 'bank,iris,cash';
    $out = array_values(array_intersect($valid, array_map('strtolower', $input)));
    return $out ? implode(',', $out) : 'bank,iris,cash';
}


// ══════════════════════════════════════════════════════════════
// 3. CATEGORIES
// ══════════════════════════════════════════════════════════════

function eventCategories(int $eventId): array {
    $st = getDB()->prepare("SELECT * FROM event_categories WHERE event_id = ? ORDER BY display_order, id");
    $st->execute([$eventId]);
    return $st->fetchAll();
}

function eventCategoryGet(int $categoryId): ?array {
    $st = getDB()->prepare("SELECT * FROM event_categories WHERE id = ? LIMIT 1");
    $st->execute([$categoryId]);
    return $st->fetch() ?: null;
}

function eventCategoryCreate(int $eventId, array $data): int {
    $db = getDB();
    $format = in_array($data['format'] ?? '', ['single_elim','double_elim','round_robin','pool_ko','pool_only','exhibition','group_weight'], true)
              ? $data['format'] : 'single_elim';
    $marginKg = ($data['weight_margin_kg'] ?? '') !== '' ? (float)$data['weight_margin_kg'] : 0.0;

    // Best-effort include the new column; safe if migration 009 hasn't run
    // (we degrade gracefully to the old column set).
    try {
        $sql = "INSERT INTO event_categories
                  (event_id, name, gender, min_age, max_age, min_weight, max_weight,
                   belt_from, belt_to, style, max_slots, fee_override, format, pool_size, weight_margin_kg, display_order)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
        $st = $db->prepare($sql);
        $st->execute([
            $eventId,
            mb_substr(trim($data['name'] ?? 'Κατηγορία'), 0, 120),
            in_array($data['gender'] ?? '', ['M','F','MX'], true) ? $data['gender'] : 'MX',
            $data['min_age'] !== '' ? (int)$data['min_age'] : null,
            $data['max_age'] !== '' ? (int)$data['max_age'] : null,
            $data['min_weight'] !== '' ? (float)$data['min_weight'] : null,
            $data['max_weight'] !== '' ? (float)$data['max_weight'] : null,
            trim($data['belt_from'] ?? '') ?: null,
            trim($data['belt_to'] ?? '') ?: null,
            trim($data['style'] ?? '') ?: null,
            $data['max_slots'] !== '' ? (int)$data['max_slots'] : null,
            $data['fee_override'] !== '' ? (float)$data['fee_override'] : null,
            $format,
            max(2, (int)($data['pool_size'] ?? 4)),
            $marginKg,
            (int)($data['display_order'] ?? 0),
        ]);
        return (int)$db->lastInsertId();
    } catch (Throwable $e) {
        // Legacy fallback (pre-migration-009 column set)
        $sql = "INSERT INTO event_categories
                  (event_id, name, gender, min_age, max_age, min_weight, max_weight,
                   belt_from, belt_to, style, max_slots, fee_override, format, pool_size, display_order)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
        $st = $db->prepare($sql);
        $st->execute([
            $eventId,
            mb_substr(trim($data['name'] ?? 'Κατηγορία'), 0, 120),
            in_array($data['gender'] ?? '', ['M','F','MX'], true) ? $data['gender'] : 'MX',
            $data['min_age'] !== '' ? (int)$data['min_age'] : null,
            $data['max_age'] !== '' ? (int)$data['max_age'] : null,
            $data['min_weight'] !== '' ? (float)$data['min_weight'] : null,
            $data['max_weight'] !== '' ? (float)$data['max_weight'] : null,
            trim($data['belt_from'] ?? '') ?: null,
            trim($data['belt_to'] ?? '') ?: null,
            trim($data['style'] ?? '') ?: null,
            $data['max_slots'] !== '' ? (int)$data['max_slots'] : null,
            $data['fee_override'] !== '' ? (float)$data['fee_override'] : null,
            $format,
            max(2, (int)($data['pool_size'] ?? 4)),
            (int)($data['display_order'] ?? 0),
        ]);
        return (int)$db->lastInsertId();
    }
}

function eventCategoryDelete(int $categoryId, int $eventId): void {
    $st = getDB()->prepare("DELETE FROM event_categories WHERE id = ? AND event_id = ?");
    $st->execute([$categoryId, $eventId]);
}

// ══════════════════════════════════════════════════════════════
// Age groups + Weight classes (new hierarchical model, optional).
// When the organiser clicks "Generate categories", we materialise
// the cartesian product into event_categories so the rest of the
// bracket/registration pipeline keeps working unchanged.
// ══════════════════════════════════════════════════════════════

function eventAgeGroups(int $eventId): array {
    $st = getDB()->prepare("SELECT * FROM event_age_groups WHERE event_id = ? ORDER BY sort_order ASC, id ASC");
    $st->execute([$eventId]);
    return $st->fetchAll();
}

function eventAgeGroupCreate(int $eventId, array $data): int {
    $st = getDB()->prepare("INSERT INTO event_age_groups
        (event_id, name, min_age, max_age, gender, sort_order, notes)
        VALUES (?, ?, ?, ?, ?, ?, ?)");
    $st->execute([
        $eventId,
        mb_substr(trim($data['name'] ?? 'Age group'), 0, 80),
        ($data['min_age'] ?? '') !== '' ? (int)$data['min_age'] : null,
        ($data['max_age'] ?? '') !== '' ? (int)$data['max_age'] : null,
        in_array($data['gender'] ?? '', ['M','F','MX'], true) ? $data['gender'] : 'MX',
        (int)($data['sort_order'] ?? 0),
        mb_substr(trim($data['notes'] ?? ''), 0, 255) ?: null,
    ]);
    return (int)getDB()->lastInsertId();
}

function eventAgeGroupDelete(int $ageGroupId, int $eventId): void {
    $st = getDB()->prepare("DELETE FROM event_age_groups WHERE id = ? AND event_id = ?");
    $st->execute([$ageGroupId, $eventId]);
}

function eventWeightClasses(int $ageGroupId): array {
    $st = getDB()->prepare("SELECT * FROM event_weight_classes WHERE age_group_id = ? ORDER BY sort_order ASC, min_weight ASC, id ASC");
    $st->execute([$ageGroupId]);
    return $st->fetchAll();
}

function eventWeightClassCreate(int $ageGroupId, array $data): int {
    $st = getDB()->prepare("INSERT INTO event_weight_classes
        (age_group_id, name, min_weight, max_weight, sort_order, fee_amount)
        VALUES (?, ?, ?, ?, ?, ?)");
    $st->execute([
        $ageGroupId,
        mb_substr(trim($data['name'] ?? 'Class'), 0, 60),
        ($data['min_weight'] ?? '') !== '' ? (float)$data['min_weight'] : null,
        ($data['max_weight'] ?? '') !== '' ? (float)$data['max_weight'] : null,
        (int)($data['sort_order'] ?? 0),
        ($data['fee_amount'] ?? '') !== '' ? (float)$data['fee_amount'] : null,
    ]);
    return (int)getDB()->lastInsertId();
}

function eventWeightClassDelete(int $classId, int $ageGroupId): void {
    $st = getDB()->prepare("DELETE FROM event_weight_classes WHERE id = ? AND age_group_id = ?");
    $st->execute([$classId, $ageGroupId]);
}

/**
 * Generate event_categories rows from every (age group × weight class)
 * combination that isn't already generated. Idempotent — skips pairs
 * where an event_categories row with the same generated_from_* pair
 * already exists.
 *
 * Returns the number of new categories created.
 */
function eventGenerateCategoriesFromAgeWeight(int $eventId): int {
    $db = getDB();
    $created = 0;

    $groups = eventAgeGroups($eventId);
    if (!$groups) return 0;

    $existing = $db->prepare("SELECT COUNT(*) FROM event_categories
                              WHERE event_id = ? AND generated_from_age_group_id = ? AND generated_from_weight_class_id = ?");

    $ins = $db->prepare("INSERT INTO event_categories
        (event_id, name, gender, min_age, max_age, min_weight, max_weight,
         generated_from_age_group_id, generated_from_weight_class_id,
         format, pool_size, display_order)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'single_elim', 4, ?)");

    $order = 0;
    foreach ($groups as $ag) {
        $classes = eventWeightClasses((int)$ag['id']);
        if (!$classes) {
            // Age group with no weight classes → one category per age group
            $order++;
            $existing->execute([$eventId, (int)$ag['id'], 0]);
            if ((int)$existing->fetchColumn() > 0) continue;
            $name = $ag['name'];
            $ins->execute([
                $eventId, mb_substr($name, 0, 120), $ag['gender'],
                $ag['min_age'], $ag['max_age'], null, null,
                (int)$ag['id'], null, $order
            ]);
            $created++;
            continue;
        }
        foreach ($classes as $wc) {
            $order++;
            $existing->execute([$eventId, (int)$ag['id'], (int)$wc['id']]);
            if ((int)$existing->fetchColumn() > 0) continue;
            $name = trim($ag['name'] . ' — ' . $wc['name']);
            $ins->execute([
                $eventId, mb_substr($name, 0, 120), $ag['gender'],
                $ag['min_age'], $ag['max_age'],
                $wc['min_weight'], $wc['max_weight'],
                (int)$ag['id'], (int)$wc['id'], $order
            ]);
            $created++;
        }
    }

    if ($created > 0) {
        auditLog('event_categories_generated', 'event', $eventId, (string)$created);
    }
    return $created;
}


// ══════════════════════════════════════════════════════════════
// 4. REGISTRATION & ELIGIBILITY
// ══════════════════════════════════════════════════════════════

/** Basic eligibility check: age/gender/weight/belt against category. */
function eventAthleteEligible(array $athlete, array $cat): array {
    $errors = [];
    // Gender
    if ($cat['gender'] !== 'MX' && !empty($athlete['gender'])) {
        $g = strtoupper(substr($athlete['gender'], 0, 1));
        if ($g !== $cat['gender']) $errors[] = 'Το φύλο δεν ταιριάζει στην κατηγορία.';
    }
    // Age (at event start — but Phase 1: today)
    if (!empty($athlete['birthdate']) && ($athlete['birthdate'] !== '0000-00-00')) {
        try {
            $age = (new DateTime())->diff(new DateTime($athlete['birthdate']))->y;
            if ($cat['min_age'] !== null && $age < (int)$cat['min_age']) $errors[] = 'Ηλικία μικρότερη του ελάχιστου (' . $cat['min_age'] . ').';
            if ($cat['max_age'] !== null && $age > (int)$cat['max_age']) $errors[] = 'Ηλικία μεγαλύτερη του μέγιστου (' . $cat['max_age'] . ').';
        } catch (Exception $e) {}
    }
    return $errors;
}

/** Register an athlete into an event category. Returns registration id. */
function eventRegisterAthlete(int $eventId, int $categoryId, int $athleteId, int $registeringSchoolId, int $userId, string $notes = ''): int {
    $db = getDB();
    $ev = eventGet($eventId);
    if (!$ev) throw new RuntimeException('Ο διαγωνισμός δεν βρέθηκε.');
    if (!in_array($ev['status'], ['open','draft'], true)) throw new RuntimeException('Οι εγγραφές δεν είναι ανοιχτές.');
    if ($ev['registration_closes_at'] && strtotime($ev['registration_closes_at']) < time()) {
        throw new RuntimeException('Η προθεσμία εγγραφής έχει λήξει.');
    }
    if ($ev['max_participants']) {
        $cur = $db->prepare("SELECT COUNT(*) FROM event_registrations WHERE event_id = ? AND status NOT IN ('rejected','withdrawn')");
        $cur->execute([$eventId]);
        if ((int)$cur->fetchColumn() >= (int)$ev['max_participants']) throw new RuntimeException('Έχει συμπληρωθεί ο μέγιστος αριθμός συμμετεχόντων.');
    }
    $cat = eventCategoryGet($categoryId);
    if (!$cat || (int)$cat['event_id'] !== $eventId) throw new RuntimeException('Μη έγκυρη κατηγορία.');

    // Athlete belongs to registering school
    $ath = $db->prepare("SELECT * FROM athletes WHERE id = ? AND school_id = ? LIMIT 1");
    $ath->execute([$athleteId, $registeringSchoolId]);
    $athlete = $ath->fetch();
    if (!$athlete) throw new RuntimeException('Ο αθλητής δεν ανήκει στη σχολή σας.');

    // Slot check (category)
    if ($cat['max_slots']) {
        $used = $db->prepare("SELECT COUNT(*) FROM event_registrations WHERE category_id = ? AND status NOT IN ('rejected','withdrawn')");
        $used->execute([$categoryId]);
        if ((int)$used->fetchColumn() >= (int)$cat['max_slots']) throw new RuntimeException('Η κατηγορία είναι πλήρης.');
    }

    // Idempotency: same athlete+event+cat should not duplicate
    $dup = $db->prepare("SELECT id FROM event_registrations WHERE event_id = ? AND category_id = ? AND athlete_id = ? LIMIT 1");
    $dup->execute([$eventId, $categoryId, $athleteId]);
    if ($existing = $dup->fetchColumn()) return (int)$existing;

    $amount = $cat['fee_override'] !== null ? (float)$cat['fee_override'] : (float)$ev['fee_amount'];
    $snapshot = json_encode([
        'full_name' => $athlete['full_name'] ?? '',
        'birthdate' => $athlete['birthdate'] ?? null,
        'gender' => $athlete['gender'] ?? null,
        'sport' => $athlete['sport'] ?? null,
        'belt' => $athlete['belt'] ?? null,
    ], JSON_UNESCAPED_UNICODE);

    $sql = "INSERT INTO event_registrations
              (event_id, category_id, registering_school_id, athlete_id, coach_user_id,
               athlete_snapshot, status, payment_status, amount, notes_participant)
            VALUES (?,?,?,?,?,?, 'pending','unpaid',?,?)";
    $st = $db->prepare($sql);
    $st->execute([$eventId, $categoryId, $registeringSchoolId, $athleteId, $userId, $snapshot, $amount, mb_substr($notes, 0, 500)]);
    $regId = (int)$db->lastInsertId();

    auditLog('event_registered', 'event_registration', $regId, "event=$eventId cat=$categoryId athlete=$athleteId");
    return $regId;
}

function eventRegistrationsForOrganiser(int $eventId): array {
    $sql = "SELECT r.*, c.name AS cat_name, s.name AS school_name, a.full_name AS athlete_name
            FROM event_registrations r
            LEFT JOIN event_categories c ON c.id = r.category_id
            LEFT JOIN schools s ON s.id = r.registering_school_id
            LEFT JOIN athletes a ON a.id = r.athlete_id
            WHERE r.event_id = ?
            ORDER BY r.created_at DESC";
    $st = getDB()->prepare($sql);
    $st->execute([$eventId]);
    return $st->fetchAll();
}

// ══════════════════════════════════════════════════════════════
// Camp-specific extras (attached to any event_registrations row).
// Only relevant when the parent event.type = 'camp'. Fully
// optional — a camp registration works without camp_registrations.
// ══════════════════════════════════════════════════════════════

function campRegistrationGet(int $registrationId): ?array {
    $st = getDB()->prepare("SELECT * FROM camp_registrations WHERE registration_id = ? LIMIT 1");
    $st->execute([$registrationId]);
    $r = $st->fetch();
    return $r ?: null;
}

/**
 * Insert or update camp-specific fields for a registration.
 * Whitelists inputs; safe to call from POST forms.
 */
function campRegistrationSave(int $registrationId, array $data): int {
    $db = getDB();

    $cols = [
        'arrival_at'              => ($data['arrival_at']              ?? '') ?: null,
        'departure_at'            => ($data['departure_at']            ?? '') ?: null,
        'tshirt_size'             => in_array($data['tshirt_size'] ?? '', ['XS','S','M','L','XL','XXL','3XL'], true) ? $data['tshirt_size'] : null,
        'dietary_notes'           => mb_substr(trim($data['dietary_notes']       ?? ''), 0, 255) ?: null,
        'medical_notes'           => mb_substr(trim($data['medical_notes']       ?? ''), 0, 500) ?: null,
        'roommate_preference'     => mb_substr(trim($data['roommate_preference'] ?? ''), 0, 120) ?: null,
        'accompanying_adults'     => max(0, min(20, (int)($data['accompanying_adults'] ?? 0))),
        'transportation'          => in_array($data['transportation'] ?? '', ['own','shared_bus','pickup_needed'], true) ? $data['transportation'] : null,
        'emergency_contact_name'  => mb_substr(trim($data['emergency_contact_name']  ?? ''), 0, 120) ?: null,
        'emergency_contact_phone' => mb_substr(trim($data['emergency_contact_phone'] ?? ''), 0, 40)  ?: null,
        'notes'                   => trim($data['notes'] ?? '') ?: null,
    ];

    $existing = campRegistrationGet($registrationId);
    if ($existing) {
        $set = implode(',', array_map(fn($k) => "$k = ?", array_keys($cols)));
        $st = $db->prepare("UPDATE camp_registrations SET $set WHERE registration_id = ?");
        $st->execute([...array_values($cols), $registrationId]);
        return (int)$existing['id'];
    }
    $fields = array_keys($cols);
    $ph     = implode(',', array_fill(0, count($fields), '?'));
    $sql    = "INSERT INTO camp_registrations (registration_id, " . implode(',', $fields) . ") VALUES (?, $ph)";
    $st = $db->prepare($sql);
    $st->execute([$registrationId, ...array_values($cols)]);
    return (int)$db->lastInsertId();
}

function eventRegistrationsForParticipant(int $eventId, int $schoolId): array {
    $sql = "SELECT r.*, c.name AS cat_name, a.full_name AS athlete_name
            FROM event_registrations r
            LEFT JOIN event_categories c ON c.id = r.category_id
            LEFT JOIN athletes a ON a.id = r.athlete_id
            WHERE r.event_id = ? AND r.registering_school_id = ?
            ORDER BY a.full_name";
    $st = getDB()->prepare($sql);
    $st->execute([$eventId, $schoolId]);
    return $st->fetchAll();
}

function eventRegistrationUpdateStatus(int $regId, int $eventId, string $status, ?int $verifiedBy = null): void {
    if (!in_array($status, ['pending','approved','rejected','withdrawn','checked_in','no_show','disqualified'], true)) return;
    $st = getDB()->prepare("UPDATE event_registrations SET status = ?, verified_by = COALESCE(?, verified_by) WHERE id = ? AND event_id = ?");
    $st->execute([$status, $verifiedBy, $regId, $eventId]);
    auditLog('event_reg_status', 'event_registration', $regId, "→ $status");
}

function eventRegistrationWithdraw(int $regId, int $registeringSchoolId, string $reason = ''): void {
    $st = getDB()->prepare("UPDATE event_registrations
        SET status = 'withdrawn', withdrew_at = NOW(), withdraw_reason = ?
        WHERE id = ? AND registering_school_id = ?");
    $st->execute([mb_substr($reason, 0, 255), $regId, $registeringSchoolId]);
    auditLog('event_reg_withdrawn', 'event_registration', $regId, $reason);
}


// ══════════════════════════════════════════════════════════════
// 5. PAYMENTS
// ══════════════════════════════════════════════════════════════

/** Bundle all unpaid regs from a given school into one payment. */
function eventPaymentCreate(int $eventId, int $payingSchoolId, string $method, int $userId): int {
    $db = getDB();
    $ev = eventGet($eventId);
    if (!$ev) throw new RuntimeException('Event not found.');
    if (!in_array($method, explode(',', $ev['payment_methods']), true)) {
        throw new RuntimeException('Ο τρόπος πληρωμής δεν υποστηρίζεται σε αυτό το event.');
    }

    // Fetch unpaid regs for this school
    $q = $db->prepare("SELECT r.id, r.amount FROM event_registrations r
        LEFT JOIN event_payment_registrations pr ON pr.registration_id = r.id
        WHERE r.event_id = ? AND r.registering_school_id = ? AND r.payment_status IN ('unpaid','proof_uploaded') AND pr.registration_id IS NULL");
    $q->execute([$eventId, $payingSchoolId]);
    $regs = $q->fetchAll();
    if (!$regs) throw new RuntimeException('Δεν υπάρχουν εκκρεμείς εγγραφές για πληρωμή.');

    $total = 0.0;
    foreach ($regs as $r) $total += (float)$r['amount'];

    $ref = str_replace(
        ['{event_id}','{school_id}'],
        [(int)$eventId, (int)$payingSchoolId],
        $ev['bank_reference_template'] ?: 'MASTER-EV{event_id}-CL{school_id}'
    ) . '-' . strtoupper(bin2hex(random_bytes(2)));

    $ins = $db->prepare("INSERT INTO event_payments
        (event_id, paying_school_id, amount, currency, method, reference_code, status)
        VALUES (?,?,?,?,?,?, 'pending')");
    $ins->execute([$eventId, $payingSchoolId, $total, $ev['currency'] ?: 'EUR', $method, $ref]);
    $payId = (int)$db->lastInsertId();

    $link = $db->prepare("INSERT INTO event_payment_registrations (payment_id, registration_id) VALUES (?, ?)");
    foreach ($regs as $r) $link->execute([$payId, (int)$r['id']]);

    auditLog('event_payment_created', 'event_payment', $payId, "event=$eventId school=$payingSchoolId total=$total method=$method");
    return $payId;
}

function eventPaymentAttachProof(int $paymentId, int $payingSchoolId, string $filePath): void {
    $st = getDB()->prepare("UPDATE event_payments
        SET proof_file_path = ?, proof_uploaded_at = NOW(), status = 'proof_uploaded'
        WHERE id = ? AND paying_school_id = ?");
    $st->execute([$filePath, $paymentId, $payingSchoolId]);
    // Also bump per-reg status
    $upd = getDB()->prepare("UPDATE event_registrations r
        JOIN event_payment_registrations pr ON pr.registration_id = r.id
        SET r.payment_status = 'proof_uploaded'
        WHERE pr.payment_id = ?");
    $upd->execute([$paymentId]);
    auditLog('event_payment_proof', 'event_payment', $paymentId);
}

function eventPaymentVerify(int $paymentId, int $eventId, int $verifiedBy, string $notes = ''): void {
    $db = getDB();
    $st = $db->prepare("UPDATE event_payments
        SET status = 'verified', verified_by = ?, verified_at = NOW(), verification_notes = ?
        WHERE id = ? AND event_id = ?");
    $st->execute([$verifiedBy, mb_substr($notes, 0, 500), $paymentId, $eventId]);

    $upd = $db->prepare("UPDATE event_registrations r
        JOIN event_payment_registrations pr ON pr.registration_id = r.id
        SET r.payment_status = 'verified', r.paid_at = NOW(), r.verified_at = NOW(), r.verified_by = ?
        WHERE pr.payment_id = ?");
    $upd->execute([$verifiedBy, $paymentId]);

    // Also sync event.creation_fee_status when this was a creation-fee payment
    try { eventCreationFeeSyncFromPayment($eventId); } catch (Throwable $e) {}

    auditLog('event_payment_verified', 'event_payment', $paymentId);
}

function eventPaymentRefund(int $paymentId, int $eventId, int $userId, float $amount, string $notes = ''): void {
    $db = getDB();
    $st = $db->prepare("UPDATE event_payments
        SET status = 'refunded', verified_by = ?, verified_at = NOW(),
            verification_notes = CONCAT_WS(' | ', verification_notes, ?)
        WHERE id = ? AND event_id = ?");
    $st->execute([$userId, 'REFUND ' . number_format($amount, 2) . '€ ' . $notes, $paymentId, $eventId]);

    $upd = $db->prepare("UPDATE event_registrations r
        JOIN event_payment_registrations pr ON pr.registration_id = r.id
        SET r.payment_status = 'refunded'
        WHERE pr.payment_id = ?");
    $upd->execute([$paymentId]);
    auditLog('event_payment_refunded', 'event_payment', $paymentId, "amount=$amount");
}

/** Compute allowed refund amount for this payment at this moment. */
function eventPaymentRefundQuote(array $ev, array $payment): array {
    $now       = time();
    $eventStart= $ev['starts_at'] ? strtotime($ev['starts_at']) : 0;
    $daysToStart = $eventStart ? max(0, (int)floor(($eventStart - $now) / 86400)) : 999;

    $fullDays    = (int)($ev['refund_full_until_days']    ?? 14);
    $partialDays = (int)($ev['refund_partial_until_days'] ?? 7);
    $fullPct     = (int)($ev['refund_pct_full']    ?? 100);
    $partPct     = (int)($ev['refund_pct_partial'] ?? 50);
    $amount      = (float)$payment['amount'];

    if ($daysToStart >= $fullDays)    return ['pct' => $fullPct, 'amount' => round($amount * $fullPct / 100, 2), 'reason' => "Full refund ($daysToStart d before start ≥ $fullDays)"];
    if ($daysToStart >= $partialDays) return ['pct' => $partPct, 'amount' => round($amount * $partPct / 100, 2), 'reason' => "Partial refund ($daysToStart d before start ≥ $partialDays)"];
    return ['pct' => 0, 'amount' => 0.0, 'reason' => "Δεν επιτρέπεται επιστροφή τόσο κοντά στο event ($daysToStart d)"];
}

function eventPaymentReject(int $paymentId, int $eventId, int $userId, string $notes = ''): void {
    $st = getDB()->prepare("UPDATE event_payments
        SET status = 'rejected', verified_by = ?, verified_at = NOW(), verification_notes = ?
        WHERE id = ? AND event_id = ?");
    $st->execute([$userId, mb_substr($notes, 0, 500), $paymentId, $eventId]);

    $upd = getDB()->prepare("UPDATE event_registrations r
        JOIN event_payment_registrations pr ON pr.registration_id = r.id
        SET r.payment_status = 'unpaid'
        WHERE pr.payment_id = ?");
    $upd->execute([$paymentId]);
    auditLog('event_payment_rejected', 'event_payment', $paymentId, $notes);
}

function eventPaymentsForEvent(int $eventId): array {
    $st = getDB()->prepare("SELECT p.*, s.name AS school_name
        FROM event_payments p
        LEFT JOIN schools s ON s.id = p.paying_school_id
        WHERE p.event_id = ? ORDER BY p.created_at DESC");
    $st->execute([$eventId]);
    return $st->fetchAll();
}

function eventPaymentsForSchool(int $eventId, int $schoolId): array {
    $st = getDB()->prepare("SELECT * FROM event_payments WHERE event_id = ? AND paying_school_id = ? ORDER BY created_at DESC");
    $st->execute([$eventId, $schoolId]);
    return $st->fetchAll();
}

/**
 * All payments for a school across every event.
 * Used by /pages/event_invoices.php (school-side invoices tab).
 */
function eventPaymentsAllForSchool(int $schoolId): array {
    $st = getDB()->prepare("
        SELECT p.*, e.title AS event_title, e.slug AS event_slug, e.starts_at AS event_starts_at
          FROM event_payments p
          JOIN events e ON e.id = p.event_id
         WHERE p.paying_school_id = ?
         ORDER BY p.created_at DESC");
    $st->execute([$schoolId]);
    return $st->fetchAll();
}

/**
 * All verified payments platform-wide (for admin invoice upload page).
 * Optional filter: only rows still missing an invoice.
 */
function eventPaymentsForAdmin(bool $pendingInvoiceOnly = false): array {
    $where = ["p.status = 'verified'"];
    if ($pendingInvoiceOnly) $where[] = "(p.invoice_file_path IS NULL OR p.invoice_file_path = '')";
    $sql = "SELECT p.*, e.title AS event_title, e.slug AS event_slug,
                   s.name AS school_name
              FROM event_payments p
              JOIN events e  ON e.id = p.event_id
         LEFT JOIN schools s ON s.id = p.paying_school_id
             WHERE " . implode(' AND ', $where) . "
             ORDER BY p.verified_at DESC, p.id DESC";
    return getDB()->query($sql)->fetchAll();
}

function eventPaymentGet(int $paymentId): ?array {
    $st = getDB()->prepare("SELECT * FROM event_payments WHERE id = ? LIMIT 1");
    $st->execute([$paymentId]);
    $row = $st->fetch();
    return $row ?: null;
}

// ══════════════════════════════════════════════════════════════
// Event creation fee (€50-style organiser fee).
// Non-breaking: fee amount defaults to 0 → status 'waived' → no gate.
// Set setting 'event_creation_fee_default' to a non-zero number to
// require it on newly created events. Set per-event via
// events.creation_fee_amount.
// ══════════════════════════════════════════════════════════════

/** Global default fee amount (0 = feature effectively off). */
function eventCreationFeeDefault(): float {
    return (float)(getSetting('event_creation_fee_default', '0'));
}

/** Does THIS event actually require a creation fee to be paid? */
function eventRequiresCreationFee(array $event): bool {
    return ((float)($event['creation_fee_amount'] ?? 0)) > 0
        && (($event['creation_fee_status'] ?? 'waived') !== 'waived');
}

/** Was it paid + verified? */
function eventCreationFeePaid(array $event): bool {
    if (!eventRequiresCreationFee($event)) return true; // waived counts as paid
    return ($event['creation_fee_status'] ?? '') === 'verified';
}

/**
 * Get the single event_payments row for this event's creation fee
 * (or null if none created yet).
 */
function eventCreationFeePayment(int $eventId): ?array {
    $st = getDB()->prepare("SELECT * FROM event_payments
                             WHERE event_id = ? AND purpose = 'creation'
                             ORDER BY id DESC LIMIT 1");
    $st->execute([$eventId]);
    $row = $st->fetch();
    return $row ?: null;
}

/**
 * Create the initial creation-fee payment row (idempotent — returns
 * existing id if one is already pending). Called by the organiser
 * from the event manage page.
 */
function eventCreationFeeCreatePayment(int $eventId, int $schoolId, float $amount): int {
    $existing = eventCreationFeePayment($eventId);
    if ($existing) return (int)$existing['id'];

    $db = getDB();
    $st = $db->prepare("INSERT INTO event_payments
        (event_id, paying_school_id, purpose, amount, currency, method, status)
        VALUES (?, ?, 'creation', ?, 'EUR', 'bank', 'pending')");
    $st->execute([$eventId, $schoolId, $amount]);
    $pid = (int)$db->lastInsertId();

    // Mark event as unpaid (creation fee owed)
    $db->prepare("UPDATE events SET creation_fee_status = 'unpaid' WHERE id = ?")->execute([$eventId]);
    return $pid;
}

/**
 * Reflect a payment status change back onto the event's
 * creation_fee_status column. Call this after an admin verifies
 * / rejects the creation-fee payment via existing event_payments
 * flows.
 */
function eventCreationFeeSyncFromPayment(int $eventId): void {
    $pay = eventCreationFeePayment($eventId);
    if (!$pay) return;
    $map = [
        'pending'         => 'unpaid',
        'proof_uploaded'  => 'proof_uploaded',
        'verified'        => 'verified',
        'rejected'        => 'unpaid',
        'refunded'        => 'waived',
    ];
    $newStatus = $map[$pay['status']] ?? 'unpaid';
    getDB()->prepare("UPDATE events SET creation_fee_status = ? WHERE id = ?")
           ->execute([$newStatus, $eventId]);
}

/**
 * Persist an uploaded invoice PDF for a payment.
 * Stores path under uploads/events/invoices/{payment_id}-{random}.pdf
 * and updates the payment row.
 *
 * @param int    $paymentId  target event_payments.id
 * @param string $tmpPath    $_FILES[...]['tmp_name']
 * @param string $origName   original filename (used to enforce .pdf)
 * @param int    $uploaderId user id doing the upload (admin/superadmin)
 * @return string            relative path saved to DB
 * @throws RuntimeException  on validation failure
 */
function eventPaymentUploadInvoice(int $paymentId, string $tmpPath, string $origName, int $uploaderId): string {
    if (!is_file($tmpPath) || !is_uploaded_file($tmpPath)) {
        throw new RuntimeException('Δεν βρέθηκε ανεβασμένο αρχείο.');
    }
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    if ($ext !== 'pdf') {
        throw new RuntimeException('Επιτρέπονται μόνο αρχεία PDF.');
    }
    $size = @filesize($tmpPath) ?: 0;
    if ($size <= 0 || $size > 10 * 1024 * 1024) {
        throw new RuntimeException('Το αρχείο πρέπει να είναι 0-10 MB.');
    }

    // Verify payment exists
    $pay = eventPaymentGet($paymentId);
    if (!$pay) throw new RuntimeException('Η πληρωμή δεν βρέθηκε.');

    // Destination — private-ish subdir, served via download.php
    $baseDir = __DIR__ . '/../uploads/events/invoices';
    if (!is_dir($baseDir)) @mkdir($baseDir, 0755, true);
    if (!is_dir($baseDir)) throw new RuntimeException('Δεν μπόρεσε να δημιουργηθεί ο φάκελος αποθήκευσης.');

    $random  = bin2hex(random_bytes(6));
    $fname   = sprintf('%d-%s.pdf', $paymentId, $random);
    $dest    = $baseDir . '/' . $fname;
    if (!@move_uploaded_file($tmpPath, $dest)) {
        throw new RuntimeException('Η αποθήκευση του αρχείου απέτυχε.');
    }
    @chmod($dest, 0644);

    $relPath = 'uploads/events/invoices/' . $fname;

    getDB()->prepare("UPDATE event_payments
                         SET invoice_file_path  = ?,
                             invoice_uploaded_at = NOW(),
                             invoice_uploaded_by = ?
                       WHERE id = ?")
           ->execute([$relPath, $uploaderId, $paymentId]);

    auditLog('event_invoice_uploaded', 'event_payment', $paymentId, $fname);
    return $relPath;
}

/**
 * Remove an uploaded invoice (admin action).
 */
function eventPaymentRemoveInvoice(int $paymentId): void {
    $pay = eventPaymentGet($paymentId);
    if (!$pay || empty($pay['invoice_file_path'])) return;

    $full = __DIR__ . '/../' . ltrim($pay['invoice_file_path'], '/');
    if (is_file($full)) @unlink($full);

    getDB()->prepare("UPDATE event_payments
                         SET invoice_file_path  = NULL,
                             invoice_uploaded_at = NULL,
                             invoice_uploaded_by = NULL
                       WHERE id = ?")
           ->execute([$paymentId]);

    auditLog('event_invoice_removed', 'event_payment', $paymentId);
}


// ══════════════════════════════════════════════════════════════
// 6. PUBLIC DISCOVERY
// ══════════════════════════════════════════════════════════════

function eventsPublicSearch(array $filters = [], int $limit = 40, int $offset = 0): array {
    $where = ["visibility = 'public'", "status IN ('open','in_progress','completed')"];
    $args  = [];
    if (!empty($filters['q'])) {
        $where[] = "(title LIKE ? OR subtitle LIKE ? OR description LIKE ? OR venue_name LIKE ?)";
        $q = '%' . str_replace(['%','_'], ['\\%','\\_'], $filters['q']) . '%';
        array_push($args, $q, $q, $q, $q);
    }
    if (!empty($filters['sport'])) { $where[] = "sport = ?"; $args[] = $filters['sport']; }
    if (!empty($filters['type']))  { $where[] = "type = ?";  $args[] = $filters['type']; }
    if (!empty($filters['upcoming'])) { $where[] = "(ends_at IS NULL OR ends_at >= NOW())"; }

    $sql = "SELECT e.*, s.name AS organiser_name
            FROM events e
            LEFT JOIN schools s ON s.id = e.organiser_school_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY (starts_at IS NULL), starts_at ASC, id DESC
            LIMIT " . (int)$limit . " OFFSET " . (int)$offset;
    $st = getDB()->prepare($sql);
    $st->execute($args);
    return $st->fetchAll();
}

/**
 * Return all public events whose date range overlaps the given month.
 * Used by the /events/calendar.php monthly grid view.
 *
 * @param int $year   Full year (e.g. 2026)
 * @param int $month  1–12
 * @return array      List of event rows with organiser_name joined
 */
function eventsForMonth(int $year, int $month): array {
    // Clamp inputs — safe against ?y=99999
    $month = max(1, min(12, $month));
    $year  = max(2000, min(2100, $year));

    $monthStart = sprintf('%04d-%02d-01 00:00:00', $year, $month);
    $monthEnd   = date('Y-m-t 23:59:59', strtotime($monthStart));

    // An event overlaps the month if:
    //   starts_at (or ends_at as fallback) falls inside the window, OR
    //   it spans across the window (starts before + ends after).
    $sql = "SELECT e.*, s.name AS organiser_name
              FROM events e
              LEFT JOIN schools s ON s.id = e.organiser_school_id
             WHERE e.visibility = 'public'
               AND e.status IN ('open','in_progress','completed')
               AND (
                     (e.starts_at IS NOT NULL AND e.starts_at BETWEEN ? AND ?)
                  OR (e.ends_at   IS NOT NULL AND e.ends_at   BETWEEN ? AND ?)
                  OR (e.starts_at IS NOT NULL AND e.ends_at IS NOT NULL
                      AND e.starts_at <= ? AND e.ends_at >= ?)
               )
             ORDER BY (starts_at IS NULL), starts_at ASC, id ASC";
    $st = getDB()->prepare($sql);
    $st->execute([$monthStart, $monthEnd, $monthStart, $monthEnd, $monthStart, $monthEnd]);
    return $st->fetchAll();
}

function eventsPublicCount(array $filters = []): int {
    $where = ["visibility = 'public'", "status IN ('open','in_progress','completed')"];
    $args  = [];
    if (!empty($filters['q'])) {
        $where[] = "(title LIKE ? OR subtitle LIKE ? OR description LIKE ? OR venue_name LIKE ?)";
        $q = '%' . str_replace(['%','_'], ['\\%','\\_'], $filters['q']) . '%';
        array_push($args, $q, $q, $q, $q);
    }
    if (!empty($filters['sport'])) { $where[] = "sport = ?"; $args[] = $filters['sport']; }
    if (!empty($filters['type']))  { $where[] = "type = ?";  $args[] = $filters['type']; }
    if (!empty($filters['upcoming'])) { $where[] = "(ends_at IS NULL OR ends_at >= NOW())"; }
    $sql = "SELECT COUNT(*) FROM events WHERE " . implode(' AND ', $where);
    $st = getDB()->prepare($sql);
    $st->execute($args);
    return (int)$st->fetchColumn();
}

function eventPublicParticipants(int $eventId): array {
    $sql = "SELECT r.id, r.status, r.payment_status,
                   r.show_public,
                   c.name AS cat_name,
                   s.name AS school_name,
                   a.full_name, a.birthdate
            FROM event_registrations r
            LEFT JOIN event_categories c ON c.id = r.category_id
            LEFT JOIN schools s ON s.id = r.registering_school_id
            LEFT JOIN athletes a ON a.id = r.athlete_id
            WHERE r.event_id = ? AND r.status NOT IN ('rejected','withdrawn') AND r.show_public = 1
            ORDER BY c.display_order, c.id, s.name, a.full_name";
    $st = getDB()->prepare($sql);
    $st->execute([$eventId]);

    $rows = $st->fetchAll();
    // Privacy: minors → first name + initial
    foreach ($rows as &$r) {
        if (!empty($r['birthdate']) && $r['birthdate'] !== '0000-00-00') {
            try {
                $age = (new DateTime())->diff(new DateTime($r['birthdate']))->y;
                if ($age < 18 && $r['full_name']) {
                    $parts = preg_split('/\s+/', trim($r['full_name']));
                    if (count($parts) >= 2) {
                        $r['full_name'] = $parts[0] . ' ' . mb_substr($parts[count($parts)-1], 0, 1, 'UTF-8') . '.';
                    }
                }
            } catch (Exception $e) {}
        }
        unset($r['birthdate']);
    }
    return $rows;
}


// ══════════════════════════════════════════════════════════════
// 7. PARENT LINKAGE
// ══════════════════════════════════════════════════════════════

/** All events any of this parent's children are registered in. */
function eventsForParent(int $parentUserId, int $schoolId): array {
    $sql = "SELECT DISTINCT e.*, r.status AS reg_status, r.payment_status, r.id AS reg_id,
                   a.full_name AS athlete_name, a.id AS athlete_id,
                   c.name AS cat_name
            FROM parent_children pc
            JOIN athletes a ON a.id = pc.athlete_id
            JOIN event_registrations r ON r.athlete_id = a.id
            JOIN events e ON e.id = r.event_id
            LEFT JOIN event_categories c ON c.id = r.category_id
            WHERE pc.parent_id = ? AND a.school_id = ?
              AND r.status NOT IN ('rejected','withdrawn')
            ORDER BY e.starts_at ASC";
    $st = getDB()->prepare($sql);
    $st->execute([$parentUserId, $schoolId]);
    return $st->fetchAll();
}


// ══════════════════════════════════════════════════════════════
// 8. UPLOADS
// ══════════════════════════════════════════════════════════════

/** Move an uploaded file into events storage; returns relative path or ''. */
function eventUploadStore(array $upload, int $eventId, string $subdir = 'private', array $allowed = ['pdf','jpg','jpeg','png','webp'], int $maxKb = 5000): string {
    if (!is_array($upload) || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return '';
    if (($upload['size'] ?? 0) > $maxKb * 1024) throw new RuntimeException("Το αρχείο είναι μεγαλύτερο από {$maxKb} KB.");
    $ext = strtolower(pathinfo($upload['name'] ?? '', PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed, true)) throw new RuntimeException('Μη επιτρεπόμενος τύπος αρχείου: .' . $ext);

    $baseDir = __DIR__ . '/../uploads/events/' . $eventId . '/' . $subdir;
    if (!is_dir($baseDir)) {
        if (!@mkdir($baseDir, 0750, true) && !is_dir($baseDir)) throw new RuntimeException('Δεν ήταν δυνατή η δημιουργία φακέλου.');
    }
    $safeName = bin2hex(random_bytes(8)) . '.' . $ext;
    $dest = $baseDir . '/' . $safeName;
    if (!move_uploaded_file($upload['tmp_name'], $dest)) throw new RuntimeException('Αποτυχία αποθήκευσης αρχείου.');
    return 'events/' . $eventId . '/' . $subdir . '/' . $safeName;
}

/** Full absolute path from relative stored path (with path-traversal guard). */
function eventUploadAbsolute(string $relative): ?string {
    $base = realpath(__DIR__ . '/../uploads');
    if (!$base) return null;
    $target = realpath($base . '/' . ltrim($relative, '/'));
    if (!$target) return null;
    if (strpos($target, $base) !== 0) return null;   // escaped the base
    return $target;
}


// ══════════════════════════════════════════════════════════════
// 9. NOTIFICATIONS (thin wrapper — uses existing mailer)
// ══════════════════════════════════════════════════════════════

function eventNotify(string $trigger, array $event, array $ctx = []): void {
    if (!function_exists('sendEmail')) require_once __DIR__ . '/mailer.php';
    $to = $ctx['to_email'] ?? '';
    if (!$to || !filter_var($to, FILTER_VALIDATE_EMAIL)) return;

    $schoolName = $event['title'];
    $subject = match($trigger) {
        'registration_created'   => "Νέα εγγραφή — {$event['title']}",
        'registration_approved'  => "Εγγραφή εγκρίθηκε — {$event['title']}",
        'registration_rejected'  => "Εγγραφή απορρίφθηκε — {$event['title']}",
        'payment_verified'       => "Πληρωμή επιβεβαιώθηκε — {$event['title']}",
        'payment_rejected'       => "Πληρωμή απορρίφθηκε — {$event['title']}",
        'event_starts_soon'      => "Πλησιάζει η ημερομηνία — {$event['title']}",
        'event_cancelled'        => "Ματαίωση — {$event['title']}",
        default => "Ενημέρωση — {$event['title']}",
    };
    $body = ($ctx['body'] ?? '') . "\n\nΔείτε το event: " . eventPublicUrl($event);
    $html = function_exists('buildEmailHtml')
        ? buildEmailHtml($body, $schoolName, $subject)
        : nl2br(htmlspecialchars($body, ENT_QUOTES, 'UTF-8'));
    try { sendEmail($to, $subject, $html, $body, $ctx['to_name'] ?? ''); }
    catch (Throwable $e) { error_log('[events] notify failed: ' . $e->getMessage()); }
}


// ══════════════════════════════════════════════════════════════
// 11. CUSTOM FIELDS (Phase 4)
// ══════════════════════════════════════════════════════════════

function eventCustomFields(int $eventId): array {
    $st = getDB()->prepare("SELECT * FROM event_custom_fields WHERE event_id = ? ORDER BY display_order, id");
    $st->execute([$eventId]);
    return $st->fetchAll();
}

function eventCustomFieldSave(int $eventId, array $data, ?int $id = null): int {
    $db = getDB();
    $args = [
        $eventId,
        preg_replace('/[^a-z0-9_]/', '_', strtolower(trim($data['code'] ?? ''))) ?: 'f' . bin2hex(random_bytes(2)),
        mb_substr(trim($data['label'] ?? 'Πεδίο'), 0, 160),
        mb_substr(trim($data['help_text'] ?? ''), 0, 255) ?: null,
        in_array($data['field_type'] ?? '', ['text','textarea','select','number','date','checkbox'], true) ? $data['field_type'] : 'text',
        trim($data['options'] ?? '') ?: null,
        !empty($data['required']) ? 1 : 0,
        (int)($data['display_order'] ?? 0),
    ];
    if ($id) {
        $st = $db->prepare("UPDATE event_custom_fields
            SET event_id=?, code=?, label=?, help_text=?, field_type=?, options=?, required=?, display_order=?
            WHERE id = ?");
        $args[] = $id;
        $st->execute($args);
        return $id;
    }
    $st = $db->prepare("INSERT INTO event_custom_fields
        (event_id, code, label, help_text, field_type, options, required, display_order)
        VALUES (?,?,?,?,?,?,?,?)");
    $st->execute($args);
    return (int)$db->lastInsertId();
}

function eventCustomFieldDelete(int $fieldId, int $eventId): void {
    $st = getDB()->prepare("DELETE FROM event_custom_fields WHERE id = ? AND event_id = ?");
    $st->execute([$fieldId, $eventId]);
}

/** Save the answers submitted with a registration. */
function eventRegistrationSaveCustom(int $regId, int $eventId, array $values): void {
    $fields = eventCustomFields($eventId);
    if (!$fields) return;
    $db = getDB();
    $upd = $db->prepare("INSERT INTO event_registration_field_values (registration_id, field_id, value_text)
                         VALUES (?,?,?) ON DUPLICATE KEY UPDATE value_text = VALUES(value_text)");
    foreach ($fields as $f) {
        $v = $values[$f['code']] ?? '';
        if (is_array($v)) $v = implode(', ', $v);
        $v = mb_substr((string)$v, 0, 2000);
        if ($f['required'] && $v === '') throw new RuntimeException("Το πεδίο «{$f['label']}» είναι υποχρεωτικό.");
        $upd->execute([$regId, (int)$f['id'], $v]);
    }
}

function eventRegistrationCustomValues(int $regId): array {
    $st = getDB()->prepare("SELECT f.code, f.label, f.field_type, v.value_text
        FROM event_registration_field_values v
        JOIN event_custom_fields f ON f.id = v.field_id
        WHERE v.registration_id = ? ORDER BY f.display_order, f.id");
    $st->execute([$regId]);
    return $st->fetchAll();
}


// ══════════════════════════════════════════════════════════════
// 12. UPDATES & FOLLOWERS (Phase 4)
// ══════════════════════════════════════════════════════════════

function eventUpdatesForEvent(int $eventId, int $limit = 40): array {
    $st = getDB()->prepare("SELECT * FROM event_updates WHERE event_id = ? ORDER BY pinned DESC, published_at DESC LIMIT " . (int)$limit);
    $st->execute([$eventId]);
    return $st->fetchAll();
}

function eventUpdateSave(int $eventId, array $data, ?int $id = null): int {
    $db = getDB();
    $title  = mb_substr(trim($data['title'] ?? 'Ενημέρωση'), 0, 160);
    $body   = trim($data['body_md'] ?? '');
    $pinned = !empty($data['pinned']) ? 1 : 0;
    if ($id) {
        $db->prepare("UPDATE event_updates SET title=?, body_md=?, pinned=? WHERE id=? AND event_id=?")
           ->execute([$title, $body, $pinned, $id, $eventId]);
        return $id;
    }
    $db->prepare("INSERT INTO event_updates (event_id, title, body_md, pinned) VALUES (?,?,?,?)")
       ->execute([$eventId, $title, $body, $pinned]);
    return (int)$db->lastInsertId();
}

function eventUpdateDelete(int $updateId, int $eventId): void {
    getDB()->prepare("DELETE FROM event_updates WHERE id = ? AND event_id = ?")->execute([$updateId, $eventId]);
}

function eventFollowers(int $eventId): array {
    $st = getDB()->prepare("
        SELECT f.*,
               COALESCE(f.email, u.email, pu.parent_email) AS email_effective,
               COALESCE(u.name, pu.parent_name) AS name_effective
        FROM event_followers f
        LEFT JOIN users u ON u.id = f.user_id
        LEFT JOIN parent_users pu ON pu.id = f.parent_user_id
        WHERE f.event_id = ?");
    $st->execute([$eventId]);
    return $st->fetchAll();
}


// ══════════════════════════════════════════════════════════════
// 13. LABELS (Greek UI)
// ══════════════════════════════════════════════════════════════

function eventTypeLabel(string $type): string {
    return [
        'championship' => 'Πρωτάθλημα',
        'friendly'     => 'Φιλικό / Διασυλλογικό',
        'camp'         => 'Camp',
        'seminar'      => 'Σεμινάριο',
        'meeting'      => 'Συνάντηση',
        'exam'         => 'Εξετάσεις Ζωνών',
    ][$type] ?? $type;
}

function eventVisibilityLabel(string $v): string {
    return ['public' => 'Δημόσιο', 'unlisted' => 'Μη καταχωρημένο', 'invite_only' => 'Με πρόσκληση'][$v] ?? $v;
}

function eventStatusLabel(string $s): string {
    return [
        'draft'       => 'Πρόχειρο',
        'open'        => 'Ανοιχτές εγγραφές',
        'closed'      => 'Κλειστές εγγραφές',
        'in_progress' => 'Σε εξέλιξη',
        'completed'   => 'Ολοκληρώθηκε',
        'cancelled'   => 'Ματαιώθηκε',
    ][$s] ?? $s;
}

function eventStatusBadge(string $s): string {
    $cls = match($s) {
        'open','in_progress' => 'badge-paid',
        'draft','closed'     => 'badge-pending',
        'cancelled','completed' => 'badge-overdue',
        default => 'badge-pending',
    };
    return '<span class="badge ' . $cls . '">' . h(eventStatusLabel($s)) . '</span>';
}

function eventRegStatusBadge(string $s): string {
    $cls = match($s) {
        'approved','checked_in' => 'badge-paid',
        'pending'               => 'badge-pending',
        default                 => 'badge-overdue',
    };
    return '<span class="badge ' . $cls . '">' . h([
        'pending' => 'Εκκρεμεί', 'approved' => 'Εγκρίθηκε', 'rejected' => 'Απορρίφθηκε',
        'withdrawn' => 'Ακυρώθηκε', 'checked_in' => 'Παρών', 'no_show' => 'Απών', 'disqualified' => 'DQ',
    ][$s] ?? $s) . '</span>';
}

function eventPaymentStatusBadge(string $s): string {
    $cls = match($s) {
        'verified' => 'badge-paid',
        'proof_uploaded','unpaid' => 'badge-pending',
        default    => 'badge-overdue',
    };
    return '<span class="badge ' . $cls . '">' . h([
        'unpaid' => 'Ανεξόφλητο', 'proof_uploaded' => 'Απόδειξη ανεβασμένη',
        'verified' => 'Επιβεβαιωμένο', 'refunded' => 'Επιστροφή', 'waived' => 'Χωρίς χρέωση',
    ][$s] ?? $s) . '</span>';
}
