<?php
include __DIR__ . '/includes/header.php';
if (!isLoggedIn()) {
    global $_session_kicked;
    $msg = $_session_kicked
        ? "You+were+signed+in+elsewhere.+This+session+has+ended."
        : "Please+log+in+to+continue.";
    header("Location: index.php?error=" . $msg);
    exit();
}
include __DIR__ . '/includes/connect.php';
$user_id = getUserId();
$deck_id = (int)($_GET['deck_id'] ?? 0);

// Verify deck ownership
$stmt = $dbc->prepare("SELECT id, name, description FROM decks WHERE id = ? AND user_id = ?");
$stmt->bind_param("ii", $deck_id, $user_id);
$stmt->execute();
$deck = $stmt->get_result()->fetch_assoc();
if (!$deck) {
    header("Location: decks.php?error=Deck not found");
    exit();
}
$stmt->close();

// Handle messages
$message = '';
$msg_type = 'success';
if (isset($_GET['msg'])) {
    switch ($_GET['msg']) {
        case 'added':   $message = 'Card added to deck.'; break;
        case 'removed': $message = 'Card removed from deck.'; break;
        case 'updated': $message = 'Quantity updated.'; break;
        case 'cleared': $message = 'All cards removed. Deck is ready to rebuild.'; $msg_type = 'warning'; break;
    }
}

function truncate($string, $length = 16, $append = '…') {
    return strlen($string) > $length ? substr($string, 0, $length) . $append : $string;
}

// ── Deck Summary Data ─────────────────────────────────────────────────────
// Total card count
$total_stmt = $dbc->prepare(
    "SELECT SUM(dc.quantity) as total,
            SUM(IF(dc.is_sideboard=0 AND c.type_line NOT LIKE '%Token%', dc.quantity, 0)) as main_count,
            SUM(IF(dc.is_sideboard=1 AND c.type_line NOT LIKE '%Token%', dc.quantity, 0)) as side_count,
            SUM(IF(c.type_line LIKE '%Token%', dc.quantity, 0)) as token_qty,
            SUM(IF(dc.is_sideboard=0 AND c.type_line NOT LIKE '%Token%', 1, 0)) as unique_main,
            SUM(IF(dc.is_sideboard=1 AND c.type_line NOT LIKE '%Token%', 1, 0)) as unique_side,
            SUM(IF(c.type_line LIKE '%Token%', 1, 0)) as unique_tokens
     FROM deck_cards dc
     JOIN cards c ON c.id = dc.card_id
     WHERE dc.deck_id = ?");
$total_stmt->bind_param("i", $deck_id);
$total_stmt->execute();
$totals = $total_stmt->get_result()->fetch_assoc();
$total_stmt->close();
$total_cards   = (int)($totals['total']        ?? 0);
$main_count    = (int)($totals['main_count']   ?? 0);
$side_count    = (int)($totals['side_count']   ?? 0);
$token_qty     = (int)($totals['token_qty']    ?? 0);
$unique_main   = (int)($totals['unique_main']  ?? 0);
$unique_side   = (int)($totals['unique_side']  ?? 0);
$unique_tokens = (int)($totals['unique_tokens'] ?? 0);
$unique_total  = $unique_main + $unique_side;

// Color distribution — join through card_colors
$color_stmt = $dbc->prepare(
    "SELECT cc.color_id, SUM(dc.quantity) as cnt
     FROM deck_cards dc
     JOIN card_colors cc ON cc.card_id = dc.card_id
     WHERE dc.deck_id = ?
     GROUP BY cc.color_id
     ORDER BY cnt DESC");
$color_stmt->bind_param("i", $deck_id);
$color_stmt->execute();
$color_rows = $color_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$color_stmt->close();

$color_counts = [];
foreach ($color_rows as $row) {
    $color_counts[$row['color_id']] = (int)$row['cnt'];
}

// Color meta
$color_meta = [
    'W' => ['name'=>'White',   'hex'=>'#f9f1d8', 'text'=>'#1a1a1a'],
    'U' => ['name'=>'Blue',    'hex'=>'#1a6bbd', 'text'=>'#fff'],
    'B' => ['name'=>'Black',   'hex'=>'#2a2a2a', 'text'=>'#e8e8e8'],
    'R' => ['name'=>'Red',     'hex'=>'#c0392b', 'text'=>'#fff'],
    'G' => ['name'=>'Green',   'hex'=>'#1a7a3c', 'text'=>'#fff'],
];

// Dual-color guild/archetype names
$guild_names = [
    'WU'=>'Azorius','WB'=>'Orzhov','WR'=>'Boros','WG'=>'Selesnya',
    'UB'=>'Dimir',  'UR'=>'Izzet', 'UG'=>'Simic',
    'BR'=>'Rakdos', 'BG'=>'Golgari',
    'RG'=>'Gruul',
];
$trio_names = [
    'WUB'=>'Esper',  'WUR'=>'Jeskai', 'WUG'=>'Bant',   'WBR'=>'Mardu',
    'WBG'=>'Abzan',  'WRG'=>'Naya',   'UBR'=>'Grixis', 'UBG'=>'Sultai',
    'URG'=>'Temur',  'BRG'=>'Jund',
];

$active_colors = array_keys($color_counts);
sort($active_colors);
$color_key = implode('', $active_colors);
$archetype = '';
if (count($active_colors) === 1) {
    $archetype = 'Mono-' . $color_meta[$active_colors[0]]['name'];
} elseif (count($active_colors) === 2) {
    $archetype = ($guild_names[$color_key] ?? $color_key) . ' (Dual Color)';
} elseif (count($active_colors) === 3) {
    $archetype = ($trio_names[$color_key] ?? $color_key) . ' (Tri Color)';
} elseif (count($active_colors) === 4) {
    $archetype = '4-Color (Artifice)';
} elseif (count($active_colors) === 5) {
    $archetype = '5-Color (Rainbow / Domain)';
}

// Type breakdown for summary
$type_stmt = $dbc->prepare(
    "SELECT
        SUM(IF(c.type_line LIKE '%Token%',       dc.quantity, 0)) as tokens,
        SUM(IF(c.type_line LIKE '%Creature%' AND c.type_line NOT LIKE '%Token%', dc.quantity, 0)) as creatures,
        SUM(IF(c.type_line LIKE '%Instant%',     dc.quantity, 0)) as instants,
        SUM(IF(c.type_line LIKE '%Sorcery%',     dc.quantity, 0)) as sorceries,
        SUM(IF(c.type_line LIKE '%Enchantment%', dc.quantity, 0)) as enchantments,
        SUM(IF(c.type_line LIKE '%Artifact%',    dc.quantity, 0)) as artifacts,
        SUM(IF(c.type_line LIKE '%Planeswalker%',dc.quantity, 0)) as planeswalkers,
        SUM(IF(c.type_line LIKE '%Land%',        dc.quantity, 0)) as lands,
        SUM(IF(c.type_line LIKE '%Token%',       1, 0)) as u_tokens,
        SUM(IF(c.type_line LIKE '%Creature%' AND c.type_line NOT LIKE '%Token%', 1, 0)) as u_creatures,
        SUM(IF(c.type_line LIKE '%Instant%',     1, 0)) as u_instants,
        SUM(IF(c.type_line LIKE '%Sorcery%',     1, 0)) as u_sorceries,
        SUM(IF(c.type_line LIKE '%Enchantment%', 1, 0)) as u_enchantments,
        SUM(IF(c.type_line LIKE '%Artifact%',    1, 0)) as u_artifacts,
        SUM(IF(c.type_line LIKE '%Planeswalker%',1, 0)) as u_planeswalkers,
        SUM(IF(c.type_line LIKE '%Land%',        1, 0)) as u_lands
     FROM deck_cards dc
     JOIN cards c ON dc.card_id = c.id
     WHERE dc.deck_id = ? AND dc.is_sideboard = 0");
$type_stmt->bind_param("i", $deck_id);
$type_stmt->execute();
$types = $type_stmt->get_result()->fetch_assoc();
$type_stmt->close();

// Commander singleton check — non-basic cards with quantity > 1
$singleton_stmt = $dbc->prepare(
    "SELECT COUNT(*) as violations
     FROM deck_cards dc
     JOIN cards c ON dc.card_id = c.id
     WHERE dc.deck_id = ?
       AND dc.is_sideboard = 0
       AND dc.quantity > 1
       AND c.type_line NOT LIKE '%Basic Land%'
       AND c.type_line NOT LIKE '%Token%'"
);
$singleton_stmt->bind_param("i", $deck_id);
$singleton_stmt->execute();
$singleton_violations = (int)$singleton_stmt->get_result()->fetch_assoc()['violations'];
$singleton_stmt->close();

$is_commander_count     = ($main_count === 100);
$is_commander_singleton = ($singleton_violations === 0);
$is_commander_legal     = $is_commander_count && $is_commander_singleton;

// Mana curve — CMC distribution for non-land main deck cards
$curve_stmt = $dbc->prepare(
    "SELECT LEAST(FLOOR(c.cmc), 7) as cmc_bucket, SUM(dc.quantity) as cnt
     FROM deck_cards dc
     JOIN cards c ON dc.card_id = c.id
     WHERE dc.deck_id = ? AND dc.is_sideboard = 0
       AND c.type_line NOT LIKE '%Land%'
       AND c.type_line NOT LIKE '%Token%'
     GROUP BY cmc_bucket
     ORDER BY cmc_bucket"
);
$curve_stmt->bind_param("i", $deck_id);
$curve_stmt->execute();
$curve_rows = $curve_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$curve_stmt->close();

$curve_data = array_fill(0, 8, 0); // 0-6, then 7+
foreach ($curve_rows as $row) {
    $bucket = (int)$row['cmc_bucket'];
    $curve_data[$bucket] = (int)$row['cnt'];
}
$curve_max = max($curve_data) ?: 1;
$curve_labels = ['0','1','2','3','4','5','6','7+'];

// Token cards in deck
$token_stmt = $dbc->prepare(
    "SELECT c.id, c.name, c.type_line, c.power, c.toughness, c.image_uri, dc.quantity
     FROM deck_cards dc
     JOIN cards c ON dc.card_id = c.id
     WHERE dc.deck_id = ? AND c.type_line LIKE '%Token%'
     ORDER BY c.name");
$token_stmt->bind_param("i", $deck_id);
$token_stmt->execute();
$token_cards = $token_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$token_stmt->close();
$token_count = count($token_cards);

// Missing cards — cards in deck where user owns fewer than needed (tokens excluded)
$missing_stmt = $dbc->prepare(
    "SELECT c.id, c.name, c.type_line, c.image_uri, c.mana_cost, c.rarity,
            dc.quantity as needed,
            COALESCE(uc.quantity, 0) as owned,
            (dc.quantity - COALESCE(uc.quantity, 0)) as missing_qty,
            cp.price_usd
     FROM deck_cards dc
     JOIN cards c ON c.id = dc.card_id
     LEFT JOIN user_collection uc ON uc.card_id = dc.card_id AND uc.user_id = ?
     LEFT JOIN card_prices cp ON cp.card_id = dc.card_id
     WHERE dc.deck_id = ?
       AND c.type_line NOT LIKE '%Token%'
       AND (uc.quantity IS NULL OR uc.quantity < dc.quantity)
     ORDER BY c.name"
);
$missing_stmt->bind_param("ii", $user_id, $deck_id);
$missing_stmt->execute();
$missing_cards = $missing_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$missing_stmt->close();
$missing_count = count($missing_cards);
$missing_value = 0;
foreach ($missing_cards as $mc) {
    if ($mc['price_usd'] !== null) {
        $missing_value += (float)$mc['price_usd'] * (int)$mc['missing_qty'];
    }
}
?>

<div class="container-fluid my-4">
    <div class="row">
        <div class="col-12">
            <div class="text-center mb-1">
                <h1 class="mb-0" id="deck-name-display"><?= htmlspecialchars($deck['name']) ?></h1>
            </div>
            <div class="d-flex justify-content-center gap-2 mb-2" style="padding-top:0.5rem;">
                <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editDeckModal">
                    <i class="bi bi-pencil me-1"></i>Edit
                </button>
                <button class="btn btn-sm btn-outline-warning" id="editor-export-btn"
                        data-deck-id="<?= $deck_id ?>"
                        data-deck-name="<?= htmlspecialchars($deck['name']) ?>">
                    <i class="bi bi-box-arrow-up me-1"></i>Export
                </button>
            </div>
            <p class="text-center" id="deck-desc-display" style="color:#8899aa;">
                <?= htmlspecialchars($deck['description'] ?: 'No description') ?>
            </p>

            <?php if ($message): ?>
                <div class="alert alert-<?= $msg_type ?> alert-dismissible fade show" role="alert">
                    <?= htmlspecialchars($message) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Edit Deck Modal -->
    <div class="modal fade" id="editDeckModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" style="color:#c9a227;">Edit Deck Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="edit-deck-form">
                        <input type="hidden" name="deck_id" value="<?= $deck_id ?>">
                        <div class="mb-3">
                            <label class="form-label">Deck Name</label>
                            <input type="text" class="form-control" name="name" value="<?= htmlspecialchars($deck['name']) ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea class="form-control" name="description" rows="3"><?= htmlspecialchars($deck['description'] ?? '') ?></textarea>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" id="save-deck-btn">Save Changes</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Clear Deck Confirmation Modal -->
    <div class="modal fade" id="clearDeckModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" style="color:#c0392b;"><i class="bi bi-exclamation-triangle-fill me-2"></i>Clear Entire Deck?</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>This will remove <strong>every card</strong> from <strong><?= htmlspecialchars($deck['name']) ?></strong>. The deck itself won't be deleted — you can rebuild it from scratch.</p>
                    <p class="mb-0" style="color:#8899aa;">This action cannot be undone.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <form action="actions/clear_deck.php" method="post">
                    <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                        <input type="hidden" name="deck_id" value="<?= $deck_id ?>">
                        <button type="submit" class="btn btn-danger">
                            <i class="bi bi-trash3 me-1"></i>Yes, Clear All Cards
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <!-- LEFT: Collection -->
        <div class="col-md-6">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0">Your Collection</h5>
                </div>
                <div class="card-body deck-panel-scroll" id="collection-panel-body" style="max-height: 70vh; overflow-y: auto;">
                    <?php
                    $col_query = "SELECT c.id, c.name, c.mana_cost, c.type_line, uc.quantity,
                                         COALESCE(dc.quantity, 0) as in_deck
                                  FROM user_collection uc
                                  JOIN cards c ON uc.card_id = c.id
                                  LEFT JOIN deck_cards dc ON dc.card_id = c.id AND dc.deck_id = ?
                                  WHERE uc.user_id = ?
                                  ORDER BY c.name";
                    $col_stmt = $dbc->prepare($col_query);
                    $col_stmt->bind_param("ii", $deck_id, $user_id);
                    $col_stmt->execute();
                    $collection = $col_stmt->get_result();
                    ?>
                    <?php if ($collection->num_rows == 0): ?>
                        <p style="color:#8899aa;">Your collection is empty. <a href="search.php">Add cards first.</a></p>
                    <?php else: ?>
                        <table class="table table-sm table-hover" style="color:#e8e8e8;">
                            <thead>
                                <tr style="color:#c9a227;">
                                    <th>Card</th><th>Mana</th><th>Type</th><th>Owned</th><th>Add</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php while ($card = $collection->fetch_assoc()):
                                $owned   = (int)$card['quantity'];
                                $in_deck = (int)$card['in_deck'];
                                $remaining = $owned - $in_deck;
                                $at_limit  = $remaining <= 0;
                            ?>
                                <tr>
                                    <td title="<?= htmlspecialchars($card['name']) ?>"><?= htmlspecialchars(truncate($card['name'])) ?></td>
                                    <td><?= htmlspecialchars($card['mana_cost'] ?? '—') ?></td>
                                    <td title="<?= htmlspecialchars($card['type_line']) ?>"><?= htmlspecialchars(truncate($card['type_line'], 12)) ?></td>
                                    <td>
                                        <?= $owned ?>
                                        <?php if ($in_deck > 0): ?>
                                            <span style="color:#8899aa;font-size:0.8rem;" title="<?= $in_deck ?> already in deck">(<?= $in_deck ?> in deck)</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($at_limit): ?>
                                            <div class="d-flex gap-1 align-items-center">
                                                <input type="number" class="form-control form-control-sm" style="width:60px;" value="0" disabled>
                                                <button class="btn btn-sm btn-secondary" style="min-width:130px;opacity:0.45;" disabled title="All owned copies already in deck">Add</button>
                                                <span title="All copies are in the deck" style="font-size:1.3rem;line-height:1;">✅</span>
                                            </div>
                                        <?php else: ?>
                                            <div class="d-flex gap-1">
                                                <input type="number" class="deck-qty-input form-control form-control-sm"
                                                       value="1" min="1" max="<?= $remaining ?>" style="width:60px;">
                                                <button class="btn btn-sm btn-success add-to-deck-btn" style="min-width:130px;"
                                                        data-deck-id="<?= $deck_id ?>"
                                                        data-card-id="<?= htmlspecialchars($card['id']) ?>">Add</button>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                    <?php $col_stmt->close(); ?>
                </div>
            </div>
        </div>

        <!-- RIGHT: Deck Contents -->
        <div class="col-md-6">
            <div class="card shadow-sm h-100">
                <div id="deck-header" class="card-header bg-success text-white d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">Deck Contents</h5>
                    <?php if ($total_cards > 0): ?>
                    <button class="btn btn-sm btn-danger" data-bs-toggle="modal" data-bs-target="#clearDeckModal">
                        <i class="bi bi-trash3 me-1"></i>Clear All
                    </button>
                    <?php endif; ?>
                </div>
                <div class="card-body deck-panel-scroll" id="deck-panel-body" style="max-height: 70vh; overflow-y: auto;">
                    <?php
                    $deck_query = "SELECT c.id, c.name, c.mana_cost, c.type_line,
                                          dc.quantity, dc.is_sideboard,
                                          uc.quantity as owned
                                   FROM deck_cards dc
                                   JOIN cards c ON dc.card_id = c.id
                                   LEFT JOIN user_collection uc ON uc.card_id = c.id AND uc.user_id = ?
                                   WHERE dc.deck_id = ?
                                   ORDER BY dc.is_sideboard, c.name";
                    $deck_stmt = $dbc->prepare($deck_query);
                    $deck_stmt->bind_param("ii", $user_id, $deck_id);
                    $deck_stmt->execute();
                    $deck_cards = $deck_stmt->get_result();
                    ?>
                    <?php if ($deck_cards->num_rows == 0): ?>
                        <p style="color:#8899aa;">This deck is empty. Add cards from your collection on the left.</p>
                    <?php else: ?>
                        <table class="table table-sm table-hover" style="color:#e8e8e8;">
                            <thead>
                                <tr style="color:#c9a227;">
                                    <th>Card</th><th>Mana</th><th>Type</th><th>Qty</th><th>Side?</th><th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php while ($card = $deck_cards->fetch_assoc()):
                                $owned = (int)($card['owned'] ?? 0);
                            ?>
                                <tr>
                                    <td title="<?= htmlspecialchars($card['name']) ?>">
                                        <?= htmlspecialchars(truncate($card['name'])) ?>
                                        <?php if (strpos($card['type_line'], 'Token') !== false && (int)$card['quantity'] > 1): ?>
                                            <span style="color:#8899aa;font-size:0.78rem;"> ×<?= (int)$card['quantity'] ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= htmlspecialchars($card['mana_cost'] ?? '—') ?></td>
                                    <td title="<?= htmlspecialchars($card['type_line']) ?>"><?= htmlspecialchars(truncate($card['type_line'], 12)) ?></td>
                                    <td>
                                        <?= $card['quantity'] ?>
                                        <?php if ($owned > 0): ?>
                                            <span style="color:#8899aa;font-size:0.78rem;" title="You own <?= $owned ?>">/ <?= $owned ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= $card['is_sideboard'] ? 'Yes' : 'Main' ?></td>
                                    <td>
                                        <div class="d-flex flex-column gap-1 align-items-end">
                                            <button class="btn btn-sm btn-danger remove-from-deck-btn" style="min-width:130px;"
                                                    data-deck-id="<?= $deck_id ?>"
                                                    data-card-id="<?= htmlspecialchars($card['id']) ?>">Remove</button>
                                            <div class="d-flex gap-1">
                                                <input type="number" class="deck-update-qty form-control form-control-sm"
                                                       value="<?= $card['quantity'] ?>" min="0"
                                                       max="<?= $owned ?: '' ?>" style="width:60px;"
                                                       title="Max <?= $owned ?> owned">
                                                <button class="btn btn-sm btn-success update-deck-btn" style="min-width:130px;"
                                                        data-deck-id="<?= $deck_id ?>"
                                                        data-card-id="<?= htmlspecialchars($card['id']) ?>">Update</button>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                    <?php $deck_stmt->close(); ?>
                </div>
            </div>
        </div>
    </div>

    <?php if ($total_cards > 0): ?>
    <!-- ── DECK SUMMARY ──────────────────────────────────────────────── -->
    <div class="row mt-5">
        <div class="col-12">
            <h2 style="color:#c9a227;"><i class="bi bi-bar-chart-fill me-2"></i>Deck Summary</h2>
            <hr style="border-color:rgba(201,162,39,0.3);">
        </div>

        <!-- Card Counts -->
        <div class="col-md-4 mb-4">
            <div class="card shadow-sm h-100" style="border-top:4px solid #c9a227;">
                <div class="card-body">
                    <h5 style="color:#c9a227;"><i class="bi bi-stack me-2"></i>Card Count</h5>
                    <table class="table table-sm mb-0" style="color:#e8e8e8;">
                        <thead>
                            <tr style="color:#8899aa;font-size:0.8rem;">
                                <th></th>
                                <th class="text-end">Total</th>
                                <th class="text-end">Unique</th>
                            </tr>
                        </thead>
                        <tr>
                            <td>Main Deck</td>
                            <td class="text-end fw-bold"><?= $main_count ?></td>
                            <td class="text-end" style="color:#8899aa;"><?= $unique_main ?></td>
                        </tr>
                        <tr>
                            <td>Sideboard</td>
                            <td class="text-end fw-bold"><?= $side_count ?></td>
                            <td class="text-end" style="color:#8899aa;"><?= $unique_side ?></td>
                        </tr>
                        <?php if ($token_qty > 0): ?>
                        <tr>
                            <td style="color:#8899aa;"><i class="bi bi-stars me-1"></i>Tokens</td>
                            <td class="text-end fw-bold" style="color:#8899aa;"><?= $token_qty ?></td>
                            <td class="text-end" style="color:#8899aa;"><?= $unique_tokens ?></td>
                        </tr>
                        <?php endif; ?>
                        <tr style="border-top:1px solid rgba(201,162,39,0.3);">
                            <td><strong>Total</strong> <span class="small" style="color:#8899aa;">(ex. tokens)</span></td>
                            <td class="text-end fw-bold" style="color:#c9a227;"><?= $main_count + $side_count ?></td>
                            <td class="text-end" style="color:#8899aa;"><?= $unique_total ?></td>
                        </tr>
                    </table>
                    <?php if ($main_count < 60): ?>
                        <div class="mt-2 small" style="color:#fd7e14;">
                            <i class="bi bi-exclamation-circle me-1"></i>
                            <?= 60 - $main_count ?> more card<?= (60 - $main_count) !== 1 ? 's' : '' ?> needed for a standard 60-card deck
                        </div>
                    <?php elseif ($main_count === 60): ?>
                        <div class="mt-2 small" style="color:#75b798;">
                            <i class="bi bi-check-circle me-1"></i>Perfect 60-card main deck!
                        </div>
                    <?php else: ?>
                        <div class="mt-2 small" style="color:#8899aa;">
                            <i class="bi bi-info-circle me-1"></i><?= $main_count - 60 ?> cards over standard 60
                        </div>
                    <?php endif; ?>

                    <!-- Commander (100-card singleton) -->
                    <?php if ($is_commander_legal): ?>
                        <div class="mt-2 small" style="color:#a78bfa;">
                            <i class="bi bi-trophy-fill me-1"></i>Valid Commander deck — 100-card singleton!
                        </div>
                    <?php elseif ($main_count === 100 && !$is_commander_singleton): ?>
                        <div class="mt-2 small" style="color:#fd7e14;">
                            <i class="bi bi-exclamation-triangle me-1"></i>
                            100 cards but <strong><?= $singleton_violations ?></strong>
                            non-basic card<?= $singleton_violations !== 1 ? 's have' : ' has' ?> duplicate copies.
                            Commander requires singleton (1 of each non-basic).
                        </div>
                    <?php elseif ($main_count < 100 && $is_commander_singleton && $main_count > 60): ?>
                        <div class="mt-2 small" style="color:#8899aa;">
                            <i class="bi bi-info-circle me-1"></i>Singleton so far — <?= 100 - $main_count ?> more card<?= (100 - $main_count) !== 1 ? 's' : '' ?> needed for Commander.
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Color Identity -->
        <div class="col-md-4 mb-4">
            <div class="card shadow-sm h-100" style="border-top:4px solid #6ea8fe;">
                <div class="card-body">
                    <h5 style="color:#6ea8fe;"><i class="bi bi-palette me-2"></i>Color Identity</h5>
                    <?php if (empty($color_counts)): ?>
                        <p style="color:#8899aa;" class="small">No colored cards detected.</p>
                    <?php else: ?>
                        <?php if ($archetype): ?>
                            <p class="mb-3">
                                <span class="badge" style="background:rgba(201,162,39,0.2);color:#c9a227;font-size:0.9rem;border:1px solid rgba(201,162,39,0.4);">
                                    <?= htmlspecialchars($archetype) ?>
                                </span>
                            </p>
                        <?php endif; ?>
                        <!-- Color pips + bars -->
                        <?php
                        $max_color = max($color_counts);
                        foreach ($color_meta as $code => $meta):
                            if (!isset($color_counts[$code])) continue;
                            $cnt = $color_counts[$code];
                            $pct = $max_color > 0 ? round($cnt / $max_color * 100) : 0;
                        ?>
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <span class="d-inline-flex align-items-center justify-content-center rounded-circle fw-bold"
                                  style="width:28px;height:28px;background:<?= $meta['hex'] ?>;color:<?= $meta['text'] ?>;font-size:0.75rem;flex-shrink:0;border:1px solid rgba(255,255,255,0.15);">
                                <?= $code ?>
                            </span>
                            <div class="flex-grow-1">
                                <div style="background:rgba(255,255,255,0.08);border-radius:4px;height:10px;overflow:hidden;">
                                    <div style="width:<?= $pct ?>%;height:100%;background:<?= $meta['hex'] ?>;border-radius:4px;"></div>
                                </div>
                            </div>
                            <span style="color:#e8e8e8;font-size:0.85rem;min-width:28px;text-align:right;"><?= $cnt ?></span>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Type Breakdown -->
        <div class="col-md-4 mb-4">
            <div class="card shadow-sm h-100" style="border-top:4px solid #75b798;">
                <div class="card-body">
                    <h5 style="color:#75b798;"><i class="bi bi-list-ul me-2"></i>Type Breakdown</h5>
                    <table class="table table-sm mb-0" style="color:#e8e8e8;">
                        <thead>
                            <tr style="color:#8899aa;font-size:0.8rem;">
                                <th>Type</th>
                                <th class="text-end">Total</th>
                                <th class="text-end">Unique</th>
                            </tr>
                        </thead>
                        <?php
                        $type_labels = [
                            'tokens'        => ['Tokens',        'bi-stars'],
                            'creatures'     => ['Creatures',     'bi-shield-fill'],
                            'instants'      => ['Instants',      'bi-lightning-fill'],
                            'sorceries'     => ['Sorceries',     'bi-magic'],
                            'enchantments'  => ['Enchantments',  'bi-flower1'],
                            'artifacts'     => ['Artifacts',     'bi-gear-fill'],
                            'planeswalkers' => ['Planeswalkers', 'bi-person-badge-fill'],
                            'lands'         => ['Lands',         'bi-tree-fill'],
                        ];
                        $has_overlap = false;
                        foreach ($type_labels as $key => [$label, $icon]):
                            $val     = (int)($types[$key]       ?? 0);
                            $uniq    = (int)($types['u_' . $key] ?? 0);
                            if ($val === 0) continue;
                            $repeats = $val - $uniq;
                            if ($uniq !== $val) $has_overlap = true;
                        ?>
                        <tr>
                            <td><i class="bi <?= $icon ?> me-1" style="color:#8899aa;"></i><?= $label ?></td>
                            <td class="text-end fw-bold"><?= $val ?></td>
                            <td class="text-end" style="color:#8899aa;"><?= $uniq ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </table>
                    <?php if ($has_overlap): ?>
                        <p class="mt-2 mb-0 small" style="color:#8899aa;">
                            <i class="bi bi-info-circle me-1"></i>
                            Multi-type cards (e.g. Artifact Creature) are counted in each matching row.
                            <em>Total</em> = copies in deck &nbsp;·&nbsp; <em>Unique</em> = distinct card titles.
                        </p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <!-- Mana Curve -->
        <div class="col-md-6 mb-4">
            <div class="card shadow-sm h-100" style="border-top:4px solid #f97316;">
                <div class="card-body">
                    <h5 style="color:#f97316;"><i class="bi bi-bar-chart-fill me-2"></i>Mana Curve</h5>
                    <p class="small mb-3" style="color:#8899aa;">Non-land main deck cards by converted mana cost</p>
                    <div class="d-flex align-items-end gap-1" style="height:100px;">
                        <?php foreach ($curve_data as $i => $cnt): ?>
                            <?php $pct = $curve_max > 0 ? round($cnt / $curve_max * 100) : 0; ?>
                            <div class="d-flex flex-column align-items-center flex-grow-1" style="min-width:0;">
                                <?php if ($cnt > 0): ?>
                                    <span style="color:#f97316;font-size:0.65rem;margin-bottom:2px;font-weight:700;"><?= $cnt ?></span>
                                <?php else: ?>
                                    <span style="font-size:0.65rem;margin-bottom:2px;">&nbsp;</span>
                                <?php endif; ?>
                                <div style="width:100%;background:rgba(255,255,255,0.06);border-radius:3px 3px 0 0;position:relative;flex:1;">
                                    <div style="position:absolute;bottom:0;left:0;right:0;
                                                height:<?= $pct ?>%;
                                                background:<?= $cnt > 0 ? 'linear-gradient(to top,#f97316,#fbbf24)' : 'transparent' ?>;
                                                border-radius:3px 3px 0 0;
                                                transition:height 0.3s ease;"></div>
                                </div>
                                <span style="color:#8899aa;font-size:0.65rem;margin-top:3px;"><?= $curve_labels[$i] ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <?php
                    $avg_cmc = 0;
                    $total_spell_count = array_sum($curve_data);
                    if ($total_spell_count > 0) {
                        $weighted = 0;
                        foreach ($curve_data as $i => $cnt) $weighted += $i * $cnt;
                        $avg_cmc = round($weighted / $total_spell_count, 2);
                    }
                    ?>
                    <?php if ($total_spell_count > 0): ?>
                        <div class="mt-3 d-flex gap-3" style="font-size:0.8rem;color:#8899aa;">
                            <span><strong style="color:#f97316;"><?= $avg_cmc ?></strong> avg CMC</span>
                            <span><strong style="color:#f97316;"><?= $total_spell_count ?></strong> spells</span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Average CMC & Spell Efficiency -->
        <div class="col-md-6 mb-4">
            <div class="card shadow-sm h-100" style="border-top:4px solid #a78bfa;">
                <div class="card-body">
                    <h5 style="color:#a78bfa;"><i class="bi bi-lightning-fill me-2"></i>Speed Profile</h5>
                    <p class="small mb-3" style="color:#8899aa;">How fast or controlling your deck looks by CMC spread</p>
                    <?php
                    $low  = array_sum(array_slice($curve_data, 0, 3)); // 0-2
                    $mid  = array_sum(array_slice($curve_data, 3, 2)); // 3-4
                    $high = array_sum(array_slice($curve_data, 5));    // 5+
                    $total_for_pct = max($low + $mid + $high, 1);
                    $low_pct  = round($low  / $total_for_pct * 100);
                    $mid_pct  = round($mid  / $total_for_pct * 100);
                    $high_pct = round($high / $total_for_pct * 100);

                    if ($avg_cmc <= 2.2)       $speed = ['Aggro / Tempo',     '#f87171', 'Heavy low-cost pressure. Very fast.'];
                    elseif ($avg_cmc <= 3.2)   $speed = ['Midrange',          '#fbbf24', 'Balanced curve — efficient threats and answers.'];
                    elseif ($avg_cmc <= 4.2)   $speed = ['Midrange / Control','#a78bfa', 'Slower build-up, higher impact threats.'];
                    else                       $speed = ['Control / Big Mana','#6ea8fe', 'High curve. Likely needs ramp or control elements.'];
                    [$speed_label, $speed_color, $speed_desc] = $speed;
                    ?>
                    <div class="mb-3">
                        <span class="badge fs-6 px-3 py-2" style="background:rgba(167,139,250,0.1);color:<?= $speed_color ?>;border:1px solid <?= $speed_color ?>40;">
                            <?= $speed_label ?>
                        </span>
                        <p class="small mt-2 mb-0" style="color:#8899aa;"><?= $speed_desc ?></p>
                    </div>
                    <div class="mb-2">
                        <?php foreach ([['Low (0–2)', $low_pct, '#75b798'], ['Mid (3–4)', $mid_pct, '#fbbf24'], ['High (5+)', $high_pct, '#f87171']] as [$lbl, $pct, $col]): ?>
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <span style="color:#8899aa;font-size:0.78rem;min-width:60px;"><?= $lbl ?></span>
                            <div class="flex-grow-1" style="background:rgba(255,255,255,0.06);border-radius:4px;height:8px;overflow:hidden;">
                                <div style="width:<?= $pct ?>%;height:100%;background:<?= $col ?>;border-radius:4px;"></div>
                            </div>
                            <span style="color:#e8e8e8;font-size:0.78rem;min-width:32px;text-align:right;"><?= $pct ?>%</span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php if ($main_count > 0): ?>
                        <p class="small mb-0" style="color:#8899aa;">
                            Lands make up <strong style="color:#a78bfa;"><?= round(($main_count - $total_spell_count) / max($main_count,1) * 100) ?>%</strong> of the main deck.
                        </p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php if (!empty($token_cards)): ?>
        <!-- Token Cards -->
        <div class="col-12 mb-4">
            <div class="card shadow-sm" style="border-top:4px solid #8899aa;">
                <div class="card-body">
                    <h5 style="color:#8899aa;">
                        <i class="bi bi-stars me-2"></i>Token Cards
                        <span class="badge ms-2" style="background:rgba(136,153,170,0.2);color:#8899aa;border:1px solid rgba(136,153,170,0.4);"><?= $token_count ?> type<?= $token_count !== 1 ? 's' : '' ?></span>
                    </h5>
                    <p class="small mb-3" style="color:#8899aa;">Token cards associated with this deck — keep these handy during play.</p>
                    <div class="d-flex flex-wrap gap-3">
                        <?php foreach ($token_cards as $tc): ?>
                        <div class="d-flex align-items-center gap-2 px-3 py-2 rounded"
                             style="background:rgba(255,255,255,0.04);border:1px solid rgba(136,153,170,0.25);">
                            <?php if ($tc['image_uri']): ?>
                            <img src="<?= htmlspecialchars($tc['image_uri']) ?>"
                                 alt="<?= htmlspecialchars($tc['name']) ?>"
                                 style="height:48px;border-radius:4px;" loading="lazy">
                            <?php endif; ?>
                            <div>
                                <div style="color:#e8e8e8;font-size:0.85rem;font-weight:600;">
                                    <?= htmlspecialchars($tc['name']) ?>
                                    <?php if ((int)$tc['quantity'] > 1): ?>
                                        <span style="color:#8899aa;font-size:0.8rem;font-weight:400;"> ×<?= (int)$tc['quantity'] ?></span>
                                    <?php endif; ?>
                                </div>
                                <div style="color:#8899aa;font-size:0.75rem;"><?= htmlspecialchars($tc['type_line']) ?></div>
                                <?php if ($tc['power'] !== null): ?>
                                <div style="color:#8899aa;font-size:0.75rem;"><?= htmlspecialchars($tc['power']) ?>/<?= htmlspecialchars($tc['toughness']) ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Missing Cards -->
        <div class="col-12 mb-4">
            <div class="card shadow-sm" style="border-top:4px solid <?= $missing_count > 0 ? '#f87171' : '#4ade80' ?>;">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <h5 style="color:<?= $missing_count > 0 ? '#f87171' : '#4ade80' ?>;">
                            <i class="bi bi-<?= $missing_count > 0 ? 'exclamation-circle' : 'check-circle' ?> me-2"></i>Missing Cards
                            <span class="badge ms-2" style="background:<?= $missing_count > 0 ? 'rgba(248,113,113,0.15)' : 'rgba(74,222,128,0.15)' ?>;color:<?= $missing_count > 0 ? '#f87171' : '#4ade80' ?>;border:1px solid <?= $missing_count > 0 ? 'rgba(248,113,113,0.4)' : 'rgba(74,222,128,0.4)' ?>;">
                                <?= $missing_count ?>
                            </span>
                        </h5>
                        <?php if ($missing_count > 0 && $missing_value > 0): ?>
                        <span class="small" style="color:#8899aa;">
                            Est. to complete: <strong style="color:#c9a227;">$<?= number_format($missing_value, 2) ?></strong>
                        </span>
                        <?php endif; ?>
                    </div>

                    <?php if ($missing_count === 0): ?>
                        <p class="mb-0 small" style="color:#4ade80;">You own all the cards in this deck!</p>
                    <?php else: ?>
                        <p class="small mb-3" style="color:#8899aa;">These cards are in your deck but not in your collection (or not enough copies).</p>
                        <div class="table-responsive">
                            <table class="table table-sm mb-0" style="font-size:0.85rem;">
                                <thead>
                                    <tr style="color:#8899aa;border-bottom:1px solid rgba(255,255,255,0.1);">
                                        <th>Card</th>
                                        <th class="text-center">Need</th>
                                        <th class="text-center">Own</th>
                                        <th class="text-center">Missing</th>
                                        <th class="text-end">Price</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($missing_cards as $mc): ?>
                                    <tr style="border-bottom:1px solid rgba(255,255,255,0.05);">
                                        <td>
                                            <span style="color:#e8e8e8;"><?= htmlspecialchars($mc['name']) ?></span>
                                            <br><span class="small" style="color:#8899aa;"><?= htmlspecialchars($mc['type_line']) ?></span>
                                        </td>
                                        <td class="text-center" style="color:#8899aa;"><?= (int)$mc['needed'] ?></td>
                                        <td class="text-center" style="color:#8899aa;"><?= (int)$mc['owned'] ?></td>
                                        <td class="text-center fw-bold" style="color:#f87171;"><?= (int)$mc['missing_qty'] ?></td>
                                        <td class="text-end" style="color:#c9a227;">
                                            <?= $mc['price_usd'] !== null ? '$' . number_format((float)$mc['price_usd'], 2) : '—' ?>
                                        </td>
                                        <td class="text-end">
                                            <button class="btn btn-sm btn-outline-warning add-missing-to-wishlist"
                                                    data-card-id="<?= htmlspecialchars($mc['id']) ?>"
                                                    data-card-name="<?= htmlspecialchars($mc['name']) ?>"
                                                    title="Add to wishlist">
                                                <i class="bi bi-heart"></i>
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

    </div>
    <?php endif; ?>

</div><!-- /container-fluid -->

<!-- Export Modal (editor) -->
<div class="modal fade" id="editorExportModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" style="color:#c9a227;">
                    <i class="bi bi-box-arrow-up me-2"></i>Deck Exported!
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center py-4">
                <p style="color:#e8e8e8;" id="editor-export-name"></p>
                <p class="mb-2" style="color:#8899aa;font-size:0.85rem;">Share this code with anyone:</p>
                <div class="d-flex align-items-center justify-content-center gap-2 my-3">
                    <code id="editor-export-code"
                          style="font-size:1.6rem;font-weight:700;color:#c9a227;letter-spacing:0.12em;
                                 background:rgba(201,162,39,0.1);padding:0.4rem 1rem;border-radius:8px;
                                 border:1px solid rgba(201,162,39,0.3);"></code>
                    <button class="btn btn-sm btn-outline-secondary" id="editor-copy-code-btn" title="Copy">
                        <i class="bi bi-clipboard"></i>
                    </button>
                </div>
                <p class="small mb-0" style="color:#8899aa;">
                    Anyone with this code can import a copy on the
                    <a href="import_deck.php" style="color:#c9a227;">Import Deck</a> page.
                </p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
document.getElementById('save-deck-btn').addEventListener('click', function() {
    const form = document.getElementById('edit-deck-form');
    fetch('ajax/update_deck_details.php', { method: 'POST', body: new FormData(form) })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            document.getElementById('deck-name-display').textContent = data.name;
            document.getElementById('deck-desc-display').textContent = data.description || 'No description';
            bootstrap.Modal.getInstance(document.getElementById('editDeckModal')).hide();
            showToast('Deck details updated', 'success');
        } else {
            showToast(data.error || 'Update failed', 'danger');
        }
    })
    .catch(() => showToast('Network error', 'danger'));
});


// ── Export from editor ───────────────────────────────────────────────────────
document.getElementById('editor-export-btn').addEventListener('click', async function () {
    const deckId   = this.dataset.deckId;
    const deckName = this.dataset.deckName;
    this.disabled  = true;
    this.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';

    const fd = new FormData();
    fd.append('deck_id', deckId);
    try {
        const res  = await fetch('ajax/export_deck.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (!data.success) {
            showToast(data.error || 'Export failed', 'danger');
        } else {
            document.getElementById('editor-export-code').textContent = data.code;
            document.getElementById('editor-export-name').textContent = '"' + data.deck_name + '" is ready to share.';
            new bootstrap.Modal(document.getElementById('editorExportModal')).show();
        }
    } catch (_) {
        showToast('Network error', 'danger');
    } finally {
        this.disabled  = false;
        this.innerHTML = '<i class="bi bi-box-arrow-up me-1"></i>Export';
    }
});

document.getElementById('editor-copy-code-btn').addEventListener('click', function () {
    const code = document.getElementById('editor-export-code').textContent;
    navigator.clipboard.writeText(code).then(() => {
        this.innerHTML = '<i class="bi bi-clipboard-check"></i>';
        setTimeout(() => { this.innerHTML = '<i class="bi bi-clipboard"></i>'; }, 2000);
    });
});
// ─────────────────────────────────────────────────────────────────────────────
// ── Deck Editor AJAX ─────────────────────────────────────────────────────────
const DECK_ID = <?= $deck_id ?>;

async function refreshPanels() {
    const fd = new FormData();
    fd.append('deck_id', DECK_ID);
    try {
        const res  = await fetch('ajax/deck_panels_partial.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.error) { showToast(data.error, 'danger'); return; }

        // Preserve scroll positions
        const colPanel  = document.getElementById('collection-panel-body');
        const deckPanel = document.getElementById('deck-panel-body');
        const colScroll  = colPanel.scrollTop;
        const deckScroll = deckPanel.scrollTop;

        colPanel.innerHTML  = data.collection_html;
        deckPanel.innerHTML = data.deck_html;

        colPanel.scrollTop  = colScroll;
        deckPanel.scrollTop = deckScroll;

        attachDeckListeners();
    } catch(e) {
        showToast('Network error refreshing panels', 'danger');
    }
}

async function deckAction(url, formData) {
    formData.append('ajax', '1');
    try {
        const res  = await fetch(url, { method: 'POST', body: formData });
        const data = await res.json();
        if (!data.success) { showToast(data.error || 'Action failed', 'danger'); return; }
        const labels = { added: 'Card added to deck', removed: 'Card removed', updated: 'Quantity updated' };
        showToast(labels[data.msg] || 'Done', 'success');
        await refreshPanels();
    } catch(e) {
        showToast('Network error', 'danger');
    }
}

function attachDeckListeners() {
    // Add buttons
    document.querySelectorAll('.add-to-deck-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            const qty = btn.closest('.d-flex').querySelector('.deck-qty-input').value;
            const fd  = new FormData();
            fd.append('deck_id', btn.dataset.deckId);
            fd.append('card_id', btn.dataset.cardId);
            fd.append('quantity', qty);
            deckAction('actions/add_to_deck.php', fd);
        });
    });

    // Remove buttons
    document.querySelectorAll('.remove-from-deck-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            if (!confirm('Remove all copies?')) return;
            const fd = new FormData();
            fd.append('deck_id', btn.dataset.deckId);
            fd.append('card_id', btn.dataset.cardId);
            deckAction('ajax/remove_from_deck.php', fd);
        });
    });

    // Update buttons
    document.querySelectorAll('.update-deck-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            const qty = btn.closest('.d-flex').querySelector('.deck-update-qty').value;
            const fd  = new FormData();
            fd.append('deck_id', btn.dataset.deckId);
            fd.append('card_id', btn.dataset.cardId);
            fd.append('quantity', qty);
            deckAction('actions/update_deck_card.php', fd);
        });
    });
}

document.addEventListener('DOMContentLoaded', attachDeckListeners);
// ─────────────────────────────────────────────────────────────────────────────

function showToast(message, type = 'success') {
    let container = document.getElementById('toast-container');
    if (!container) {
        container = document.createElement('div');
        container.id = 'toast-container';
        container.className = 'toast-container position-fixed top-0 end-0 p-3';
        container.style.zIndex = '1100';
        document.body.appendChild(container);
    }
    const toastEl = document.createElement('div');
    toastEl.className = `toast align-items-center text-white bg-${type} border-0`;
    toastEl.setAttribute('role', 'alert');
    toastEl.innerHTML = `<div class="d-flex"><div class="toast-body">${message}</div>
        <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div>`;
    container.appendChild(toastEl);
    const toast = new bootstrap.Toast(toastEl, { delay: 3000 });
    toast.show();
    toastEl.addEventListener('hidden.bs.toast', () => toastEl.remove());
}

// ── Missing Cards — Add to Wishlist ──────────────────────────────────────────
document.querySelectorAll('.add-missing-to-wishlist').forEach(btn => {
    btn.addEventListener('click', async function () {
        const cardId   = this.dataset.cardId;
        const cardName = this.dataset.cardName;
        const fd = new FormData();
        fd.append('card_id',  cardId);
        fd.append('priority', '2'); // default Medium
        try {
            const res  = await fetch('ajax/add_to_wishlist.php', { method: 'POST', body: fd });
            const data = await res.json();
            if (data.success) {
                this.innerHTML = '<i class="bi bi-heart-fill"></i>';
                this.disabled  = true;
                showToast('Added <strong>' + cardName + '</strong> to wishlist', 'success');
            } else {
                showToast(data.error || 'Could not add to wishlist', 'danger');
            }
        } catch (_) {
            showToast('Network error', 'danger');
        }
    });
});
// ─────────────────────────────────────────────────────────────────────────────
</script>

<?php
$dbc->close();
include __DIR__ . '/includes/footer.php';
?>
