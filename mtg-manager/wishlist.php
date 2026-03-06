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

// Sort
$valid_sorts = ['priority', 'price_asc', 'price_desc'];
$sort = isset($_GET['sort']) && in_array($_GET['sort'], $valid_sorts) ? $_GET['sort'] : 'priority';
$sort_orders = [
    'priority'   => 'w.priority DESC, c.name ASC',
    'price_asc'  => 'cp.price_usd IS NULL ASC, cp.price_usd ASC, c.name ASC',
    'price_desc' => 'cp.price_usd IS NULL ASC, cp.price_usd DESC, c.name ASC',
];
$sort_order = $sort_orders[$sort];

// Handle messages
$message = '';
if (isset($_GET['msg'])) {
    switch ($_GET['msg']) {
        case 'added':   $message = 'Card added to wishlist.'; break;
        case 'updated': $message = 'Priority updated.'; break;
        case 'removed': $message = 'Card removed from wishlist.'; break;
    }
}
?>

<div class="container my-4">
    <h1 class="text-center mb-4">My Wishlist</h1>

    <?php if ($message): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <?= htmlspecialchars($message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="mb-3 d-flex align-items-center gap-3 flex-wrap">
        <a href="search.php" class="btn btn-primary">🔍 Search for Cards to Add</a>
        <form method="get" class="d-flex align-items-center gap-2 ms-auto">
            <label class="mb-0 small" style="color:#8899aa;white-space:nowrap;">Sort by:</label>
            <select name="sort" class="form-select form-select-sm" style="width:auto;" onchange="this.form.submit()">
                <option value="priority"  <?= $sort === 'priority'  ? 'selected' : '' ?>>Priority</option>
                <option value="price_asc" <?= $sort === 'price_asc' ? 'selected' : '' ?>>Price ↑</option>
                <option value="price_desc"<?= $sort === 'price_desc'? 'selected' : '' ?>>Price ↓</option>
            </select>
        </form>
        <?php if (isset($wish_value) && $wish_value !== null): ?>
        <div class="d-flex align-items-center gap-2 px-3 py-2 rounded"
             style="background:rgba(201,162,39,0.1);border:1px solid rgba(201,162,39,0.25);">
            <i class="bi bi-currency-dollar" style="color:#c9a227;"></i>
            <span style="color:#e8e8e8;font-size:0.9rem;">
                Wishlist value:
                <strong style="color:#c9a227;"><?= '$' . number_format($wish_value, 2) ?></strong>
                <span style="color:#8899aa;font-size:0.8rem;">
                    (<?= $wish_priced_count ?> of <?= $total_results ?> cards priced)
                </span>
            </span>
        </div>
        <?php endif; ?>
    </div>

    <?php
    // Pagination
    $results_per_page = 52;
    $page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
    $offset = ($page - 1) * $results_per_page;

    // Count total
    $count_stmt = $dbc->prepare("SELECT COUNT(*) as total FROM wishlist WHERE user_id = ?");
    $count_stmt->bind_param("i", $user_id);
    $count_stmt->execute();
    $total_results = $count_stmt->get_result()->fetch_assoc()['total'];
    $total_pages = ceil($total_results / $results_per_page);
    $count_stmt->close();

    // Wishlist total cost
    $wish_value        = null;
    $wish_priced_count = 0;
    $wv_check = $dbc->query("SHOW TABLES LIKE 'card_prices'");
    if ($wv_check && $wv_check->num_rows > 0) {
        $wv_stmt = $dbc->prepare(
            "SELECT SUM(cp.price_usd) as total_value, COUNT(cp.card_id) as priced
             FROM wishlist w
             JOIN card_prices cp ON cp.card_id = w.card_id
             WHERE w.user_id = ?"
        );
        $wv_stmt->bind_param("i", $user_id);
        $wv_stmt->execute();
        $wv_row = $wv_stmt->get_result()->fetch_assoc();
        $wv_stmt->close();
        if ($wv_row['total_value'] !== null) {
            $wish_value        = (float)$wv_row['total_value'];
            $wish_priced_count = (int)$wv_row['priced'];
        }
    }

    $query = "SELECT w.card_id, w.priority, c.name, c.mana_cost, c.type_line, c.image_uri, c.rarity,
                     cp.price_usd, cp.price_usd_foil
              FROM wishlist w
              JOIN cards c ON w.card_id = c.id
              LEFT JOIN card_prices cp ON cp.card_id = w.card_id
              WHERE w.user_id = ?
              ORDER BY {$sort_order}
              LIMIT ? OFFSET ?";
    $stmt = $dbc->prepare($query);
    $stmt->bind_param("iii", $user_id, $results_per_page, $offset);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($total_results == 0): ?>
        <div class="alert alert-info">Your wishlist is empty. Start adding cards you want!</div>
    <?php else: ?>
        <div class="d-flex justify-content-between align-items-center mb-3">
            <p class="mb-0">Showing <strong><?= $total_results ?></strong> wishlist cards. Page <?= $page ?> of <?= $total_pages ?>.</p>
            <?php if ($total_pages > 1): ?>
            <nav aria-label="Page navigation">
                <ul class="pagination pagination-sm mb-0">
                    <?php if ($page > 1): ?>
                        <li class="page-item"><a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page-1])) ?>">Previous</a></li>
                    <?php endif; ?>
                    <?php for ($i = max(1, $page-5); $i <= min($total_pages, $page+5); $i++): ?>
                        <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                            <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>"><?= $i ?></a>
                        </li>
                    <?php endfor; ?>
                    <?php if ($page < $total_pages): ?>
                        <li class="page-item"><a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page+1])) ?>">Next</a></li>
                    <?php endif; ?>
                </ul>
            </nav>
            <?php endif; ?>
        </div>
        <div id="wishlist-grid" class="row row-cols-1 row-cols-sm-2 row-cols-md-3 row-cols-lg-4 row-cols-xl-5 g-4">
            <?php while ($row = $result->fetch_assoc()): ?>
                <div class="col">
                    <div class="card h-100 shadow-sm rarity-<?= htmlspecialchars($row['rarity'] ?? 'common') ?>">
                        <?php if ($row['image_uri']): ?>
                            <img src="<?= $row['image_uri'] ?>" class="card-img-top" alt="<?= htmlspecialchars($row['name']) ?>" style="height: 200px; object-fit: contain; background: #f8f9fa;">
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
                                <strong>Mana:</strong> <?= htmlspecialchars($row['mana_cost'] ?? '—') ?><br>
                                <strong>Type:</strong> <?= htmlspecialchars($row['type_line']) ?><br>
                                <strong>Priority:</strong>
                                <span class="priority-<?= $row['priority'] == 3 ? 'high' : ($row['priority'] == 2 ? 'medium' : 'low') ?>">
                                    <?= getPriorityLabel($row['priority']) ?>
                                </span>
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
                            <!-- Update priority form -->
                            <form class="priority-form mb-2"
                                  data-card-id="<?= $row['card_id'] ?>"
                                  data-card-name="<?= htmlspecialchars($row['name']) ?>"
                                  data-current-priority="<?= $row['priority'] ?>">
                                <input type="hidden" name="card_id" value="<?= $row['card_id'] ?>">
                                <input type="hidden" name="ajax" value="1">
                                <div class="input-group input-group-sm">
                                    <select class="form-select" name="priority">
                                        <option value="1" <?= $row['priority'] == 1 ? 'selected' : '' ?>>Low</option>
                                        <option value="2" <?= $row['priority'] == 2 ? 'selected' : '' ?>>Medium</option>
                                        <option value="3" <?= $row['priority'] == 3 ? 'selected' : '' ?>>High</option>
                                    </select>
                                    <button class="btn btn-success" type="submit">Update</button>
                                </div>
                            </form>
                            <!-- Remove button -->
                            <form class="remove-wishlist-form">
                                <input type="hidden" name="card_id" value="<?= $row['card_id'] ?>">
                                <button class="btn btn-danger w-100" type="submit">Remove</button>
                            </form>
                        </div>
                    </div>
                </div>
            <?php endwhile; ?>
        </div>
    <?php endif; ?>
    <?php $stmt->close(); $dbc->close(); ?>
</div>

<script>
const PRIORITY_LABELS = { 1: 'Low', 2: 'Medium', 3: 'High' };

function showToast(message, type = 'success') {
    let container = document.getElementById('toast-container');
    if (!container) {
        container = document.createElement('div');
        container.id = 'toast-container';
        container.className = 'toast-container position-fixed top-0 end-0 p-3';
        container.style.zIndex = '1100';
        document.body.appendChild(container);
    }
    const el = document.createElement('div');
    el.className = `toast align-items-center text-white bg-${type} border-0`;
    el.setAttribute('role', 'alert');
    el.innerHTML = `<div class="d-flex">
        <div class="toast-body">${message}</div>
        <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
    </div>`;
    container.appendChild(el);
    const t = new bootstrap.Toast(el, { delay: 3500 });
    t.show();
    el.addEventListener('hidden.bs.toast', () => el.remove());
}

async function refreshWishlistGrid(currentPage) {
    const fd = new FormData();
    fd.append('page', currentPage);
    fd.append('sort', new URLSearchParams(window.location.search).get('sort') || 'priority');
    const res  = await fetch('ajax/wishlist_partial.php', { method: 'POST', body: fd });
    const data = await res.json();
    if (data.error) { showToast(data.error, 'danger'); return; }

    const grid = document.getElementById('wishlist-grid');
    const scrollY = window.scrollY;        // save scroll before swap

    grid.innerHTML = data.grid_html;

    window.scrollTo({ top: scrollY });     // restore immediately — no jump

    attachWishlistListeners();
}

function attachWishlistListeners() {
    // Priority update
    document.querySelectorAll('.priority-form').forEach(form => {
        form.addEventListener('submit', async function (e) {
            e.preventDefault();

            const oldPri  = parseInt(this.dataset.currentPriority);
            const newPri  = parseInt(this.querySelector('select[name="priority"]').value);
            const name    = this.dataset.cardName || 'Card';
            if (newPri === oldPri) return;

            const fd = new FormData(this);
            try {
                await fetch('ajax/update_wishlist.php', { method: 'POST', body: fd });
            } catch (_) { showToast('Network error', 'danger'); return; }

            const direction = newPri > oldPri ? '⬆️' : '⬇️';
            const msg = `${direction} <strong>${name}</strong> re-ordered: `
                      + `${PRIORITY_LABELS[oldPri]} → ${PRIORITY_LABELS[newPri]}`;
            showToast(msg, 'success');

            const currentPage = new URLSearchParams(window.location.search).get('page') || 1;
            await refreshWishlistGrid(currentPage);
        });
    });

    // Remove from wishlist
    document.querySelectorAll('.remove-wishlist-form').forEach(form => {
        form.addEventListener('submit', async function (e) {
            e.preventDefault();
            if (!confirm('Remove from wishlist?')) return;
            const fd = new FormData(this);
            fd.append('ajax', '1');
            try {
                await fetch('ajax/remove_from_wishlist.php', { method: 'POST', body: fd });
            } catch (_) { showToast('Network error', 'danger'); return; }
            showToast('Card removed from wishlist', 'danger');
            const currentPage = new URLSearchParams(window.location.search).get('page') || 1;
            await refreshWishlistGrid(currentPage);
        });
    });
}

document.addEventListener('DOMContentLoaded', attachWishlistListeners);
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>