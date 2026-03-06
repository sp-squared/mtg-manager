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

// Handle messages
$message = '';
if (isset($_GET['msg'])) {
    switch ($_GET['msg']) {
        case 'added':   $message = 'Card added to collection.'; break;
        case 'updated': $message = 'Quantity updated.'; break;
        case 'removed': $message = 'Card removed from collection.'; break;
    }
}

// Pagination settings
$results_per_page = 52;
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $results_per_page;

// Build filter conditions (on card fields)
$conditions = ["uc.user_id = ?"];
$params = [$user_id];
$types = "i";

// Name filter — detects Scryfall UUID for exact card ID lookup
if (!empty($_GET['name'])) {
    $name_input = trim($_GET['name']);
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

// Set filter
if (!empty($_GET['set'])) {
    $conditions[] = "c.set_id = ?";
    $params[] = $_GET['set'];
    $types .= 's';
}

// Rarity filter
if (!empty($_GET['rarity'])) {
    $conditions[] = "c.rarity = ?";
    $params[] = $_GET['rarity'];
    $types .= 's';
}

// Type filter
if (!empty($_GET['type'])) {
    $conditions[] = "c.type_line LIKE ?";
    $params[] = '%' . $_GET['type'] . '%';
    $types .= 's';
}

// CMC range
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

// Keyword / ability search
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
    $conditions[] = "NOT EXISTS (SELECT 1 FROM card_colors cc WHERE cc.card_id = c.id)";
} elseif (!empty($sel_colors)) {
    if ($color_mode === 'exactly') {
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
        foreach ($sel_colors as $col) {
            $conditions[] = "EXISTS (SELECT 1 FROM card_colors cc WHERE cc.card_id = c.id AND cc.color_id = ?)";
            $params[] = $col; $types .= 's';
        }
    } else {
        $placeholders = implode(',', array_fill(0, count($sel_colors), '?'));
        $conditions[] = "EXISTS (SELECT 1 FROM card_colors cc WHERE cc.card_id = c.id AND cc.color_id IN ($placeholders))";
        foreach ($sel_colors as $col) { $params[] = $col; $types .= 's'; }
    }
}

$where = "WHERE " . implode(' AND ', $conditions);

// Sort
$sort_options = [
    'added'       => 'uc.added_at DESC, c.name ASC',
    'name'        => 'c.name ASC',
    'cmc_asc'     => 'c.cmc ASC, c.name ASC',
    'cmc_desc'    => 'c.cmc DESC, c.name ASC',
    'rarity'      => "FIELD(c.rarity,'mythic','rare','uncommon','common'), c.name ASC",
    'set'         => 's.name ASC, c.name ASC',
    'qty_desc'    => 'uc.quantity DESC, c.name ASC',
    'newest'      => 'c.imported_at DESC, c.name ASC',
    'price_asc'   => 'cp.price_usd IS NULL ASC, cp.price_usd ASC, c.name ASC',
    'price_desc'  => 'cp.price_usd IS NULL ASC, cp.price_usd DESC, c.name ASC',
];
$sort_key   = $_GET['sort'] ?? 'added';
$order_by   = $sort_options[$sort_key] ?? $sort_options['name'];

// Strip empty params from pagination URLs
function clean_search_params(array $params, array $overrides = []): string {
    $merged = array_merge($params, $overrides);
    $filtered = array_filter($merged, fn($v) => $v !== '' && $v !== null);
    return http_build_query($filtered);
}

// Count total results (filtered)
$count_sql = "SELECT COUNT(*) as total
              FROM user_collection uc
              JOIN cards c ON uc.card_id = c.id
              $where";
$count_stmt = $dbc->prepare($count_sql);
if (!empty($params)) {
    $count_stmt->bind_param($types, ...$params);
}
$count_stmt->execute();
$total_results = $count_stmt->get_result()->fetch_assoc()['total'];
$total_pages = ceil($total_results / $results_per_page);

// Collection total value (for banner)
$val_sql = "SELECT SUM(COALESCE(cp.price_usd, 0) * uc.quantity) as total_value,
                   COUNT(DISTINCT CASE WHEN cp.price_usd IS NOT NULL THEN uc.card_id END) as priced_cards
            FROM user_collection uc
            JOIN cards c ON uc.card_id = c.id
            LEFT JOIN card_prices cp ON cp.card_id = uc.card_id
            $where";
$val_stmt = $dbc->prepare($val_sql);
if (!empty($params)) {
    $val_stmt->bind_param($types, ...$params);
}
$val_stmt->execute();
$val_row = $val_stmt->get_result()->fetch_assoc();
$collection_value   = (float)($val_row['total_value']   ?? 0);
$val_priced_cards   = (int)($val_row['priced_cards'] ?? 0);

// Fetch cards for current page
$sql = "SELECT c.id, c.name, c.mana_cost, c.cmc, c.type_line, c.oracle_text, c.rarity,
               c.power, c.toughness, c.image_uri, c.flavor_text, c.keywords,
               s.name as set_name, uc.quantity,
               cp.price_usd, cp.price_usd_foil, cp.price_eur, cp.updated_at as price_updated_at
        FROM user_collection uc
        JOIN cards c ON uc.card_id = c.id
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
$result = $stmt->get_result();
?>

<div class="container my-4">
    <h1 class="text-center mb-4">My Collection</h1>

    <?php if ($message): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <?= htmlspecialchars($message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="mb-3 d-flex align-items-center gap-3 flex-wrap">
        <a href="search.php" class="btn btn-primary">🔍 Add More Cards</a>
        <?php if ($collection_value > 0): ?>
        <div class="d-flex align-items-center gap-2 px-3 py-2 rounded"
             style="background:rgba(201,162,39,0.1);border:1px solid rgba(201,162,39,0.25);">
            <i class="bi bi-currency-dollar" style="color:#c9a227;"></i>
            <span style="color:#e8e8e8;font-size:0.9rem;">
                Collection value:
                <strong style="color:#c9a227;"><?= '$' . number_format($collection_value, 2) ?></strong>
                <span style="color:#8899aa;font-size:0.8rem;">
                    (<?= $val_priced_cards ?> of <?= $total_results ?> cards priced)
                </span>
            </span>
        </div>
        <?php endif; ?>
    </div>

    <!-- Filter Form -->
    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <form method="get" action="collection.php" id="filter-form">
                <div class="row g-3">
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

                    <div class="col-md-3">
                        <label class="form-label">Keyword / Ability</label>
                        <input type="text" class="form-control" name="keyword" placeholder="e.g. Flying"
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

                    <div class="col-12">
                        <label class="form-label d-block">Color Identity</label>
                        <div class="d-flex flex-wrap align-items-center gap-3">
                            <?php
                            $color_options  = ['W'=>'White','U'=>'Blue','B'=>'Black','R'=>'Red','G'=>'Green'];
                            $selected_colors = $_GET['colors'] ?? [];
                            $pip_colors = ['W'=>'#f9f1d8','U'=>'#1a6bbd','B'=>'#2a2a2a','R'=>'#c0392b','G'=>'#1a7a3c'];
                            $pip_text   = ['W'=>'#1a1a1a','U'=>'#fff','B'=>'#e8e8e8','R'=>'#fff','G'=>'#fff'];
                            foreach ($color_options as $code => $label_name):
                                $checked = in_array($code, $selected_colors) ? 'checked' : '';
                            ?>
                            <div class="form-check form-check-inline m-0 color-option">
                                <input class="form-check-input color-checkbox" type="checkbox" name="colors[]"
                                       value="<?= $code ?>" id="col_<?= $code ?>" <?= $checked ?>>
                                <label class="form-check-label d-flex align-items-center gap-1" for="col_<?= $code ?>">
                                    <span style="display:inline-flex;align-items:center;justify-content:center;
                                                 width:22px;height:22px;border-radius:50%;font-size:0.7rem;font-weight:700;
                                                 background:<?= $pip_colors[$code] ?>;color:<?= $pip_text[$code] ?>;
                                                 border:1px solid rgba(255,255,255,0.2);"><?= $code ?></span>
                                    <span style="font-size:0.85rem;"><?= $label_name ?></span>
                                </label>
                            </div>
                            <?php endforeach; ?>
                            <div class="form-check form-check-inline m-0">
                                <input class="form-check-input" type="checkbox" name="colorless" value="1" onchange="toggleColorless(this)"
                                       id="col_colorless" <?= !empty($_GET['colorless']) ? 'checked' : '' ?>>
                                <label class="form-check-label small" for="col_colorless">Colorless only</label>
                            </div>
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
                    <button type="submit" class="btn btn-primary"><i class="bi bi-search me-1"></i>Filter</button>
                    <a href="collection.php" class="btn btn-outline-secondary btn-sm">Clear</a>
                    <div class="ms-auto d-flex align-items-center gap-2">
                        <label class="form-label mb-0 text-nowrap small">Sort by</label>
                        <select class="form-select form-select-sm" name="sort" style="width:auto;" onchange="this.form.submit()">
                            <option value="added"    <?= ($_GET['sort']??'added')==='added'   ?'selected':'' ?>>Recently Added</option>
                            <option value="name"     <?= ($_GET['sort']??'added')==='name'    ?'selected':'' ?>>Name A–Z</option>
                            <option value="cmc_asc"  <?= ($_GET['sort']??'')==='cmc_asc'  ?'selected':'' ?>>CMC ↑</option>
                            <option value="cmc_desc" <?= ($_GET['sort']??'')==='cmc_desc' ?'selected':'' ?>>CMC ↓</option>
                            <option value="rarity"   <?= ($_GET['sort']??'')==='rarity'   ?'selected':'' ?>>Rarity</option>
                            <option value="set"      <?= ($_GET['sort']??'')==='set'      ?'selected':'' ?>>Set</option>
                            <option value="qty_desc" <?= ($_GET['sort']??'')==='qty_desc' ?'selected':'' ?>>Qty (most)</option>
                            <option value="newest"   <?= ($_GET['sort']??'')==='newest'   ?'selected':'' ?>>Newest Import</option>
                            <option value="price_asc"  <?= ($_GET['sort']??'')==='price_asc'  ?'selected':'' ?>>Price ↑</option>
                            <option value="price_desc" <?= ($_GET['sort']??'')==='price_desc' ?'selected':'' ?>>Price ↓</option>
                        </select>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <?php if ($result->num_rows > 0): ?>
        <div class="d-flex justify-content-between align-items-center mb-3">
            <p class="mb-0">Found <strong><?= $total_results ?></strong> cards in your collection. Page <?= $page ?> of <?= $total_pages ?>.</p>
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
            <?php while ($row = $result->fetch_assoc()):
                // Format power/toughness
                $pt = '—';
                if ($row['power'] !== null || $row['toughness'] !== null) {
                    $pt = ($row['power'] ?? '?') . '/' . ($row['toughness'] ?? '?');
                }
            ?>
                <div class="col">
                    <div class="card h-100 shadow-sm rarity-<?= htmlspecialchars($row['rarity'] ?? 'common') ?>">
                        <?php if ($row['image_uri']): ?>
                            <img src="<?= $row['image_uri'] ?>" class="card-img-top card-img-clickable"
                                 alt="<?= htmlspecialchars($row['name']) ?>"
                                 style="height: 200px; object-fit: contain; background: #0d0d1a; cursor:pointer;"
                                 onclick="openCardModal(<?= htmlspecialchars(json_encode($row)) ?>)">
                        <?php else: ?>
                            <div class="card-img-top d-flex align-items-center justify-content-center bg-light" style="height: 200px;">
                                <span class="text-muted">No Image</span>
                            </div>
                        <?php endif; ?>
                        <div class="card-body">
                            <h5 class="card-title"><?php
    $r = $row['rarity'] ?? 'common';
    $badge_label = ucfirst($r);
?>
<?= htmlspecialchars($row['name']) ?>
                                <span class="badge badge-rarity-<?= $r ?> float-end small"><?= $badge_label ?></span>
                            </h5>
                            <p class="card-text small">
                                <strong>Set:</strong> <?= htmlspecialchars($row['set_name']) ?><br>
                                <strong>Mana:</strong> <?= htmlspecialchars($row['mana_cost'] ?? '—') ?><br>
                                <strong>CMC:</strong> <?= $row['cmc'] ?><br>
                                <strong>Type:</strong> <?= htmlspecialchars($row['type_line']) ?><br>
                                <strong>P/T:</strong> <?= $pt ?><br>
                                <strong>Rarity:</strong> <?= htmlspecialchars($row['rarity']) ?><br>
                                <strong>Owned:</strong> <?= $row['quantity'] ?>
                                <?php if ($row['price_usd'] !== null): ?>
                                <br><strong>Price:</strong>
                                <span style="color:#c9a227;">$<?= number_format((float)$row['price_usd'], 2) ?></span>
                                <?php if ($row['price_usd_foil'] !== null): ?>
                                <span style="color:#8899aa;font-size:0.8em;"> / foil $<?= number_format((float)$row['price_usd_foil'], 2) ?></span>
                                <?php endif; ?>
                                <?php endif; ?>
                            </p>
                        </div>
                        <div class="card-footer bg-transparent">
                            <!-- Update quantity form -->
                            <form action="actions/update_collection.php" method="post" class="mb-2">
                                <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                                <input type="hidden" name="card_id" value="<?= $row['id'] ?>">
                                <div class="input-group input-group-sm">
                                    <input type="number" name="quantity" class="form-control" value="<?= $row['quantity'] ?>" min="0">
                                    <button class="btn btn-success" type="submit">Update</button>
                                </div>
                            </form>
                            <!-- Remove button -->
                            <form action="actions/remove_from_collection.php" method="post" onsubmit="return confirm('Remove this card from your collection?');">
                                <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                                <input type="hidden" name="card_id" value="<?= $row['id'] ?>">
                                <button class="btn btn-danger w-100" type="submit">Remove</button>
                            </form>
                        </div>
                    </div>
                </div>
            <?php endwhile; ?>
        </div>
    <?php elseif (!empty($_GET)): ?>
        <div class="alert alert-info">No cards in your collection match your filters.</div>
    <?php else: ?>
        <div class="alert alert-info">Your collection is empty. <a href="search.php" class="alert-link">Start adding cards!</a></div>
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
                    <ul class="nav nav-tabs mb-3" id="colCardTabs">
                        <li class="nav-item">
                            <a class="nav-link active" data-bs-toggle="tab" href="#col-tab-details" style="font-size:0.85rem;">Details</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" data-bs-toggle="tab" href="#col-tab-prices" id="col-prices-tab" style="font-size:0.85rem;">Prices</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" data-bs-toggle="tab" href="#col-tab-rulings" id="col-rulings-tab" style="font-size:0.85rem;">Rulings</a>
                        </li>
                    </ul>
                    <div class="tab-content flex-grow-1">
                        <div class="tab-pane fade show active" id="col-tab-details">
                            <div id="cardModalInfo"></div>
                        </div>
                        <div class="tab-pane fade" id="col-tab-prices">
                            <div id="cardModalPrices" style="color:#e8e8e8;font-size:0.85rem;">
                                <div class="text-center py-3" style="color:#8899aa;">
                                    <span class="spinner-border spinner-border-sm me-2"></span>Loading prices…
                                </div>
                            </div>
                        </div>
                        <div class="tab-pane fade" id="col-tab-rulings">
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
    var cb = document.getElementById('col_colorless');
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
let _colCardId = null;
let _colRulingsCache = {};
let _colPricesCache  = {};

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
    _colCardId = card.id;
    if (card.id) {
        const fd = new FormData();
        fd.append('card_id', card.id);
        fetch('ajax/record_view.php', { method: 'POST', body: fd }).catch(() => {});
    }
    document.getElementById('cardModalTitle').textContent = card.name;
    const img = document.getElementById('cardModalImg');
    img.src = card.image_uri || '';
    img.style.display = card.image_uri ? 'block' : 'none';
    const r = card.rarity || 'common';
    const rl = r.charAt(0).toUpperCase()+r.slice(1);

    const oracleHtml = card.oracle_text
        ? `<div class="mt-2 mb-1 p-2 rounded small" style="background:rgba(255,255,255,0.05);color:#e8e8e8;white-space:pre-wrap;line-height:1.5;">${escMana(card.oracle_text)}</div>`
        : '';
    const flavorHtml = card.flavor_text
        ? `<div class="mt-1 p-2 rounded small fst-italic" style="background:rgba(255,255,255,0.03);color:#8899aa;border-left:2px solid rgba(201,162,39,0.3);line-height:1.5;">${escHtml(card.flavor_text)}</div>`
        : '';
    const keywordsArr = (() => {
        if (!card.keywords) return [];
        try { return Array.isArray(card.keywords) ? card.keywords : JSON.parse(card.keywords); }
        catch { return []; }
    })();
    const keywordsHtml = keywordsArr.length
        ? `<div class="mb-2 d-flex flex-wrap gap-1">${keywordsArr.map(k => `<span class="badge" style="background:rgba(167,139,250,0.15);color:#a78bfa;border:1px solid rgba(167,139,250,0.3);font-size:0.7rem;">${escHtml(k)}</span>`).join('')}</div>`
        : '';

    document.getElementById('cardModalInfo').innerHTML = `
        <table class="table table-sm mb-1" style="color:#e8e8e8;font-size:0.85rem;">
            <tr><td class="text-muted" style="width:80px;">Set</td>   <td><strong>${escHtml(card.set_name||'—')}</strong></td></tr>
            <tr><td class="text-muted">Mana</td>  <td><strong>${escHtml(card.mana_cost||'—')}</strong></td></tr>
            <tr><td class="text-muted">CMC</td>   <td><strong>${card.cmc??'—'}</strong></td></tr>
            <tr><td class="text-muted">Type</td>  <td><strong>${escHtml(card.type_line||'—')}</strong></td></tr>
            <tr><td class="text-muted">Rarity</td><td><strong>${rl}</strong></td></tr>
            ${card.power!=null?`<tr><td class="text-muted">P/T</td><td><strong>${card.power}/${card.toughness}</strong></td></tr>`:''}
            <tr><td class="text-muted">Owned</td> <td><strong>${card.quantity}</strong></td></tr>
            ${card.price_usd != null ? `<tr><td class="text-muted">Price</td><td><strong style="color:#c9a227;">$${parseFloat(card.price_usd).toFixed(2)}</strong>${card.price_usd_foil != null ? ` <span style="color:#8899aa;font-size:0.8em;">/ foil $${parseFloat(card.price_usd_foil).toFixed(2)}</span>` : ''}</td></tr>` : ''}
        </table>
        ${keywordsHtml}${oracleHtml}${flavorHtml}
    `;

    // Reset prices + rulings tabs
    document.getElementById('cardModalPrices').innerHTML =
        '<div class="text-center py-3" style="color:#8899aa;"><span class="spinner-border spinner-border-sm me-2"></span>Loading prices…</div>';
    document.getElementById('cardModalRulings').innerHTML =
        '<div class="text-center py-3" style="color:#8899aa;"><span class="spinner-border spinner-border-sm me-2"></span>Loading rulings…</div>';
    const detailTab = document.querySelector('a[href="#col-tab-details"]');
    if (detailTab) new bootstrap.Tab(detailTab).show();
    new bootstrap.Modal(document.getElementById('cardModal')).show();
}

document.getElementById('col-prices-tab')?.addEventListener('shown.bs.tab', async () => {
    if (!_colCardId) return;
    if (_colPricesCache[_colCardId]) { renderColPrices(_colPricesCache[_colCardId]); return; }
    try {
        const base = document.querySelector('meta[name="app-base"]')?.content ?? '';
        const res  = await fetch(`${base}/ajax/card_price_history.php?card_id=${encodeURIComponent(_colCardId)}&days=30`);
        const data = await res.json();
        _colPricesCache[_colCardId] = data;
        renderColPrices(data);
    } catch {
        document.getElementById('cardModalPrices').innerHTML = '<p style="color:#f87171;">Could not load price data.</p>';
    }
});

function renderColPrices(data) {
    const box = document.getElementById('cardModalPrices');
    const cur = data.current;
    const history = data.history || [];

    let html = '';

    // Current prices table
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
        html += `<p style="color:#8899aa;" class="mb-3">No price data available. Run <strong>Update Prices</strong> from the admin panel.</p>`;
    }

    // Price history chart (last 30 days)
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
            return { x, y, p, d: dates[i] };
        }).filter(pt => pt.y !== null);

        if (pts.length > 1) {
            const polyline = pts.map(pt => `${pt.x.toFixed(1)},${pt.y.toFixed(1)}`).join(' ');
            const areaBottom = H - pad;
            const area = `${pts[0].x.toFixed(1)},${areaBottom} ` +
                         pts.map(pt => `${pt.x.toFixed(1)},${pt.y.toFixed(1)}`).join(' ') +
                         ` ${pts[pts.length-1].x.toFixed(1)},${areaBottom}`;

            const firstDate = dates[0];
            const lastDate  = dates[dates.length - 1];
            const change    = valid.length >= 2 ? valid[valid.length-1] - valid[0] : null;
            const changeStr = change !== null
                ? (change >= 0 ? `<span style="color:#4ade80;">+$${change.toFixed(2)}</span>` : `<span style="color:#f87171;">-$${Math.abs(change).toFixed(2)}</span>`)
                : '';

            html += `<div class="mb-1" style="color:#8899aa;font-size:0.78rem;">
                USD price — last ${history.length} days
                ${changeStr ? `&nbsp;${changeStr}` : ''}
            </div>
            <svg width="${W}" height="${H}" viewBox="0 0 ${W} ${H}"
                 style="display:block;overflow:visible;border-radius:4px;background:rgba(0,0,0,0.2);">
                <polygon points="${area}" fill="rgba(201,162,39,0.12)" />
                <polyline points="${polyline}" fill="none" stroke="#c9a227" stroke-width="1.5" stroke-linejoin="round" />
                <text x="${pts[0].x}" y="${H-1}" font-size="8" fill="#8899aa" text-anchor="middle">${escHtml(firstDate)}</text>
                <text x="${pts[pts.length-1].x}" y="${H-1}" font-size="8" fill="#8899aa" text-anchor="end">${escHtml(lastDate)}</text>
            </svg>`;
        }
    } else if (history.length === 0 && cur) {
        html += `<p style="color:#8899aa;font-size:0.8rem;">No history yet — price trends appear after multiple update runs.</p>`;
    }

    box.innerHTML = html || '<p style="color:#8899aa;">No price data.</p>';
}

document.getElementById('col-rulings-tab')?.addEventListener('shown.bs.tab', async () => {
    if (!_colCardId) return;
    if (_colRulingsCache[_colCardId]) { renderColRulings(_colRulingsCache[_colCardId]); return; }
    try {
        const res = await fetch(`https://api.scryfall.com/cards/${_colCardId}/rulings`);
        const data = await res.json();
        _colRulingsCache[_colCardId] = data.data || [];
        renderColRulings(_colRulingsCache[_colCardId]);
    } catch {
        document.getElementById('cardModalRulings').innerHTML = '<p style="color:#f87171;">Could not load rulings.</p>';
    }
});

function renderColRulings(rulings) {
    const box = document.getElementById('cardModalRulings');
    if (!rulings.length) { box.innerHTML = '<p style="color:#8899aa;">No rulings for this card.</p>'; return; }
    box.innerHTML = rulings.map(r => `
        <div class="mb-3 pb-3" style="border-bottom:1px solid rgba(255,255,255,0.07);">
            <div class="small mb-1" style="color:#8899aa;">${r.published_at} · <em>${r.source}</em></div>
            <div style="color:#e8e8e8;">${escMana(r.comment)}</div>
        </div>
    `).join('');
}
</script>

<?php
$stmt->close();
$dbc->close();
include __DIR__ . '/includes/footer.php';
?>