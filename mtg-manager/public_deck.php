<?php
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/connect.php';

$code   = strtoupper(trim($_GET['code'] ?? ''));
$export = null;
$cards  = [];
$error  = '';

if ($code) {
    $s = $dbc->prepare(
        "SELECT e.export_code, e.deck_name, e.description, e.card_data,
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
        $error = 'This share code has expired.';
        $export = null;
    } else {
        $cards = json_decode($export['card_data'], true) ?? [];

        // Sort: main first (grouped by type-ish), sideboard last
        $main_cards = array_filter($cards, fn($c) => !$c['is_sideboard']);
        $side_cards = array_filter($cards, fn($c) =>  $c['is_sideboard']);

        $main_total = array_sum(array_column($main_cards, 'quantity'));
        $side_total = array_sum(array_column($side_cards, 'quantity'));
    }
}
?>

<div class="container my-4" style="max-width:800px;">

    <div class="d-flex align-items-center gap-3 mb-4">
        <h1 class="mb-0" style="color:#c9a227;"><i class="bi bi-eye me-2"></i>Preview Deck</h1>
    </div>

    <!-- Code entry -->
    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <label class="form-label" style="color:#e8e8e8;">Enter Export Code</label>
            <small class="d-block mb-2" style="color:#8899aa;">
                Codes look like <code style="color:#c9a227;">MTG-ABCD1234</code> — ask the deck owner to share theirs.
            </small>
            <form method="get" action="public_deck.php" class="d-flex gap-2">
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

    <!-- Header -->
    <div class="card shadow-sm mb-4" style="border-top:4px solid #c9a227;background:#1e1e2e;">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
                <div>
                    <h1 class="mb-1" style="color:#c9a227;"><?= htmlspecialchars($export['deck_name']) ?></h1>
                    <?php if ($export['description']): ?>
                        <p class="mb-1" style="color:#8899aa;"><?= htmlspecialchars($export['description']) ?></p>
                    <?php endif; ?>
                    <p class="mb-0 small" style="color:#8899aa;">
                        Shared by <strong style="color:#e8e8e8;"><?= htmlspecialchars($export['owner']) ?></strong>
                        &nbsp;·&nbsp; <?= $main_total ?> main<?= $side_total > 0 ? " + {$side_total} sideboard" : '' ?>
                        &nbsp;·&nbsp; Code: <code style="color:#c9a227;"><?= htmlspecialchars($export['export_code']) ?></code>
                        <?php if ($export['expires_at']): ?>
                            &nbsp;·&nbsp; Expires <?= date('M j, Y', strtotime($export['expires_at'])) ?>
                        <?php endif; ?>
                    </p>
                </div>
                <div class="d-flex gap-2 flex-shrink-0">
                    <?php if (isLoggedIn()): ?>
                    <a href="import_deck.php?code=<?= urlencode($export['export_code']) ?>"
                       class="btn btn-success">
                        <i class="bi bi-box-arrow-in-down me-1"></i>Import This Deck
                    </a>
                    <?php else: ?>
                    <a href="index.php" class="btn btn-outline-primary">
                        <i class="bi bi-person me-1"></i>Log in to Import
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Card List -->
    <div class="row g-4">
        <!-- Main Deck -->
        <div class="col-md-<?= $side_total > 0 ? '8' : '12' ?>">
            <div class="card shadow-sm" style="background:#1e1e2e;">
                <div class="card-header" style="background:rgba(201,162,39,0.08);border-bottom:1px solid rgba(201,162,39,0.2);">
                    <span style="color:#c9a227;font-weight:600;">
                        <i class="bi bi-stack me-2"></i>Main Deck
                        <span class="badge ms-2 bg-secondary"><?= $main_total ?></span>
                    </span>
                </div>
                <div class="card-body p-0" style="background:#ffffff;">
                    <table class="table table-sm mb-0" style="font-size:0.85rem;color:#111111;background:#ffffff;">
                        <tbody>
                        <?php foreach ($main_cards as $card): ?>
                            <tr style="border-bottom:1px solid #e5e7eb;">
                                <td style="width:36px;color:#6b7280;text-align:right;padding-right:8px;"><?= (int)$card['quantity'] ?></td>
                                <td style="color:#111111;"><?= htmlspecialchars($card['name']) ?></td>
                                <?php if (!empty($card['mana_cost'])): ?>
                                <td class="text-end" style="color:#6b7280;font-size:0.78rem;"><?= htmlspecialchars($card['mana_cost']) ?></td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <?php if ($side_total > 0): ?>
        <!-- Sideboard -->
        <div class="col-md-4">
            <div class="card shadow-sm" style="background:#1e1e2e;">
                <div class="card-header" style="background:rgba(136,153,170,0.08);border-bottom:1px solid rgba(136,153,170,0.2);">
                    <span style="color:#8899aa;font-weight:600;">
                        <i class="bi bi-collection me-2"></i>Sideboard
                        <span class="badge ms-2 bg-secondary"><?= $side_total ?></span>
                    </span>
                </div>
                <div class="card-body p-0" style="background:#ffffff;">
                    <table class="table table-sm mb-0" style="font-size:0.85rem;color:#111111;background:#ffffff;">
                        <tbody>
                        <?php foreach ($side_cards as $card): ?>
                            <tr style="border-bottom:1px solid #e5e7eb;">
                                <td style="width:36px;color:#6b7280;text-align:right;padding-right:8px;"><?= (int)$card['quantity'] ?></td>
                                <td style="color:#111111;"><?= htmlspecialchars($card['name']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Footer note -->
    <p class="text-center mt-4 small" style="color:#8899aa;">
        <i class="bi bi-box-arrow-up me-1"></i>Shared via MTG Manager &nbsp;·&nbsp;
        Imported <?= (int)$export['import_count'] ?> time<?= $export['import_count'] !== 1 ? 's' : '' ?>
    </p>

    <?php endif; // export && cards ?>
</div>

<script>
// Auto-uppercase and insert hyphen after MTG prefix
(function () {
    const input = document.getElementById('code-input');
    if (!input) return;

    input.addEventListener('input', function () {
        let raw = this.value.replace(/[^A-Za-z0-9]/g, '').toUpperCase();
        if (raw.length >= 3 && raw.substring(0, 3) !== 'MTG') {
            raw = 'MTG' + raw.replace(/^MTG/i, '');
        }
        this.value = raw.length > 3
            ? raw.substring(0, 3) + '-' + raw.substring(3, 11)
            : raw;
    });

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

<?php
$dbc->close();
include __DIR__ . '/includes/footer.php';
?>
