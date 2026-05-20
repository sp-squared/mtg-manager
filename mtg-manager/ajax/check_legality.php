<?php
ini_set('display_errors', 0);
ob_start();
session_start();
include __DIR__ . '/../includes/connect.php';
include __DIR__ . '/../includes/functions.php';
header('Content-Type: application/json');

if (!isLoggedIn()) {
    ob_end_clean(); echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit();
}

$user_id = getUserId();
$deck_id = (int)($_POST['deck_id'] ?? 0);
$format  = strtolower(trim($_POST['format'] ?? ''));

if (!$deck_id || !$format) {
    ob_end_clean(); echo json_encode(['success' => false, 'error' => 'Missing parameters']);
    exit();
}

// Verify deck ownership
$own = $dbc->prepare("SELECT id FROM decks WHERE id = ? AND user_id = ?");
$own->bind_param("ii", $deck_id, $user_id);
$own->execute();
$own->store_result();
if ($own->num_rows === 0) {
    ob_end_clean(); echo json_encode(['success' => false, 'error' => 'Deck not found']);
    exit();
}
$own->close();

// Construction rules per format
$rules = [
    'standard'        => ['min_main' => 60, 'max_side' => 15, 'max_copies' => 4],
    'pioneer'         => ['min_main' => 60, 'max_side' => 15, 'max_copies' => 4],
    'modern'          => ['min_main' => 60, 'max_side' => 15, 'max_copies' => 4],
    'legacy'          => ['min_main' => 60, 'max_side' => 15, 'max_copies' => 4],
    'vintage'         => ['min_main' => 60, 'max_side' => 15, 'max_copies' => 4],
    'pauper'          => ['min_main' => 60, 'max_side' => 15, 'max_copies' => 4],
    'historic'        => ['min_main' => 60, 'max_side' => 15, 'max_copies' => 4],
    'explorer'        => ['min_main' => 60, 'max_side' => 15, 'max_copies' => 4],
    'alchemy'         => ['min_main' => 60, 'max_side' => 15, 'max_copies' => 4],
    'premodern'       => ['min_main' => 60, 'max_side' => 15, 'max_copies' => 4],
    'oldschool'       => ['min_main' => 60, 'max_side' => 15, 'max_copies' => 4],
    'commander'       => ['min_main' => 99, 'max_side' => 0,  'max_copies' => 1, 'commander_zone' => 1],
    'brawl'           => ['min_main' => 59, 'max_side' => 0,  'max_copies' => 1, 'commander_zone' => 1],
    'paupercommander' => ['min_main' => 99, 'max_side' => 0,  'max_copies' => 1, 'commander_zone' => 1],
    'gladiator'       => ['min_main' => 100,'max_side' => 0,  'max_copies' => 1],
];

if (!isset($rules[$format])) {
    ob_end_clean(); echo json_encode(['success' => false, 'error' => "Unknown format: $format"]);
    exit();
}
$rule = $rules[$format];

// Resolve format_id (Scryfall stores names lowercase)
$fmt_s = $dbc->prepare("SELECT id FROM formats WHERE LOWER(name) = ? LIMIT 1");
$fmt_s->bind_param("s", $format);
$fmt_s->execute();
$fmt_row   = $fmt_s->get_result()->fetch_assoc();
$fmt_s->close();
$format_id       = $fmt_row['id'] ?? null;
$has_legality_db = $format_id !== null;

// Fetch deck cards with legality
$q = $dbc->prepare(
    "SELECT c.id, c.name, c.oracle_id, c.type_line,
            dc.quantity, dc.zone,
            fl.legality
     FROM deck_cards dc
     JOIN cards c ON c.id = dc.card_id
     LEFT JOIN format_legalities fl
            ON fl.card_id = dc.card_id AND fl.format_id = ?
     WHERE dc.deck_id = ?
     ORDER BY c.name"
);
$fid = $format_id ?? 0;
$q->bind_param("ii", $fid, $deck_id);
$q->execute();
$cards = $q->get_result()->fetch_all(MYSQLI_ASSOC);
$q->close();
$dbc->close();

// Zone counts — zone is the source of truth; skip only explicit token/maybeboard zones
$main_count      = 0;
$side_count      = 0;
$commander_count = 0;
foreach ($cards as $c) {
    if ($c['zone'] === 'tokens' || $c['zone'] === 'maybeboard') continue;
    if (str_contains($c['type_line'] ?? '', 'Token')) continue;
    $qty = (int)$c['quantity'];
    if ($c['zone'] === 'mainboard')  $main_count      += $qty;
    if ($c['zone'] === 'sideboard')  $side_count      += $qty;
    if ($c['zone'] === 'commander')  $commander_count += $qty;
}

$violations = [];

// ── Construction rules ────────────────────────────────────────────────────────
$commander_mode = !empty($rule['commander_zone']);

if ($commander_mode) {
    $expected = $rule['min_main'] + $rule['commander_zone']; // e.g. 100
    $actual   = $main_count + $commander_count;
    if ($actual !== $expected) {
        $violations[] = [
            'type'    => 'deck_size',
            'message' => "Deck must have exactly {$expected} cards (currently {$actual})",
        ];
    }
    // Allow commander in mainboard (0 in zone) OR in commander zone (exactly 1).
    // Only flag if more than 1 card sits in the commander zone.
    if ($commander_count > $rule['commander_zone']) {
        $violations[] = [
            'type'    => 'commander_zone',
            'message' => "Commander zone can have at most {$rule['commander_zone']} card (currently {$commander_count})",
        ];
    }
} else {
    if ($main_count < $rule['min_main']) {
        $violations[] = [
            'type'    => 'deck_size',
            'message' => "Main deck needs at least {$rule['min_main']} cards (currently {$main_count})",
        ];
    }
    if ($side_count > $rule['max_side']) {
        $violations[] = [
            'type'    => 'sideboard_size',
            'message' => "Sideboard cannot exceed {$rule['max_side']} cards (currently {$side_count})",
        ];
    }
}

// ── Copy-limit check (grouped by oracle_id) ───────────────────────────────────
$oracle_groups = [];
foreach ($cards as $c) {
    if ($c['zone'] === 'tokens' || $c['zone'] === 'maybeboard') continue;
    if (str_contains($c['type_line'] ?? '', 'Token')) continue;
    if (str_contains($c['type_line'] ?? '', 'Basic Land')) continue;
    $key = $c['oracle_id'] ?? $c['name'];
    if (!isset($oracle_groups[$key])) {
        $oracle_groups[$key] = ['name' => $c['name'], 'count' => 0];
    }
    $oracle_groups[$key]['count'] += (int)$c['quantity'];
}
foreach ($oracle_groups as $data) {
    if ($data['count'] > $rule['max_copies']) {
        $violations[] = [
            'type'  => 'too_many_copies',
            'card'  => $data['name'],
            'count' => $data['count'],
            'max'   => $rule['max_copies'],
        ];
    }
}

// ── Card legality (only when we have DB data for this format) ─────────────────
if ($has_legality_db) {
    $seen = [];
    foreach ($cards as $c) {
        if ($c['zone'] === 'tokens' || $c['zone'] === 'maybeboard') continue;
        if (str_contains($c['type_line'] ?? '', 'Token')) continue;
        if (!preg_match('/Creature|Artifact|Enchantment|Instant|Sorcery|Planeswalker|Land|Battle|Dungeon/i', $c['type_line'] ?? '')) continue;
        if (isset($seen[$c['id']])) continue;
        $seen[$c['id']] = true;

        $leg = $c['legality'];
        if ($leg === 'banned') {
            $violations[] = ['type' => 'banned',     'card' => $c['name']];
        } elseif ($leg === 'restricted' && (int)$c['quantity'] > 1) {
            $violations[] = ['type' => 'restricted', 'card' => $c['name'], 'count' => (int)$c['quantity']];
        } elseif ($leg === 'not_legal') {
            // NULL means legality data is missing (card predates last import) — not a violation
            $violations[] = ['type' => 'not_legal',  'card' => $c['name']];
        }
    }
} else {
    $violations[] = [
        'type'    => 'no_legality_data',
        'message' => "No card-pool data for $format in database — run Scryfall import to populate.",
    ];
}

ob_end_clean();
echo json_encode([
    'success'    => true,
    'legal'      => empty($violations),
    'format'     => $format,
    'counts'     => ['main' => $main_count, 'side' => $side_count, 'commander' => $commander_count],
    'violations' => $violations,
]);
