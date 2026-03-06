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

// If a code was submitted, look it up for preview
$code    = strtoupper(trim($_GET['code'] ?? ''));
$export  = null;
$cards   = [];
$error   = '';

if ($code) {
    $s = $dbc->prepare(
        "SELECT e.id, e.export_code, e.deck_name, e.description, e.card_data,
                e.created_at, e.expires_at, e.import_count, p.username as owner
         FROM deck_exports e
         JOIN player p ON p.id = e.owner_id
         WHERE e.export_code = ?"
    );
    $s->bind_param("s", $code);
    $s->execute();
    $export = $s->get_result()->fetch_assoc();
    $s->close();

    if (!$export) {
        $error = 'No deck found with that code. Check the code and try again.';
    } elseif ($export['expires_at'] && strtotime($export['expires_at']) < time()) {
        $error = 'This export code has expired.';
        $export = null;
    } else {
        $cards = json_decode($export['card_data'], true);

        // Tally main / side totals
        $main_total = array_sum(array_column(
            array_filter($cards, fn($c) => !$c['is_sideboard']), 'quantity'));
        $side_total = array_sum(array_column(
            array_filter($cards, fn($c) =>  $c['is_sideboard']), 'quantity'));
    }
}
?>

<div class="container my-4" style="max-width:760px;">

    <div class="d-flex align-items-center gap-3 mb-4">
        <a href="decks.php" class="btn btn-sm btn-outline-secondary">← Decks</a>
        <h1 class="mb-0" style="color:#c9a227;"><i class="bi bi-box-arrow-in-down me-2"></i>Import Deck</h1>
    </div>

    <!-- Code entry -->
    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <label class="form-label" style="color:#e8e8e8;">Enter Export Code</label>
            <small class="d-block mb-2" style="color:#8899aa;">
                Codes look like <code style="color:#c9a227;">MTG-ABCD1234</code> — ask the deck owner to share theirs.
            </small>
            <form method="get" action="import_deck.php" class="d-flex gap-2">
                <input type="text" name="code" class="form-control"
                       placeholder="MTG-XXXXXXXX"
                       value="<?= htmlspecialchars($code) ?>"
                       maxlength="12"
                       id="code-input"
                       style="text-transform:uppercase;letter-spacing:0.08em;font-family:monospace;max-width:220px;"
                       autocomplete="off"
                       required>
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-search me-1"></i>Preview
                </button>
            </form>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if ($export && $cards): ?>
    <!-- Deck preview -->
    <div class="card shadow-sm mb-4" style="border-top:4px solid #c9a227;">
        <div class="card-header" style="background:rgba(201,162,39,0.1);border-bottom:1px solid rgba(201,162,39,0.2);">
            <div class="d-flex justify-content-between align-items-center">
                <h5 class="mb-0" style="color:#c9a227;">
                    <i class="bi bi-layers me-2"></i><?= htmlspecialchars($export['deck_name']) ?>
                </h5>
                <span class="badge bg-secondary">
                    <?= $main_total + $side_total ?> cards &nbsp;·&nbsp;
                    <?= count($cards) ?> unique
                </span>
            </div>
        </div>
        <div class="card-body">
            <?php if ($export['description']): ?>
                <p class="mb-3" style="color:#8899aa;"><?= htmlspecialchars($export['description']) ?></p>
            <?php endif; ?>

            <div class="row g-2 mb-3 text-center">
                <div class="col-4">
                    <div class="p-2 rounded" style="background:rgba(255,255,255,0.05);">
                        <div class="fw-bold" style="color:#e8e8e8;"><?= $main_total ?></div>
                        <div class="small" style="color:#8899aa;">Main Deck</div>
                    </div>
                </div>
                <div class="col-4">
                    <div class="p-2 rounded" style="background:rgba(255,255,255,0.05);">
                        <div class="fw-bold" style="color:#e8e8e8;"><?= $side_total ?></div>
                        <div class="small" style="color:#8899aa;">Sideboard</div>
                    </div>
                </div>
                <div class="col-4">
                    <div class="p-2 rounded" style="background:rgba(255,255,255,0.05);">
                        <div class="fw-bold" style="color:#e8e8e8;"><?= $export['import_count'] ?></div>
                        <div class="small" style="color:#8899aa;">Times Imported</div>
                    </div>
                </div>
            </div>

            <p class="small mb-3" style="color:#8899aa;">
                <i class="bi bi-person me-1"></i>Shared by <strong style="color:#e8e8e8;"><?= htmlspecialchars($export['owner']) ?></strong>
                &nbsp;·&nbsp;
                <i class="bi bi-clock me-1"></i><?= date('M j, Y', strtotime($export['created_at'])) ?>
                <?php if ($export['expires_at']): ?>
                    &nbsp;·&nbsp; <i class="bi bi-hourglass-split me-1"></i>Expires <?= date('M j, Y', strtotime($export['expires_at'])) ?>
                <?php endif; ?>
            </p>

            <!-- Card list -->
            <div style="max-height:320px;overflow-y:auto;">
                <table class="table table-sm mb-0" style="color:#e8e8e8;">
                    <thead>
                        <tr style="color:#c9a227;">
                            <th>Card</th><th>Type</th><th>Mana</th>
                            <th class="text-end">Qty</th><th>Side?</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($cards as $card): ?>
                        <tr>
                            <td><?= htmlspecialchars($card['name']) ?></td>
                            <td class="small" style="color:#8899aa;"><?= htmlspecialchars($card['type_line']) ?></td>
                            <td><?= htmlspecialchars($card['mana_cost'] ?? '—') ?></td>
                            <td class="text-end fw-bold"><?= $card['quantity'] ?></td>
                            <td><?= $card['is_sideboard'] ? '<span class="badge bg-secondary">Side</span>' : 'Main' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card-footer bg-transparent d-flex justify-content-end gap-2">
            <a href="import_deck.php" class="btn btn-secondary">Cancel</a>
            <form action="do_import_deck.php" method="post">
                <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                <input type="hidden" name="export_code" value="<?= htmlspecialchars($export['export_code']) ?>">
                <button type="submit" class="btn btn-success">
                    <i class="bi bi-box-arrow-in-down me-1"></i>Import This Deck
                </button>
            </form>
        </div>
    </div>
    <?php endif; ?>

</div>

<script>
// Auto-uppercase and insert hyphen after 3rd character (MTG-)
(function () {
    const input = document.getElementById('code-input');
    if (!input) return;

    input.addEventListener('input', function (e) {
        // Strip everything except alphanumeric
        let raw = this.value.replace(/[^A-Za-z0-9]/g, '').toUpperCase();

        // Enforce prefix: first 3 chars must be MTG
        if (raw.length >= 3 && raw.substring(0, 3) !== 'MTG') {
            // User typed something else — prepend MTG
            raw = 'MTG' + raw.replace(/^MTG/i, '');
        }

        // Insert hyphen after position 3
        let formatted = raw.length > 3
            ? raw.substring(0, 3) + '-' + raw.substring(3, 11)
            : raw;

        this.value = formatted;
    });

    // On paste — clean up immediately
    input.addEventListener('paste', function () {
        setTimeout(() => {
            let raw = this.value.replace(/[^A-Za-z0-9]/g, '').toUpperCase();
            this.value = raw.length > 3
                ? raw.substring(0, 3) + '-' + raw.substring(3, 11)
                : raw;
        }, 0);
    });
})();
</script>

<script>
// Auto-uppercase and auto-insert dash after "MTG" prefix
(function () {
    const el = document.getElementById('export-code-input');
    if (!el) return;

    el.addEventListener('input', function (e) {
        let val = this.value.toUpperCase().replace(/[^A-Z0-9\-]/g, '');

        // Strip any existing dashes so we can reformat cleanly
        const stripped = val.replace(/-/g, '');

        // Enforce MTG prefix and add dash after position 3
        if (stripped.length <= 3) {
            val = stripped;
        } else {
            val = stripped.slice(0, 3) + '-' + stripped.slice(3, 11);
        }

        // Force MTG as the prefix if user is typing from scratch
        if (val.length > 0 && !val.startsWith('MTG')) {
            // Only enforce if they have typed at least 3 non-dash chars
            if (stripped.length >= 3 && stripped.slice(0, 3) !== 'MTG') {
                val = 'MTG-' + stripped.slice(0, 8);
            }
        }

        this.value = val;
    });

    // On paste, clean and reformat immediately
    el.addEventListener('paste', function (e) {
        e.preventDefault();
        const pasted = (e.clipboardData || window.clipboardData)
            .getData('text').toUpperCase().replace(/[^A-Z0-9]/g, '');
        const code = pasted.slice(0, 11); // max 3+8=11 chars without dash
        if (code.length >= 3) {
            this.value = code.slice(0, 3) + '-' + code.slice(3, 11);
        } else {
            this.value = code;
        }
    });
})();
</script>
<?php include 'footer.php'; ?>
