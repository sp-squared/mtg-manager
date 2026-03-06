<?php
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/connect.php';

// Handle fallback message from non-AJAX submissions
$message = '';
if (isset($_GET['msg'])) {
    switch ($_GET['msg']) {
        case 'added':   $message = 'Card added to your collection!'; break;
        case 'wish_added': $message = 'Card added to your wishlist!'; break;
    }
}

// Strip empty params from query string — keeps URLs clean like Scryfall
function clean_search_params(array $params, array $overrides = []): string {
    $merged = array_merge($params, $overrides);
    $filtered = array_filter($merged, fn($v) => $v !== '' && $v !== null);
    return http_build_query($filtered);
}

$results_per_page = 52;
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $results_per_page;

// Build conditions ...
$conditions = [];
$params = [];
$types = '';

if (!empty($_GET['name'])) {
    $name_input = trim($_GET['name']);
    // Detect Scryfall UUID format (xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx)
    if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $name_input)) {
        $conditions[] = "c.id = ?";
        $params[] = $name_input;
        $types .= 's';
    } else {
        $conditions[] = "c.name LIKE ?";
        $params[] = '%' . $name_input . '%';
        $types .= 's';
    }
}
if (!empty($_GET['set'])) {
    $conditions[] = "c.set_id = ?";
    $params[] = $_GET['set'];
    $types .= 's';
}
if (!empty($_GET['rarity'])) {
    $conditions[] = "c.rarity = ?";
    $params[] = $_GET['rarity'];
    $types .= 's';
}
// Color filtering handled below with color_mode logic
if (!empty($_GET['type'])) {
    $conditions[] = "c.type_line LIKE ?";
    $params[] = '%' . $_GET['type'] . '%';
    $types .= 's';
}
if (!empty($_GET['cmc_min'])) {
    $conditions[] = "c.cmc >= ?";
    $params[] = (float)$_GET['cmc_min'];
    $types .= 'd';
}
if (!empty($_GET['cmc_max'])) {
    $conditions[] = "c.cmc <= ?";
    $params[] = (float)$_GET['cmc_max'];
    $types .= 'd';
}

// Keyword / ability search (searches the stored JSON keywords array)
if (!empty($_GET['keyword'])) {
    $conditions[] = "JSON_SEARCH(LOWER(c.keywords), 'one', LOWER(?)) IS NOT NULL";
    $params[] = '%' . $_GET['keyword'] . '%';
    $types .= 's';
}

// Oracle text search
if (!empty($_GET['oracle'])) {
    $conditions[] = "c.oracle_text LIKE ?";
    $params[] = '%' . $_GET['oracle'] . '%';
    $types .= 's';
}

// Enhanced color filtering
$color_mode    = $_GET['color_mode'] ?? 'any';
$sel_colors    = !empty($_GET['colors']) && is_array($_GET['colors']) ? $_GET['colors'] : [];
$colorless_req = !empty($_GET['colorless']);

if ($colorless_req) {
    // Must have NO colors at all
    $conditions[] = "NOT EXISTS (SELECT 1 FROM card_colors cc WHERE cc.card_id = c.id)";
} elseif (!empty($sel_colors)) {
    if ($color_mode === 'exactly') {
        // Has exactly these colors and no others
        foreach ($sel_colors as $col) {
            $conditions[] = "EXISTS (SELECT 1 FROM card_colors cc WHERE cc.card_id = c.id AND cc.color_id = ?)";
            $params[] = $col; $types .= 's';
        }
        foreach (['W','U','B','R','G'] as $col) {
            if (!in_array($col, $sel_colors)) {
                $conditions[] = "NOT EXISTS (SELECT 1 FROM card_colors cc WHERE cc.card_id = c.id AND cc.color_id = ?)";
                $params[] = $col; $types .= 's';
            }
        }
    } elseif ($color_mode === 'all') {
        // Must include ALL selected colors (original behavior)
        foreach ($sel_colors as $col) {
            $conditions[] = "EXISTS (SELECT 1 FROM card_colors cc WHERE cc.card_id = c.id AND cc.color_id = ?)";
            $params[] = $col; $types .= 's';
        }
    } else {
        // any — must include at least one of the selected colors
        $placeholders = implode(',', array_fill(0, count($sel_colors), '?'));
        $conditions[] = "EXISTS (SELECT 1 FROM card_colors cc WHERE cc.card_id = c.id AND cc.color_id IN ($placeholders))";
        foreach ($sel_colors as $col) { $params[] = $col; $types .= 's'; }
    }
}

$where = $conditions ? "WHERE " . implode(' AND ', $conditions) : '';

// Fetch user's decks for the "Add to Deck" dropdown (only when logged in)
$user_decks = [];
if (isLoggedIn()) {
    $deck_stmt = $dbc->prepare("SELECT id, name FROM decks WHERE user_id = ? ORDER BY name ASC");
    $search_user_id = getUserId();
    $deck_stmt->bind_param("i", $search_user_id);
    $deck_stmt->execute();
    $user_decks = $deck_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $deck_stmt->close();
}

// Sort
$sort_options = [
    'name'     => 'c.name ASC',
    'cmc_asc'  => 'c.cmc ASC, c.name ASC',
    'cmc_desc' => 'c.cmc DESC, c.name ASC',
    'rarity'   => "FIELD(c.rarity,'mythic','rare','uncommon','common'), c.name ASC",
    'set'      => 's.name ASC, c.name ASC',
    'newest'   => 'c.imported_at DESC, c.name ASC',
];
$sort_key = $_GET['sort'] ?? 'newest';
$order_by = $sort_options[$sort_key] ?? $sort_options['name'];

// Count total
$count_sql = "SELECT COUNT(*) as total FROM cards c $where";
$count_stmt = $dbc->prepare($count_sql);
if (!empty($params)) {
    $count_stmt->bind_param($types, ...$params);
}
$count_stmt->execute();
$total_results = $count_stmt->get_result()->fetch_assoc()['total'];
$total_pages = ceil($total_results / $results_per_page);

// Fetch cards for current page
$sql = "SELECT c.id, c.name, c.mana_cost, c.cmc, c.type_line, c.oracle_text, c.rarity,
               c.power, c.toughness, c.loyalty, c.image_uri, c.flavor_text, c.keywords,
               s.name as set_name,
               cp.price_usd, cp.price_usd_foil, cp.price_eur
        FROM cards c
        LEFT JOIN sets s ON c.set_id = s.id
        LEFT JOIN card_prices cp ON cp.card_id = c.id
        $where
        ORDER BY {$order_by}
        LIMIT ? OFFSET ?";
$params[] = $results_per_page;
$params[] = $offset;
$types .= 'ii';

$stmt = $dbc->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$results = $stmt->get_result();
?>

<div class="container my-4">
    <h1 class="text-center mb-4">Card Search</h1>

    <?php if ($message): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <?= htmlspecialchars($message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Search Form -->
    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <form method="get" action="search.php" id="search-form">
                <div class="row g-3">
                    <!-- Row 1: Name / Type / Set / Rarity -->
                    <div class="col-md-3">
                        <label class="form-label">Card Name</label>
                        <input type="text" class="form-control" name="name" value="<?= htmlspecialchars($_GET['name'] ?? '') ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Type Line</label>
                        <input type="text" class="form-control" name="type" placeholder="e.g. Creature" value="<?= htmlspecialchars($_GET['type'] ?? '') ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Set</label>
                        <select class="form-select" name="set">
                            <option value="">Any Set</option>
                            <?php
                            $sets = $dbc->query("SELECT id, name FROM sets ORDER BY name");
                            while ($set = $sets->fetch_assoc()):
                                $selected = ($_GET['set'] ?? '') == $set['id'] ? 'selected' : '';
                                echo '<option value="' . htmlspecialchars($set['id']) . '" ' . $selected . '>' . htmlspecialchars($set['name']) . '</option>';
                            endwhile;
                            ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Rarity</label>
                        <select class="form-select" name="rarity">
                            <option value="">Any</option>
                            <option value="common"   <?= ($_GET['rarity']??'')=='common'   ?'selected':'' ?>>Common</option>
                            <option value="uncommon" <?= ($_GET['rarity']??'')=='uncommon' ?'selected':'' ?>>Uncommon</option>
                            <option value="rare"     <?= ($_GET['rarity']??'')=='rare'     ?'selected':'' ?>>Rare</option>
                            <option value="mythic"   <?= ($_GET['rarity']??'')=='mythic'   ?'selected':'' ?>>Mythic</option>
                        </select>
                    </div>

                    <!-- Row 2: Keyword / Oracle / CMC -->
                    <div class="col-md-3">
                        <label class="form-label">Keyword / Ability</label>
                        <input type="text" class="form-control" name="keyword" placeholder="e.g. Flying, Trample"
                               value="<?= htmlspecialchars($_GET['keyword'] ?? '') ?>">
                    </div>
                    <div class="col-md-5">
                        <label class="form-label">Oracle Text Contains</label>
                        <input type="text" class="form-control" name="oracle" placeholder="e.g. draw a card"
                               value="<?= htmlspecialchars($_GET['oracle'] ?? '') ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">CMC Min</label>
                        <input type="number" step="1" min="0" class="form-control" name="cmc_min" value="<?= htmlspecialchars($_GET['cmc_min'] ?? '') ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">CMC Max</label>
                        <input type="number" step="1" min="0" class="form-control" name="cmc_max" value="<?= htmlspecialchars($_GET['cmc_max'] ?? '') ?>">
                    </div>

                    <!-- Row 3: Colors -->
                    <div class="col-12">
                        <label class="form-label d-block">Color Identity</label>
                        <div class="d-flex flex-wrap align-items-center gap-3">
                            <!-- Color checkboxes -->
                            <?php
                            $color_options  = ['W'=>'White','U'=>'Blue','B'=>'Black','R'=>'Red','G'=>'Green'];
                            $selected_colors = $_GET['colors'] ?? [];
                            foreach ($color_options as $code => $label_name):
                                $checked = in_array($code, $selected_colors) ? 'checked' : '';
                                $pip_colors = ['W'=>'#f9f1d8','U'=>'#1a6bbd','B'=>'#2a2a2a','R'=>'#c0392b','G'=>'#1a7a3c'];
                                $pip_text   = ['W'=>'#1a1a1a','U'=>'#fff','B'=>'#e8e8e8','R'=>'#fff','G'=>'#fff'];
                            ?>
                            <div class="form-check form-check-inline m-0 color-option">
                                <input class="form-check-input color-checkbox" type="checkbox" name="colors[]"
                                       value="<?= $code ?>" id="color_<?= $code ?>" <?= $checked ?>>
                                <label class="form-check-label d-flex align-items-center gap-1" for="color_<?= $code ?>">
                                    <span style="display:inline-flex;align-items:center;justify-content:center;
                                                 width:22px;height:22px;border-radius:50%;font-size:0.7rem;font-weight:700;
                                                 background:<?= $pip_colors[$code] ?>;color:<?= $pip_text[$code] ?>;
                                                 border:1px solid rgba(255,255,255,0.2);"><?= $code ?></span>
                                    <span style="font-size:0.85rem;"><?= $label_name ?></span>
                                </label>
                            </div>
                            <?php endforeach; ?>

                            <!-- Colorless -->
                            <div class="form-check form-check-inline m-0">
                                <input class="form-check-input" type="checkbox" name="colorless" value="1"
                                       id="colorless" <?= !empty($_GET['colorless']) ? 'checked' : '' ?> onchange="toggleColorless(this)">
                                <label class="form-check-label small" for="colorless">Colorless only</label>
                            </div>

                            <!-- Color mode -->
                            <div class="d-flex align-items-center gap-2 ms-2">
                                <span class="small" style="color:#8899aa;">Mode:</span>
                                <?php $cm = $_GET['color_mode'] ?? 'any'; ?>
                                <select class="form-select form-select-sm" name="color_mode" style="width:auto;">
                                    <option value="any"     <?= $cm==='any'     ?'selected':'' ?>>Any of selected</option>
                                    <option value="all"     <?= $cm==='all'     ?'selected':'' ?>>All of selected</option>
                                    <option value="exactly" <?= $cm==='exactly' ?'selected':'' ?>>Exactly these</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mt-3 d-flex gap-2 align-items-center flex-wrap">
                    <button type="submit" class="btn btn-primary"><i class="bi bi-search me-1"></i>Search</button>
                    <a href="search.php" class="btn btn-outline-secondary btn-sm">Clear</a>
                    <div class="ms-auto d-flex align-items-center gap-2">
                        <label class="form-label mb-0 text-nowrap small">Sort by</label>
                        <select class="form-select form-select-sm" name="sort" style="width:auto;" onchange="this.form.submit()">
                            <option value="newest"   <?= ($_GET['sort']??'newest')==='newest'   ?'selected':'' ?>>Newest Import</option>
                            <option value="name"     <?= ($_GET['sort']??'newest')==='name'     ?'selected':'' ?>>Name</option>
                            <option value="cmc_asc"  <?= ($_GET['sort']??'')==='cmc_asc'  ?'selected':'' ?>>CMC ↑</option>
                            <option value="cmc_desc" <?= ($_GET['sort']??'')==='cmc_desc' ?'selected':'' ?>>CMC ↓</option>
                            <option value="rarity"   <?= ($_GET['sort']??'')==='rarity'   ?'selected':'' ?>>Rarity</option>
                            <option value="set"      <?= ($_GET['sort']??'')==='set'      ?'selected':'' ?>>Set</option>
                        </select>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <?php if ($total_results > 0): ?>
        <div class="d-flex justify-content-between align-items-center mb-3">
            <p class="mb-0">Found <strong><?= $total_results ?></strong> cards. Page <?= $page ?> of <?= $total_pages ?>.</p>
            <nav aria-label="Page navigation">
                <ul class="pagination pagination-sm mb-0">
                    <?php if ($page > 1): ?>
                        <li class="page-item"><a class="page-link" href="?<?= clean_search_params($_GET, ['page' => $page-1]) ?>">Previous</a></li>
                    <?php endif; ?>
                    <?php for ($i = max(1, $page-5); $i <= min($total_pages, $page+5); $i++): ?>
                        <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                            <a class="page-link" href="?<?= clean_search_params($_GET, ['page' => $i]) ?>"><?= $i ?></a>
                        </li>
                    <?php endfor; ?>
                    <?php if ($page < $total_pages): ?>
                        <li class="page-item"><a class="page-link" href="?<?= clean_search_params($_GET, ['page' => $page+1]) ?>">Next</a></li>
                    <?php endif; ?>
                </ul>
            </nav>
        </div>

        <div class="row row-cols-1 row-cols-sm-2 row-cols-md-3 row-cols-lg-4 row-cols-xl-5 g-4">
            <?php while ($card = $results->fetch_assoc()): ?>
                <div class="col">
                    <div class="card h-100 shadow-sm rarity-<?= htmlspecialchars($card['rarity'] ?? 'common') ?>">
                        <?php if ($card['image_uri']): ?>
                            <img src="<?= $card['image_uri'] ?>" class="card-img-top card-img-clickable"
                                 alt="<?= htmlspecialchars($card['name']) ?>"
                                 style="height:200px;object-fit:contain;background:#0d0d1a;cursor:pointer;"
                                 onclick="openCardModal(<?= htmlspecialchars(json_encode($card)) ?>)">
                        <?php else: ?>
                            <div class="card-img-top d-flex align-items-center justify-content-center bg-light" style="height: 200px;">
                                <span class="text-muted">No Image</span>
                            </div>
                        <?php endif; ?>
                        <div class="card-body">
                            <h5 class="card-title"><?php
    $r = $card['rarity'] ?? 'common';
    $badge_label = ucfirst($r);
?>
<?= htmlspecialchars($card['name']) ?>
                                <span class="badge badge-rarity-<?= $r ?> float-end small"><?= $badge_label ?></span>
                            </h5>
                            <p class="card-text small">
                                <strong>Mana:</strong> <?= htmlspecialchars($card['mana_cost'] ?? '—') ?><br>
                                <strong>CMC:</strong> <?= $card['cmc'] ?><br>
                                <strong>Type:</strong> <?= htmlspecialchars($card['type_line']) ?><br>
                                <strong>Set:</strong> <?= htmlspecialchars($card['set_name']) ?><br>
                                <strong>Rarity:</strong> <?= $card['rarity'] ?>
                                <?php if ($card['price_usd'] !== null): ?>
                                <br><strong>Price:</strong>
                                <span style="color:#c9a227;">$<?= number_format((float)$card['price_usd'], 2) ?></span>
                                <?php if ($card['price_usd_foil'] !== null): ?>
                                <span style="color:#8899aa;font-size:0.8em;"> / foil $<?= number_format((float)$card['price_usd_foil'], 2) ?></span>
                                <?php endif; ?>
                                <?php endif; ?>
                            </p>
                        </div>
                        <?php if (isLoggedIn()): ?>
                            <div class="card-footer bg-transparent">
                                <!-- Add to Collection -->
                                <form class="add-collection-form mb-2">
                                    <input type="hidden" name="card_id" value="<?= $card['id'] ?>">
                                    <div class="input-group input-group-sm">
                                        <input type="number" name="quantity" class="form-control" value="1" min="1" style="max-width:70px;">
                                        <button class="btn btn-success" type="submit">+ Collection</button>
                                    </div>
                                </form>
                                <!-- Add to Deck -->
                                <?php if (!empty($user_decks)): ?>
                                <form class="add-deck-form mb-2"
                                      data-card-id="<?= htmlspecialchars($card['id']) ?>"
                                      data-card-name="<?= htmlspecialchars($card['name'], ENT_QUOTES) ?>">
                                    <div class="input-group input-group-sm">
                                        <select class="form-select deck-select" name="deck_id">
                                            <option value="">— Pick deck —</option>
                                            <?php foreach ($user_decks as $ud): ?>
                                            <option value="<?= $ud['id'] ?>"><?= htmlspecialchars($ud['name']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button class="btn btn-primary" type="submit">+ Deck</button>
                                    </div>
                                </form>
                                <?php else: ?>
                                <a href="decks.php" class="btn btn-sm btn-outline-primary w-100 mb-2">Create a deck first</a>
                                <?php endif; ?>
                                <!-- Add to Wishlist -->
                                <form class="add-wishlist-form">
                                    <input type="hidden" name="card_id" value="<?= $card['id'] ?>">
                                    <div class="input-group input-group-sm">
                                        <select class="form-select" name="priority">
                                            <option value="1">Low</option>
                                            <option value="2">Medium</option>
                                            <option value="3" selected>High</option>
                                        </select>
                                        <button class="btn btn-warning" type="submit">Wishlist</button>
                                    </div>
                                </form>
                            </div>
                        <?php else: ?>
                            <div class="card-footer bg-transparent">
                                <a href="index.php" class="btn btn-sm btn-outline-secondary w-100">Login to add</a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endwhile; ?>
        </div>
    <?php elseif (!empty($_GET)): ?>
        <div class="alert alert-info">No cards found matching your criteria.</div>
    <?php else: ?>
        <div class="alert alert-secondary">Use the search form above to find cards.</div>
    <?php endif; ?>
</div>

<!-- Card Detail Modal -->
<div class="modal fade" id="cardModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="cardModalTitle" style="color:#c9a227;"></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body d-flex flex-column flex-md-row gap-3">
                <div class="flex-shrink-0">
                    <img id="cardModalImg" src="" alt=""
                         style="width:220px;border-radius:10px;object-fit:contain;background:#0d0d1a;display:block;">
                </div>
                <div class="flex-grow-1 d-flex flex-column" style="min-width:0;">
                    <!-- Tabs -->
                    <ul class="nav nav-tabs nav-tabs-sm mb-3" id="cardModalTabs">
                        <li class="nav-item">
                            <a class="nav-link active" data-bs-toggle="tab" href="#tab-details" style="font-size:0.85rem;">Details</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" data-bs-toggle="tab" href="#tab-prices" id="prices-tab-link" style="font-size:0.85rem;">Prices</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" data-bs-toggle="tab" href="#tab-rulings" id="rulings-tab-link" style="font-size:0.85rem;">Rulings</a>
                        </li>
                    </ul>
                    <div class="tab-content flex-grow-1">
                        <div class="tab-pane fade show active" id="tab-details">
                            <div id="cardModalInfo"></div>
                        </div>
                        <div class="tab-pane fade" id="tab-prices">
                            <div id="cardModalPrices" style="color:#e8e8e8;font-size:0.85rem;">
                                <div class="text-center py-3" style="color:#8899aa;">
                                    <span class="spinner-border spinner-border-sm me-2"></span>Loading prices…
                                </div>
                            </div>
                        </div>
                        <div class="tab-pane fade" id="tab-rulings">
                            <div id="cardModalRulings" style="color:#e8e8e8;font-size:0.85rem;">
                                <div class="text-center py-3" style="color:#8899aa;">
                                    <span class="spinner-border spinner-border-sm me-2"></span>Loading rulings…
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function toggleColorless(cb) {
    var colorBoxes = document.querySelectorAll('.color-checkbox');
    var colorWrappers = document.querySelectorAll('.color-option');
    if (cb.checked) {
        colorBoxes.forEach(function(el) {
            el.checked = false;
            el.disabled = true;
        });
        colorWrappers.forEach(function(el) {
            el.style.opacity = '0.4';
            el.style.pointerEvents = 'none';
        });
    } else {
        colorBoxes.forEach(function(el) {
            el.disabled = false;
        });
        colorWrappers.forEach(function(el) {
            el.style.opacity = '';
            el.style.pointerEvents = '';
        });
    }
}
// Apply on page load if colorless is already checked (e.g. back button / pre-filled)
(function() {
    var cb = document.getElementById('colorless');
    if (cb && cb.checked) toggleColorless(cb);
})();
</script>
<script>
// Strip empty fields before form submits so the URL stays clean
(function () {
    ['search-form', 'filter-form'].forEach(function (id) {
        var form = document.getElementById(id);
        if (!form) return;
        form.addEventListener('submit', function () {
            Array.from(form.elements).forEach(function (el) {
                if (!el.name) return;
                // Keep checkboxes/selects that have a meaningful default
                if (el.type === 'checkbox' && !el.checked) { el.disabled = true; return; }
                if (el.value === '') el.disabled = true;
            });
        });
    });
})();
</script>
<script>
let _currentCardId = null;
let _rulingsCache  = {};
let _pricesCache   = {};

function openCardModal(card) {
    _currentCardId = card.id;
    document.getElementById('cardModalTitle').textContent = card.name;

    const img = document.getElementById('cardModalImg');
    img.src   = card.image_uri || '';
    img.style.display = card.image_uri ? 'block' : 'none';

    const r  = card.rarity || 'common';
    const rl = r.charAt(0).toUpperCase() + r.slice(1);

    // Oracle text — replace mana symbols with styled spans
    const oracleHtml = card.oracle_text
        ? `<div class="mt-2 mb-2 p-2 rounded small" style="background:rgba(255,255,255,0.05);color:#e8e8e8;white-space:pre-wrap;line-height:1.5;">${escMana(card.oracle_text)}</div>`
        : '';

    const ptHtml = (card.power != null)
        ? `<tr><td class="text-muted" style="width:80px;">P/T</td><td><strong>${card.power}/${card.toughness}</strong></td></tr>`
        : (card.loyalty ? `<tr><td class="text-muted">Loyalty</td><td><strong>${card.loyalty}</strong></td></tr>` : '');

    const flavorHtml = card.flavor_text
        ? `<div class="mt-2 p-2 rounded small fst-italic" style="background:rgba(255,255,255,0.03);color:#8899aa;border-left:2px solid rgba(201,162,39,0.3);line-height:1.5;">${escHtml(card.flavor_text)}</div>`
        : '';

    const keywordsArr = (() => {
        if (!card.keywords) return [];
        try { return Array.isArray(card.keywords) ? card.keywords : JSON.parse(card.keywords); }
        catch { return []; }
    })();
    const keywordsHtml = keywordsArr.length
        ? `<div class="mt-2 d-flex flex-wrap gap-1">${keywordsArr.map(k => `<span class="badge" style="background:rgba(167,139,250,0.15);color:#a78bfa;border:1px solid rgba(167,139,250,0.3);font-size:0.7rem;">${escHtml(k)}</span>`).join('')}</div>`
        : '';

    document.getElementById('cardModalInfo').innerHTML = `
        <table class="table table-sm mb-1" style="color:#e8e8e8;font-size:0.85rem;">
            <tr><td class="text-muted" style="width:80px;">Set</td>    <td><strong>${escHtml(card.set_name||'—')}</strong></td></tr>
            <tr><td class="text-muted">Mana</td>   <td><strong>${escHtml(card.mana_cost||'—')}</strong></td></tr>
            <tr><td class="text-muted">CMC</td>    <td><strong>${card.cmc??'—'}</strong></td></tr>
            <tr><td class="text-muted">Type</td>   <td><strong>${escHtml(card.type_line||'—')}</strong></td></tr>
            <tr><td class="text-muted">Rarity</td> <td><strong>${rl}</strong></td></tr>
            ${ptHtml}
            ${card.price_usd != null ? `<tr><td class="text-muted">Price</td><td><strong style="color:#c9a227;">$${parseFloat(card.price_usd).toFixed(2)}</strong>${card.price_usd_foil != null ? ` <span style="color:#8899aa;font-size:0.8em;">/ foil $${parseFloat(card.price_usd_foil).toFixed(2)}</span>` : ''}</td></tr>` : ''}
        </table>
        ${keywordsHtml}
        ${oracleHtml}
        ${flavorHtml}
    `;

    // Reset lazy-loaded tabs
    document.getElementById('cardModalPrices').innerHTML =
        '<div class="text-center py-3" style="color:#8899aa;"><span class="spinner-border spinner-border-sm me-2"></span>Loading prices…</div>';
    document.getElementById('cardModalRulings').innerHTML =
        '<div class="text-center py-3" style="color:#8899aa;"><span class="spinner-border spinner-border-sm me-2"></span>Loading rulings…</div>';

    // Switch back to details tab
    const detailTab = document.querySelector('a[href="#tab-details"]');
    if (detailTab) new bootstrap.Tab(detailTab).show();

    new bootstrap.Modal(document.getElementById('cardModal')).show();
}

function escHtml(str) {
    if (!str) return '';
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

function escMana(text) {
    return text
        .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
        .replace(/\{([^}]+)\}/g, (_, sym) =>
            `<span style="display:inline-block;min-width:1.2em;text-align:center;border-radius:50%;background:rgba(201,162,39,0.2);color:#c9a227;font-size:0.75em;padding:0 2px;font-weight:700;">{${sym}}</span>`
        );
}

// Fetch prices lazily when the prices tab is clicked
document.getElementById('prices-tab-link')?.addEventListener('shown.bs.tab', async () => {
    if (!_currentCardId) return;
    if (_pricesCache[_currentCardId]) { renderSearchPrices(_pricesCache[_currentCardId]); return; }
    try {
        const base = document.querySelector('meta[name="app-base"]')?.content ?? '';
        const res  = await fetch(`${base}/ajax/card_price_history.php?card_id=${encodeURIComponent(_currentCardId)}&days=30`);
        const data = await res.json();
        _pricesCache[_currentCardId] = data;
        renderSearchPrices(data);
    } catch {
        document.getElementById('cardModalPrices').innerHTML = '<p style="color:#f87171;">Could not load price data.</p>';
    }
});

function renderSearchPrices(data) {
    const box = document.getElementById('cardModalPrices');
    const cur = data.current;
    const history = data.history || [];

    let html = '';
    if (cur) {
        const fmt = v => v != null ? '$' + parseFloat(v).toFixed(2) : '<span style="color:#8899aa;">—</span>';
        html += `<table class="table table-sm mb-3" style="color:#e8e8e8;font-size:0.85rem;">
            <tr><td class="text-muted" style="width:110px;">USD</td>      <td><strong style="color:#c9a227;">${fmt(cur.price_usd)}</strong></td></tr>
            <tr><td class="text-muted">USD Foil</td>  <td><strong>${fmt(cur.price_usd_foil)}</strong></td></tr>
            <tr><td class="text-muted">EUR</td>        <td><strong>${fmt(cur.price_eur)}</strong></td></tr>
            <tr><td class="text-muted">EUR Foil</td>  <td><strong>${fmt(cur.price_eur_foil)}</strong></td></tr>
            <tr><td class="text-muted">MTGO Tix</td>  <td><strong>${fmt(cur.price_tix)}</strong></td></tr>
            <tr><td class="text-muted">Updated</td>   <td style="color:#8899aa;font-size:0.8em;">${escHtml(cur.updated_at||'—')}</td></tr>
        </table>`;
    } else {
        html += `<p style="color:#8899aa;">No price data available. Run <strong>Update Prices</strong> from the admin panel.</p>`;
    }

    if (history.length > 1) {
        const prices = history.map(r => r.price_usd != null ? parseFloat(r.price_usd) : null);
        const dates  = history.map(r => r.recorded_date);
        const valid  = prices.filter(p => p !== null);
        const minP   = Math.min(...valid);
        const maxP   = Math.max(...valid);
        const range  = maxP - minP || 1;
        const W = 340, H = 80, pad = 6;

        const pts = prices.map((p, i) => {
            const x = pad + (i / Math.max(prices.length - 1, 1)) * (W - pad * 2);
            const y = p !== null ? pad + ((maxP - p) / range) * (H - pad * 2) : null;
            return { x, y, d: dates[i] };
        }).filter(pt => pt.y !== null);

        if (pts.length > 1) {
            const polyline  = pts.map(pt => `${pt.x.toFixed(1)},${pt.y.toFixed(1)}`).join(' ');
            const areaBottom = H - pad;
            const area = `${pts[0].x.toFixed(1)},${areaBottom} ` +
                         pts.map(pt => `${pt.x.toFixed(1)},${pt.y.toFixed(1)}`).join(' ') +
                         ` ${pts[pts.length-1].x.toFixed(1)},${areaBottom}`;
            const change = valid.length >= 2 ? valid[valid.length-1] - valid[0] : null;
            const changeStr = change !== null
                ? (change >= 0
                    ? `<span style="color:#4ade80;">+$${change.toFixed(2)}</span>`
                    : `<span style="color:#f87171;">-$${Math.abs(change).toFixed(2)}</span>`)
                : '';

            html += `<div class="mb-1" style="color:#8899aa;font-size:0.78rem;">USD price — last ${history.length} days ${changeStr}</div>
            <svg width="${W}" height="${H}" viewBox="0 0 ${W} ${H}"
                 style="display:block;overflow:visible;border-radius:4px;background:rgba(0,0,0,0.2);">
                <polygon points="${area}" fill="rgba(201,162,39,0.12)" />
                <polyline points="${polyline}" fill="none" stroke="#c9a227" stroke-width="1.5" stroke-linejoin="round" />
                <text x="${pts[0].x}" y="${H-1}" font-size="8" fill="#8899aa" text-anchor="middle">${escHtml(dates[0])}</text>
                <text x="${pts[pts.length-1].x}" y="${H-1}" font-size="8" fill="#8899aa" text-anchor="end">${escHtml(dates[dates.length-1])}</text>
            </svg>`;
        }
    }
    box.innerHTML = html || '<p style="color:#8899aa;">No price data.</p>';
}

// Fetch rulings lazily when the rulings tab is clicked
document.getElementById('rulings-tab-link')?.addEventListener('shown.bs.tab', async () => {
    if (!_currentCardId) return;
    if (_rulingsCache[_currentCardId]) {
        renderRulings(_rulingsCache[_currentCardId]);
        return;
    }
    try {
        const res  = await fetch(`https://api.scryfall.com/cards/${_currentCardId}/rulings`);
        const data = await res.json();
        _rulingsCache[_currentCardId] = data.data || [];
        renderRulings(_rulingsCache[_currentCardId]);
    } catch {
        document.getElementById('cardModalRulings').innerHTML =
            '<p style="color:#f87171;">Could not load rulings — check your connection.</p>';
    }
});

function renderRulings(rulings) {
    const box = document.getElementById('cardModalRulings');
    if (!rulings.length) {
        box.innerHTML = '<p style="color:#8899aa;">No rulings for this card.</p>';
        return;
    }
    box.innerHTML = rulings.map(r => `
        <div class="mb-3 pb-3" style="border-bottom:1px solid rgba(255,255,255,0.07);">
            <div class="small mb-1" style="color:#8899aa;">${r.published_at} · <em>${r.source}</em></div>
            <div style="color:#e8e8e8;">${escMana(r.comment)}</div>
        </div>
    `).join('');
}
</script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Toast function
    function showToast(message, type = 'success') {
        const container = document.getElementById('toast-container');
        const toastEl = document.createElement('div');
        toastEl.className = `toast align-items-center text-white bg-${type} border-0`;
        toastEl.setAttribute('role', 'alert');
        toastEl.innerHTML = `
            <div class="d-flex">
                <div class="toast-body">${message}</div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
            </div>
        `;
        container.appendChild(toastEl);
        const toast = new bootstrap.Toast(toastEl, { delay: 3000 });
        toast.show();
        toastEl.addEventListener('hidden.bs.toast', () => toastEl.remove());
    }

    // Add to Collection AJAX
    document.querySelectorAll('.add-collection-form').forEach(form => {
        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            const formData = new FormData(form);
            try {
                const response = await fetch('ajax/add_to_collection.php', {
                    method: 'POST',
                    body: formData
                });
                const result = await response.json();
                if (result.success) {
                    showToast(result.message, 'success');
                } else {
                    showToast(result.error || 'Error', 'danger');
                }
            } catch (err) {
                showToast('Network error', 'danger');
            }
        });
    });

    // Add to Wishlist AJAX
    document.querySelectorAll('.add-wishlist-form').forEach(form => {
        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            const formData = new FormData(form);
            try {
                const response = await fetch('ajax/add_to_wishlist.php', {
                    method: 'POST',
                    body: formData
                });
                const result = await response.json();
                if (result.success) {
                    showToast(result.message, 'success');
                } else {
                    showToast(result.error || 'Error', 'danger');
                }
            } catch (err) {
                showToast('Network error', 'danger');
            }
        });
    });

    // Add to Deck AJAX
    document.querySelectorAll('.add-deck-form').forEach(form => {
        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            const select   = form.querySelector('.deck-select');
            const deckId   = select.value;
            const cardId   = form.dataset.cardId;
            const cardName = form.dataset.cardName;

            if (!deckId) {
                showToast('Please select a deck first.', 'warning');
                return;
            }

            const btn = form.querySelector('button[type="submit"]');
            const orig = btn.textContent;
            btn.disabled = true;
            btn.textContent = '…';

            const fd = new FormData();
            fd.append('card_id', cardId);
            fd.append('deck_id', deckId);
            fd.append('quantity', 1);

            try {
                const res  = await fetch('ajax/add_to_deck.php', { method: 'POST', body: fd });
                const data = await res.json();
                if (data.success) {
                    showToast(
                        `<strong>${data.card_name}</strong> added to <strong>${data.deck_name}</strong>.`,
                        'success'
                    );
                    // Reset deck select back to placeholder
                    select.value = '';
                } else {
                    showToast(data.error || 'Could not add to deck.', 'danger');
                }
            } catch {
                showToast('Network error.', 'danger');
            } finally {
                btn.disabled = false;
                btn.textContent = orig;
            }
        });
    });
});
</script>

<?php
$stmt->close();
$dbc->close();
include __DIR__ . '/includes/footer.php';
?>