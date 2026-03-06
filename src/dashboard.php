<?php
include 'header.php';

if (!isLoggedIn()) {
    global $_session_kicked;
    $msg = $_session_kicked
        ? "You+were+signed+in+elsewhere.+This+session+has+ended."
        : "Please+log+in+to+continue.";
    header("Location: index.php?error=" . $msg);
    exit();
}

include 'connect.php';
$user_id = getUserId();

// Collection count
$col_stmt = mysqli_prepare($dbc, "SELECT COUNT(*) as total FROM user_collection WHERE user_id = ?");
mysqli_stmt_bind_param($col_stmt, "i", $user_id);
mysqli_stmt_execute($col_stmt);
$col_count = mysqli_fetch_assoc(mysqli_stmt_get_result($col_stmt))['total'] ?? 0;

// Deck count
$deck_stmt = mysqli_prepare($dbc, "SELECT COUNT(*) as total FROM decks WHERE user_id = ?");
mysqli_stmt_bind_param($deck_stmt, "i", $user_id);
mysqli_stmt_execute($deck_stmt);
$deck_count = mysqli_fetch_assoc(mysqli_stmt_get_result($deck_stmt))['total'] ?? 0;

// Wishlist count
$wish_stmt = mysqli_prepare($dbc, "SELECT COUNT(*) as total FROM wishlist WHERE user_id = ?");
mysqli_stmt_bind_param($wish_stmt, "i", $user_id);
mysqli_stmt_execute($wish_stmt);
$wish_count = mysqli_fetch_assoc(mysqli_stmt_get_result($wish_stmt))['total'] ?? 0;

// Favorite decks — hard cap at 18 (3 rows of 2, matches 3 COTD slots)
$fav_stmt = mysqli_prepare($dbc, "SELECT id, name, description FROM decks WHERE user_id = ? AND is_favorite = 1 ORDER BY name LIMIT 18");
mysqli_stmt_bind_param($fav_stmt, "i", $user_id);
mysqli_stmt_execute($fav_stmt);
$fav_result = mysqli_stmt_get_result($fav_stmt);
$fav_rows = [];
while ($r = mysqli_fetch_assoc($fav_result)) $fav_rows[] = $r;
$fav_count = count($fav_rows);

// Recently added (last 8 by row insertion order)
$recent_stmt = mysqli_prepare($dbc,
    "SELECT c.id, c.name, c.image_uri, c.rarity, c.mana_cost, s.name as set_name, uc.quantity
     FROM user_collection uc
     JOIN cards c ON uc.card_id = c.id
     LEFT JOIN sets s ON c.set_id = s.id
     WHERE uc.user_id = ?
     ORDER BY uc.card_id DESC
     LIMIT 8");
mysqli_stmt_bind_param($recent_stmt, "i", $user_id);
mysqli_stmt_execute($recent_stmt);
$recent_result = mysqli_stmt_get_result($recent_stmt);

// ── Daily Cards pile ──────────────────────────────────────────────────────────
// Auto-create table if it doesn't exist
$dbc->query("CREATE TABLE IF NOT EXISTS daily_cards (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    card_id      VARCHAR(36) NOT NULL,
    display_date DATE NOT NULL UNIQUE,
    INDEX idx_date (display_date)
)");

// Use MySQL CURDATE() as single source of truth for today's date.
$today = $dbc->query("SELECT CURDATE() AS today")->fetch_assoc()['today'];

// ── Gap detection and fill ────────────────────────────────────────────────────
// Generate every date from the earliest daily_cards record to today using a
// recursive CTE, then find which dates have no entry and fill them.
// This is entirely MySQL-driven — no PHP date arithmetic involved.
//
// If the table is empty, seed just today and let future visits fill forward.
$has_any = (int)$dbc->query("SELECT COUNT(*) AS n FROM daily_cards")->fetch_assoc()['n'];

if ($has_any === 0) {
    // First ever visit — seed today
    $rand = $dbc->query(
        "SELECT c.id FROM cards c
         WHERE c.type_line NOT LIKE '%Token%'
           AND c.type_line NOT LIKE '%Basic Land%'
           AND c.image_uri IS NOT NULL
         ORDER BY RAND() LIMIT 1"
    );
    if ($rand_row = $rand->fetch_assoc()) {
        $ins = $dbc->prepare("INSERT IGNORE INTO daily_cards (card_id, display_date) VALUES (?, CURDATE())");
        $ins->bind_param("s", $rand_row['id']);
        $ins->execute();
        $ins->close();
    }
} else {
    // Find every missing date between earliest record and today using a
    // recursive CTE date series joined against existing records.
    $gaps = $dbc->query(
        "WITH RECURSIVE date_series AS (
             SELECT MIN(display_date) AS d FROM daily_cards
             UNION ALL
             SELECT DATE_ADD(d, INTERVAL 1 DAY)
             FROM date_series
             WHERE d < CURDATE()
         )
         SELECT d
         FROM date_series
         LEFT JOIN daily_cards ON daily_cards.display_date = d
         WHERE daily_cards.display_date IS NULL
         ORDER BY d ASC"
    );

    if ($gaps && $gaps->num_rows > 0) {
        // Prepare a single insert statement and fill each gap with a unique card
        $ins = $dbc->prepare(
            "INSERT IGNORE INTO daily_cards (card_id, display_date)
             SELECT c.id, ?
             FROM cards c
             WHERE c.type_line NOT LIKE '%Token%'
               AND c.type_line NOT LIKE '%Basic Land%'
               AND c.image_uri IS NOT NULL
               AND c.id NOT IN (SELECT card_id FROM daily_cards)
             ORDER BY RAND()
             LIMIT 1"
        );
        while ($gap = $gaps->fetch_assoc()) {
            $ins->bind_param("s", $gap['d']);
            $ins->execute();
        }
        $ins->close();
    }
}

// How many COTD slots we need:
// Every 6 favorite decks = 1 COTD card. Hard cap at 3 (matches 18 max favs).
// Always show at least 1.
$cotd_slots = max(1, min(3, (int)ceil($fav_count / 6)));
// But only show as many as we actually have in the pile
// Fetch pile newest-first, limited to cotd_slots
$pile_stmt = $dbc->prepare(
    "SELECT dc.display_date, c.id, c.name, c.image_uri, c.rarity,
            c.mana_cost, c.type_line, c.oracle_text, c.flavor_text, c.keywords,
            s.name as set_name
     FROM daily_cards dc
     JOIN cards c ON c.id = dc.card_id
     LEFT JOIN sets s ON c.set_id = s.id
     ORDER BY dc.display_date DESC
     LIMIT ?"
);
$pile_stmt->bind_param("i", $cotd_slots);
$pile_stmt->execute();
$pile_rows = $pile_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$pile_stmt->close();

// Gap fill already ran above — pile is now up to date including any missed days

mysqli_close($dbc);
?>

<div class="container my-4">
    <h1 class="text-center mb-5" style="color:#c9a227; text-shadow: 0 0 16px rgba(201,162,39,0.4);">
        Welcome, <?= htmlspecialchars(getCurrentUser()) ?>!
    </h1>

    <!-- Favorite Decks + Card of the Day -->
    <!-- min-height = heading (~44px) + 3 rows of cards (3*120px) + 3 gaps (3*16px) = ~440px -->
    <div class="row g-4 mb-5" id="fav-cotd-row" style="min-height:440px;">

        <!-- Left: Favorite Decks -->
        <div class="col-lg-8 d-flex flex-column">
            <h2 class="mb-3" style="color:#c9a227;"><i class="bi bi-star-fill text-warning me-2"></i>Favorite Decks</h2>
            <?php if ($fav_count > 0): ?>
                <div class="row row-cols-1 row-cols-md-2 g-3">
                    <?php foreach ($fav_rows as $fav): ?>
                        <div class="col">
                            <div class="card h-100 shadow-sm">
                                <div class="card-body">
                                    <h5 class="card-title"><?= htmlspecialchars($fav['name']) ?></h5>
                                    <p class="card-text text-muted small"><?= htmlspecialchars($fav['description'] ?: 'No description') ?></p>
                                    <a href="deck_editor.php?deck_id=<?= $fav['id'] ?>" class="btn btn-sm btn-success">Open Deck</a>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="p-4 rounded text-center d-flex flex-column justify-content-center"
                     style="border:1px dashed rgba(201,162,39,0.3);background:rgba(201,162,39,0.04);flex:1;min-height:360px;">
                    <i class="bi bi-star fs-2 mb-2 d-block" style="color:rgba(201,162,39,0.4);"></i>
                    <p class="mb-1" style="color:#e8e8e8;">No favorite decks yet.</p>
                    <p class="small mb-3" style="color:#8899aa;">
                        Star any deck from <a href="decks.php" style="color:#c9a227;">My Decks</a> to pin it here.
                    </p>
                    <a href="decks.php" class="btn btn-sm btn-outline-warning">
                        <i class="bi bi-stack me-1"></i>Go to My Decks
                    </a>
                </div>
            <?php endif; ?>
        </div>

        <!-- Right: Card of the Day pile -->
        <div class="col-lg-4 d-flex flex-column" id="cotd-col">
            <h2 class="mb-3" style="color:#c9a227;"><i class="bi bi-stars me-2"></i>Card of the Day</h2>
            <div id="cotd-pile" class="d-flex flex-column gap-3">
                <?php if (empty($pile_rows)): ?>
                    <div class="card shadow-sm p-3 text-center" style="border-top:3px solid #c9a227;color:#8899aa;">
                        No cards yet — visit again tomorrow or re-run the Scryfall importer.
                    </div>
                <?php else: ?>
                    <?php foreach ($pile_rows as $pc):
                        $is_today = ($pc['display_date'] === $today);
                        $rarity   = $pc['rarity'] ?? 'common';
                        $rc_map   = ['common'=>'#9ca3af','uncommon'=>'#c0c0c0','rare'=>'#c9a227','mythic'=>'#f97316'];
                        $rc       = $rc_map[$rarity] ?? '#9ca3af';
                        $rl       = ucfirst($rarity);
                        $card_json = htmlspecialchars(json_encode([
                            'id'          => $pc['id'],
                            'name'        => $pc['name'],
                            'mana_cost'   => $pc['mana_cost'],
                            'type_line'   => $pc['type_line'],
                            'oracle_text' => $pc['oracle_text'],
                            'flavor_text' => $pc['flavor_text'],
                            'rarity'      => $rarity,
                            'image_uri'   => $pc['image_uri'],
                            'set_name'    => $pc['set_name'],
                            'quantity'    => null,
                        ]), ENT_QUOTES);
                    ?>
                    <div class="cotd-card"
                         style="flex:1 1 0;min-height:0;position:relative;border-radius:8px;overflow:hidden;
                                cursor:pointer;border:1px solid <?= $is_today ? 'rgba(201,162,39,0.4)' : 'rgba(255,255,255,0.08)' ?>;
                                box-shadow:0 4px 16px rgba(0,0,0,0.4);"
                         onclick='openCardModal(<?= $card_json ?>)'>
                        <!-- Full-art background image -->
                        <?php if ($pc['image_uri']): ?>
                            <img src="<?= htmlspecialchars($pc['image_uri']) ?>"
                                 alt="<?= htmlspecialchars($pc['name']) ?>"
                                 style="position:absolute;inset:0;width:100%;height:100%;object-fit:cover;object-position:top center;">
                        <?php else: ?>
                            <div style="position:absolute;inset:0;background:#0d0d1a;"></div>
                        <?php endif; ?>
                        <!-- Gradient scrim so text is readable -->
                        <div style="position:absolute;inset:0;background:linear-gradient(to bottom,
                                    transparent 0%,
                                    transparent 45%,
                                    rgba(10,10,20,0.6) 65%,
                                    rgba(10,10,20,0.92) 100%);"></div>
                        <!-- Text overlay pinned to bottom -->
                        <div style="position:absolute;bottom:0;left:0;right:0;padding:12px 14px 12px;">
                            <div class="d-flex align-items-start justify-content-between gap-1 mb-1">
                                <span class="fw-bold small" style="color:#fff;line-height:1.3;text-shadow:0 1px 4px rgba(0,0,0,0.8);"><?= htmlspecialchars($pc['name']) ?></span>
                                <div class="d-flex flex-column align-items-end gap-1 flex-shrink-0">
                                    <span class="badge" style="background:rgba(0,0,0,0.55);color:<?= $rc ?>;border:1px solid <?= $rc ?>55;font-size:0.65rem;backdrop-filter:blur(4px);"><?= $rl ?></span>
                                    <span style="color:rgba(201,162,39,0.85);font-size:0.65rem;white-space:nowrap;text-shadow:0 1px 3px rgba(0,0,0,0.9);">
                                        <?= $is_today ? '✦ Today' : date('M j', strtotime($pc['display_date'])) ?>
                                    </span>
                                </div>
                            </div>
                            <p class="mb-1" style="color:rgba(200,210,220,0.85);font-size:0.74rem;line-height:1.3;text-shadow:0 1px 3px rgba(0,0,0,0.9);"><?= htmlspecialchars($pc['type_line'] ?? '') ?></p>
                            <?php if ($pc['oracle_text']): ?>
                                <p class="mb-0" style="color:rgba(232,232,232,0.9);white-space:pre-wrap;line-height:1.35;font-size:0.75rem;text-shadow:0 1px 3px rgba(0,0,0,0.9);display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden;"><?= htmlspecialchars($pc['oracle_text']) ?></p>
                            <?php endif; ?>
                            <?php if ($pc['flavor_text']): ?>
                                <p class="mt-1 mb-0 fst-italic" style="color:rgba(160,175,190,0.85);border-left:2px solid rgba(201,162,39,0.5);padding-left:6px;font-size:0.71rem;text-shadow:0 1px 3px rgba(0,0,0,0.9);display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;"><?= htmlspecialchars($pc['flavor_text']) ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Stat Cards -->
    <div class="row text-center g-4 mb-5">
        <div class="col-md-4">
            <div class="card shadow-sm h-100 stat-card-collection">
                <div class="card-body py-4">
                    <i class="bi bi-collection fs-1 text-primary mb-2 d-block"></i>
                    <h5 class="text-muted mb-1">Collection</h5>
                    <p class="display-5 fw-bold mb-3"><?= $col_count ?></p>
                    <a href="collection.php" class="btn btn-outline-primary btn-sm">View Collection</a>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card shadow-sm h-100 stat-card-decks">
                <div class="card-body py-4">
                    <i class="bi bi-stack fs-1 text-success mb-2 d-block"></i>
                    <h5 class="text-muted mb-1">Decks</h5>
                    <p class="display-5 fw-bold mb-3"><?= $deck_count ?></p>
                    <a href="decks.php" class="btn btn-outline-success btn-sm">Manage Decks</a>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card shadow-sm h-100 stat-card-wishlist">
                <div class="card-body py-4">
                    <i class="bi bi-heart fs-1 mb-2 d-block" style="color:#c9a227;"></i>
                    <h5 class="text-muted mb-1">Wishlist</h5>
                    <p class="display-5 fw-bold mb-3"><?= $wish_count ?></p>
                    <a href="wishlist.php" class="btn btn-sm" style="border-color:#c9a227;color:#c9a227;">View Wishlist</a>
                </div>
            </div>
        </div>
    </div>

    <!-- Recently Added -->
    <?php if (mysqli_num_rows($recent_result) > 0): ?>
    <div class="mb-5">
        <h2 class="mb-3" style="color:#c9a227;"><i class="bi bi-clock-history me-2"></i>Recently Added</h2>
        <div class="row row-cols-2 row-cols-sm-4 row-cols-md-8 g-3">
            <?php while ($rc = mysqli_fetch_assoc($recent_result)):
                $r = $rc['rarity'] ?? 'common';
            ?>
                <div class="col">
                    <div class="card h-100 shadow-sm rarity-<?= htmlspecialchars($r) ?> text-center p-0"
                         style="cursor:pointer;"
                         onclick="openCardModal(<?= htmlspecialchars(json_encode($rc)) ?>)">
                        <?php if ($rc['image_uri']): ?>
                            <img src="<?= $rc['image_uri'] ?>" class="card-img-top"
                                 alt="<?= htmlspecialchars($rc['name']) ?>"
                                 style="height:130px;object-fit:contain;background:#0d0d1a;">
                        <?php else: ?>
                            <div class="d-flex align-items-center justify-content-center bg-dark" style="height:130px;">
                                <i class="bi bi-image text-muted fs-3"></i>
                            </div>
                        <?php endif; ?>
                        <div class="card-body p-1">
                            <p class="small mb-0 text-truncate" title="<?= htmlspecialchars($rc['name']) ?>">
                                <?= htmlspecialchars($rc['name']) ?>
                            </p>
                        </div>
                    </div>
                </div>
            <?php endwhile; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Quick Actions -->
    <div>
        <h2 class="mb-3" style="color:#c9a227;">Quick Actions</h2>
        <div class="list-group">
            <a href="search.php"      class="list-group-item list-group-item-action"><i class="bi bi-search me-2"></i>Search for Cards</a>
            <a href="create_deck.php" class="list-group-item list-group-item-action"><i class="bi bi-plus-circle me-2"></i>Create New Deck</a>
            <a href="collection.php"  class="list-group-item list-group-item-action"><i class="bi bi-collection me-2"></i>Browse Your Collection</a>
        </div>
    </div>
</div>

<!-- Card Quick-View Modal -->
<div class="modal fade" id="cardModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="cardModalTitle" style="color:#c9a227;"></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body d-flex flex-column flex-md-row gap-3">
                <img id="cardModalImg" src="" alt="" style="width:200px;border-radius:8px;object-fit:contain;background:#0d0d1a;flex-shrink:0;">
                <div id="cardModalInfo" class="small flex-grow-1"></div>
            </div>
        </div>
    </div>
</div>

<script>
function escHtml(str) {
    if (!str) return '';
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}
function escMana(text) {
    return escHtml(text).replace(/\{([^}]+)\}/g, (_, sym) =>
        `<span style="display:inline-block;min-width:1.2em;text-align:center;border-radius:50%;background:rgba(201,162,39,0.2);color:#c9a227;font-size:0.75em;padding:0 2px;font-weight:700;">{${sym}}</span>`
    );
}

function openCardModal(card) {
    document.getElementById('cardModalTitle').textContent = card.name;
    const img = document.getElementById('cardModalImg');
    img.src   = card.image_uri || '';
    img.style.display = card.image_uri ? 'block' : 'none';
    const r = card.rarity || 'common';
    const rl = r.charAt(0).toUpperCase() + r.slice(1);
    const oracleHtml = card.oracle_text
        ? `<div class="mt-2 p-2 rounded small" style="background:rgba(255,255,255,0.05);color:#e8e8e8;white-space:pre-wrap;line-height:1.5;">${escMana(card.oracle_text)}</div>`
        : '';
    const flavorHtml = card.flavor_text
        ? `<div class="mt-1 p-2 rounded small fst-italic" style="background:rgba(255,255,255,0.03);color:#8899aa;border-left:2px solid rgba(201,162,39,0.3);">${escHtml(card.flavor_text)}</div>`
        : '';
    document.getElementById('cardModalInfo').innerHTML = `
        <table class="table table-sm mb-1" style="color:#e8e8e8;font-size:0.85rem;">
            <tr><td class="text-muted" style="width:80px;">Set</td>    <td><strong>${escHtml(card.set_name||'—')}</strong></td></tr>
            <tr><td class="text-muted">Mana</td>   <td><strong>${escHtml(card.mana_cost||'—')}</strong></td></tr>
            <tr><td class="text-muted">Type</td>   <td><strong>${escHtml(card.type_line||'—')}</strong></td></tr>
            <tr><td class="text-muted">Rarity</td> <td><strong>${rl}</strong></td></tr>
            ${card.quantity != null ? `<tr><td class="text-muted">Owned</td><td><strong>${card.quantity}</strong></td></tr>` : ''}
        </table>
        ${oracleHtml}${flavorHtml}
    `;
    new bootstrap.Modal(document.getElementById('cardModal')).show();
}

// Make COTD pile stretch to match fav decks column height
function syncCotdHeight() {
    const MIN_H   = 440; // matches row min-height: heading + 3 card-rows + gaps
    const favCol  = document.querySelector('#fav-cotd-row .col-lg-8');
    const cotdCol = document.getElementById('cotd-col');
    const pile    = document.getElementById('cotd-pile');
    const cards   = pile ? pile.querySelectorAll('.cotd-card') : [];
    if (!favCol || !cotdCol || !pile) return;

    if (window.innerWidth >= 992) {
        // Desktop: match fav column height, cards flex equally inside pile
        const targetH = Math.max(MIN_H, favCol.offsetHeight);
        cotdCol.style.height     = targetH + 'px';
        pile.style.flex          = '1';
        pile.style.display       = 'flex';
        pile.style.flexDirection = 'column';
        cards.forEach(c => { c.style.height = ''; c.style.minHeight = ''; });
    } else {
        // Mobile: release fixed heights, give each card an explicit height
        // so full-art image is visible (280px each is comfortable on phones)
        cotdCol.style.height     = '';
        pile.style.flex          = '';
        pile.style.display       = 'flex';
        pile.style.flexDirection = 'column';
        cards.forEach(c => {
            c.style.height    = '280px';
            c.style.minHeight = '280px';
        });
    }
}
window.addEventListener('load', syncCotdHeight);
window.addEventListener('resize', syncCotdHeight);
</script>

<?php include 'footer.php'; ?>
