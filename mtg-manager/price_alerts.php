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

// Auto-create table
$dbc->query("CREATE TABLE IF NOT EXISTS price_alerts (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    user_id       INT NOT NULL,
    card_id       VARCHAR(36) NOT NULL,
    target_price  DECIMAL(10,2) NOT NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    triggered_at  DATETIME NULL,
    is_active     TINYINT(1) NOT NULL DEFAULT 1,
    INDEX idx_user_active (user_id, is_active),
    UNIQUE KEY uq_user_card (user_id, card_id)
)");

// Handle delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_alert_id'])) {
    requireCsrf();
    $del_id = (int)$_POST['delete_alert_id'];
    $del_stmt = $dbc->prepare("DELETE FROM price_alerts WHERE id = ? AND user_id = ?");
    $del_stmt->bind_param("ii", $del_id, $user_id);
    $del_stmt->execute();
    $del_stmt->close();
    header("Location: price_alerts.php?msg=deleted");
    exit();
}

// Handle add
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['card_id'], $_POST['target_price'])) {
    requireCsrf();
    $card_id      = trim($_POST['card_id']);
    $target_price = round((float)$_POST['target_price'], 2);

    if ($card_id && $target_price > 0) {
        // Verify card exists
        $cv = $dbc->prepare("SELECT id FROM cards WHERE id = ?");
        $cv->bind_param("s", $card_id);
        $cv->execute();
        if ($cv->get_result()->num_rows > 0) {
            $ins = $dbc->prepare(
                "INSERT INTO price_alerts (user_id, card_id, target_price)
                 VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE target_price = VALUES(target_price), is_active = 1, triggered_at = NULL"
            );
            $ins->bind_param("isd", $user_id, $card_id, $target_price);
            $ins->execute();
            $ins->close();
        }
        $cv->close();
    }
    header("Location: price_alerts.php?msg=added");
    exit();
}

$msg = $_GET['msg'] ?? '';

// Fetch all alerts with current price
$alerts_stmt = $dbc->prepare(
    "SELECT pa.id, pa.card_id, pa.target_price, pa.is_active, pa.triggered_at, pa.created_at,
            c.name, c.image_uri, c.type_line, c.rarity,
            cp.price_usd
     FROM price_alerts pa
     JOIN cards c ON c.id = pa.card_id
     LEFT JOIN card_prices cp ON cp.card_id = pa.card_id
     WHERE pa.user_id = ?
     ORDER BY pa.is_active DESC, c.name ASC"
);
$alerts_stmt->bind_param("i", $user_id);
$alerts_stmt->execute();
$alerts = $alerts_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$alerts_stmt->close();
$dbc->close();
?>

<div class="container my-4" style="max-width:860px;">
    <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-3">
        <h1 class="mb-0"><i class="bi bi-bell me-2" style="color:#c9a227;"></i>Price Alerts</h1>
    </div>

    <?php if ($msg === 'added'): ?>
        <div class="alert alert-success alert-dismissible fade show">
            Alert saved. You'll be notified on the dashboard when the price drops.
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php elseif ($msg === 'deleted'): ?>
        <div class="alert alert-warning alert-dismissible fade show">
            Alert removed.
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Add Alert -->
    <div class="card shadow-sm mb-4">
        <div class="card-header" style="background:rgba(201,162,39,0.08);border-bottom:1px solid rgba(201,162,39,0.2);">
            <span style="color:#c9a227;font-weight:600;"><i class="bi bi-plus-circle me-2"></i>Add Price Alert</span>
        </div>
        <div class="card-body">
            <p class="small mb-3" style="color:#8899aa;">
                Search for a card below, then enter the price you want to be alerted at.
                You'll see a notification on your dashboard when the price drops to or below your target.
            </p>
            <div class="mb-3">
                <label class="form-label" style="color:#e8e8e8;">Card Name</label>
                <input type="text" id="alert-card-search" class="form-control"
                       placeholder="Start typing a card name…" autocomplete="off"
                       style="background:#1e1e2e;color:#e8e8e8;border-color:rgba(201,162,39,0.3);">
                <div id="alert-search-results" class="list-group mt-1"
                     style="display:none;max-height:240px;overflow-y:auto;
                            background:#1e1e2e;border:1px solid rgba(201,162,39,0.3);border-radius:6px;box-shadow:0 4px 16px rgba(0,0,0,0.5);"></div>
                <input type="hidden" id="alert-card-id">
                <div id="alert-selected-card" class="mt-2 small" style="color:#4ade80;display:none;"></div>
            </div>
            <form method="post" id="alert-form">
                <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                <input type="hidden" name="card_id" id="alert-form-card-id">
                <div class="mb-3">
                    <label class="form-label" style="color:#e8e8e8;">Alert me when price drops to or below ($)</label>
                    <input type="number" name="target_price" class="form-control" step="0.01" min="0.01"
                           placeholder="e.g. 5.00" style="max-width:200px;">
                </div>
                <button type="submit" class="btn btn-primary" id="alert-submit-btn" disabled>
                    <i class="bi bi-bell-fill me-1"></i>Set Alert
                </button>
            </form>
        </div>
    </div>

    <!-- Alerts List -->
    <?php if (empty($alerts)): ?>
        <div class="alert alert-info mt-4">No price alerts yet. Add one above.</div>
    <?php else: ?>
    <div class="card shadow-sm mt-4">
        <div class="card-header" style="background:rgba(255,255,255,0.03);border-bottom:1px solid rgba(255,255,255,0.08);">
            <span style="color:#e8e8e8;font-weight:600;">Your Alerts</span>
        </div>
        <div class="table-responsive">
        <table class="table table-sm mb-0" style="font-size:0.88rem;">
            <thead>
                <tr style="color:#8899aa;border-bottom:1px solid rgba(255,255,255,0.1);">
                    <th>Card</th>
                    <th class="text-end">Target</th>
                    <th class="text-end">Current</th>
                    <th class="text-center">Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($alerts as $alert):
                $current = $alert['price_usd'] !== null ? (float)$alert['price_usd'] : null;
                $target  = (float)$alert['target_price'];
                $hit     = ($current !== null && $current <= $target);
                $active  = (bool)$alert['is_active'];
            ?>
            <tr style="border-bottom:1px solid rgba(255,255,255,0.05);<?= !$active ? 'opacity:0.5;' : '' ?>">
                <td>
                    <span style="color:#e8e8e8;"><?= htmlspecialchars($alert['name']) ?></span><br>
                    <span class="small" style="color:#8899aa;"><?= htmlspecialchars($alert['type_line']) ?></span>
                </td>
                <td class="text-end fw-bold" style="color:#c9a227;">$<?= number_format($target, 2) ?></td>
                <td class="text-end" style="color:<?= $current === null ? '#8899aa' : ($hit ? '#4ade80' : '#e8e8e8') ?>;">
                    <?= $current !== null ? '$' . number_format($current, 2) : '—' ?>
                </td>
                <td class="text-center">
                    <?php if (!$active && $alert['triggered_at']): ?>
                        <span class="badge" style="background:rgba(74,222,128,0.15);color:#4ade80;border:1px solid rgba(74,222,128,0.3);">
                            Triggered <?= date('M j', strtotime($alert['triggered_at'])) ?>
                        </span>
                    <?php elseif ($hit): ?>
                        <span class="badge" style="background:rgba(74,222,128,0.15);color:#4ade80;border:1px solid rgba(74,222,128,0.3);">
                            <i class="bi bi-bell-fill me-1"></i>At target!
                        </span>
                    <?php else: ?>
                        <span class="badge bg-secondary">Watching</span>
                    <?php endif; ?>
                </td>
                <td class="text-end">
                    <form method="post" class="d-inline">
                        <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                        <input type="hidden" name="delete_alert_id" value="<?= $alert['id'] ?>">
                        <button class="btn btn-sm btn-outline-danger" type="submit"
                                onclick="return confirm('Remove this price alert?')" title="Remove">
                            <i class="bi bi-trash3"></i>
                        </button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
// Card search autocomplete
const searchInput = document.getElementById('alert-card-search');
const resultsBox  = document.getElementById('alert-search-results');
const cardIdField = document.getElementById('alert-card-id');
const selectedDiv = document.getElementById('alert-selected-card');
const formCardId  = document.getElementById('alert-form-card-id');
const submitBtn   = document.getElementById('alert-submit-btn');

let searchTimer;
searchInput.addEventListener('input', function () {
    clearTimeout(searchTimer);
    const q = this.value.trim();
    if (q.length < 2) { resultsBox.style.display = 'none'; return; }
    searchTimer = setTimeout(async () => {
        try {
            const res  = await fetch('ajax/card_autocomplete.php?q=' + encodeURIComponent(q));
            const data = await res.json();
            resultsBox.innerHTML = '';
            if (!data.length) { resultsBox.style.display = 'none'; return; }
            data.forEach(card => {
                const a = document.createElement('a');
                a.href = '#';
                a.className = 'list-group-item list-group-item-action';
                a.style.cssText = 'background:#1e1e2e;color:#e8e8e8;border-color:rgba(255,255,255,0.08);';
                a.addEventListener('mouseover', () => a.style.background = '#2a2a3e');
                a.addEventListener('mouseout',  () => a.style.background = '#1e1e2e');
                a.textContent = card.name + (card.type_line ? ' — ' + card.type_line : '');
                a.addEventListener('click', function(e) {
                    e.preventDefault();
                    cardIdField.value = card.id;
                    formCardId.value  = card.id;
                    searchInput.value = card.name;
                    selectedDiv.textContent = '✓ ' + card.name + (card.price_usd ? ' (current: $' + parseFloat(card.price_usd).toFixed(2) + ')' : '');
                    selectedDiv.style.display = '';
                    resultsBox.style.display = 'none';
                    submitBtn.disabled = false;
                });
                resultsBox.appendChild(a);
            });
            resultsBox.style.display = '';
        } catch (_) {}
    }, 280);
});

document.addEventListener('click', e => {
    if (!resultsBox.contains(e.target) && e.target !== searchInput) {
        resultsBox.style.display = 'none';
    }
});

document.getElementById('alert-form').addEventListener('submit', function(e) {
    if (!formCardId.value) { e.preventDefault(); alert('Please select a card first.'); }
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
