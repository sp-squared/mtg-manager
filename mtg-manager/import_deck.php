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

// MTG export code lookup
$code    = strtoupper(trim($_GET['code'] ?? ''));
$export  = null;
$cards   = [];
$error   = '';

// TPL template code lookup
$tpl_code     = strtoupper(trim($_GET['tpl_code'] ?? ''));
$template     = null;
$tpl_error    = '';
$tpl_main     = 0;
$tpl_side     = 0;

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

        // Tally main / side totals (support both old is_sideboard and new zone)
        $get_zone   = fn($c) => $c['zone'] ?? ($c['is_sideboard'] ? 'sideboard' : 'mainboard');
        $main_total = array_sum(array_column(
            array_filter($cards, fn($c) => $get_zone($c) === 'mainboard'), 'quantity'));
        $side_total = array_sum(array_column(
            array_filter($cards, fn($c) => $get_zone($c) !== 'mainboard'), 'quantity'));
    }
}

if ($tpl_code) {
    $ts = $dbc->prepare(
        "SELECT t.id, t.share_code, t.name, t.description, t.format,
                t.card_data, t.total_cards, t.fork_count, t.created_at,
                p.username AS creator,
                (SELECT deck_id FROM user_decks WHERE user_id = ? AND template_id = t.id LIMIT 1) AS fork_deck_id
         FROM deck_templates t
         JOIN player p ON p.id = t.creator_user_id
         WHERE t.share_code = ?"
    );
    $ts->bind_param("is", $user_id, $tpl_code);
    $ts->execute();
    $template = $ts->get_result()->fetch_assoc();
    $ts->close();

    if (!$template) {
        $tpl_error = 'No template found with that code. Check the code and try again.';
    } else {
        $tpl_cards = json_decode($template['card_data'], true) ?? [];
        $tpl_main  = array_sum(array_column(
            array_filter($tpl_cards, fn($c) => ($c['zone'] ?? 'mainboard') === 'mainboard'), 'quantity'));
        $tpl_side  = array_sum(array_column(
            array_filter($tpl_cards, fn($c) => ($c['zone'] ?? 'mainboard') === 'sideboard'), 'quantity'));
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
                            <td><?php $z = $get_zone($card); echo $z === 'mainboard' ? 'Main' : '<span class="badge bg-secondary">' . ucfirst($z) . '</span>'; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card-footer bg-transparent d-flex justify-content-end gap-2">
            <a href="import_deck.php" class="btn btn-secondary">Cancel</a>
            <form action="actions/do_import_deck.php" method="post">
                <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                <input type="hidden" name="export_code" value="<?= htmlspecialchars($export['export_code']) ?>">
                <button type="submit" class="btn btn-success">
                    <i class="bi bi-box-arrow-in-down me-1"></i>Import This Deck
                </button>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <!-- ── Template Code (TPL-) ────────────────────────────────────────── -->
    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <label class="form-label" style="color:#e8e8e8;">Fork by Template Code</label>
            <small class="d-block mb-2" style="color:#8899aa;">
                Template codes look like <code style="color:#6ea8fe;">TPL-ABCD1234</code> — shared from the deck editor.
            </small>
            <form method="get" action="import_deck.php" class="d-flex gap-2">
                <input type="hidden" name="code" value="<?= htmlspecialchars($code) ?>">
                <input type="text" name="tpl_code" id="tpl-code-input" class="form-control"
                       placeholder="TPL-XXXXXXXX"
                       value="<?= htmlspecialchars($tpl_code) ?>"
                       maxlength="12"
                       style="text-transform:uppercase;letter-spacing:0.08em;font-family:monospace;max-width:220px;"
                       autocomplete="off">
                <button type="submit" class="btn btn-info">
                    <i class="bi bi-search me-1"></i>Preview
                </button>
            </form>
        </div>
    </div>

    <?php if ($tpl_error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($tpl_error) ?></div>
    <?php endif; ?>

    <?php if ($template): ?>
    <div class="card shadow-sm mb-4" style="border-top:3px solid #6ea8fe;">
        <div class="card-body">
            <div class="d-flex align-items-start justify-content-between gap-2 mb-2">
                <h5 class="mb-0" style="color:#e8e8e8;"><?= htmlspecialchars($template['name']) ?></h5>
                <?php if ($template['format']): ?>
                    <span class="badge flex-shrink-0"
                          style="background:rgba(110,168,254,0.15);color:#6ea8fe;border:1px solid rgba(110,168,254,0.3);">
                        <?= htmlspecialchars($template['format']) ?>
                    </span>
                <?php endif; ?>
            </div>
            <?php if ($template['description']): ?>
                <p class="mb-3" style="color:#8899aa;"><?= htmlspecialchars($template['description']) ?></p>
            <?php endif; ?>

            <div class="row g-2 mb-3 text-center">
                <div class="col-4">
                    <div class="p-2 rounded" style="background:rgba(255,255,255,0.05);">
                        <div class="fw-bold" style="color:#e8e8e8;"><?= $tpl_main ?></div>
                        <div class="small" style="color:#8899aa;">Main Deck</div>
                    </div>
                </div>
                <div class="col-4">
                    <div class="p-2 rounded" style="background:rgba(255,255,255,0.05);">
                        <div class="fw-bold" style="color:#e8e8e8;"><?= $tpl_side ?></div>
                        <div class="small" style="color:#8899aa;">Sideboard</div>
                    </div>
                </div>
                <div class="col-4">
                    <div class="p-2 rounded" style="background:rgba(255,255,255,0.05);">
                        <div class="fw-bold" style="color:#e8e8e8;"><?= $template['fork_count'] ?></div>
                        <div class="small" style="color:#8899aa;">Forks</div>
                    </div>
                </div>
            </div>

            <p class="small mb-0" style="color:#8899aa;">
                <i class="bi bi-person me-1"></i>By <strong style="color:#e8e8e8;"><?= htmlspecialchars($template['creator']) ?></strong>
                &nbsp;·&nbsp;
                <i class="bi bi-clock me-1"></i><?= date('M j, Y', strtotime($template['created_at'])) ?>
                &nbsp;·&nbsp;
                <code style="font-size:0.78rem;"><?= htmlspecialchars($template['share_code']) ?></code>
            </p>
        </div>
        <div class="card-footer bg-transparent d-flex justify-content-end gap-2">
            <a href="import_deck.php" class="btn btn-secondary">Cancel</a>
            <?php if ($template['fork_deck_id']): ?>
                <a href="deck_editor.php?deck_id=<?= $template['fork_deck_id'] ?>"
                   class="btn btn-outline-success">
                    <i class="bi bi-check-circle me-1"></i>Already forked — Edit my copy
                </a>
            <?php else: ?>
                <button class="btn btn-info" id="fork-template-btn"
                        data-template-id="<?= $template['id'] ?>">
                    <i class="bi bi-diagram-2 me-1"></i>Fork to My Decks
                </button>
            <?php endif; ?>
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

<script>
// TPL code input formatter — enforces TPL-XXXXXXXX shape
(function () {
    const el = document.getElementById('tpl-code-input');
    if (!el) return;

    function format(raw) {
        const stripped = raw.replace(/[^A-Z0-9]/g, '');
        if (stripped.length <= 3) return stripped;
        return stripped.slice(0, 3) + '-' + stripped.slice(3, 11);
    }

    el.addEventListener('input', function () {
        const pos = this.selectionStart;
        this.value = format(this.value.toUpperCase());
    });

    el.addEventListener('paste', function (e) {
        e.preventDefault();
        const pasted = (e.clipboardData || window.clipboardData)
            .getData('text').toUpperCase().replace(/[^A-Z0-9]/g, '');
        this.value = format(pasted);
    });
})();

// Fork template button
(function () {
    const btn = document.getElementById('fork-template-btn');
    if (!btn) return;

    btn.addEventListener('click', async function () {
        this.disabled = true;
        this.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Forking…';

        const fd = new FormData();
        fd.append('template_id', this.dataset.templateId);

        try {
            const res  = await fetch('ajax/fork_template.php', { method: 'POST', body: fd });
            const data = await res.json();

            if (data.already_forked) {
                window.location.href = 'deck_editor.php?deck_id=' + data.deck_id;
            } else if (data.success) {
                window.location.href = 'deck_editor.php?deck_id=' + data.deck_id + '&msg=forked';
            } else {
                alert(data.error || 'Fork failed');
                this.disabled = false;
                this.innerHTML = '<i class="bi bi-diagram-2 me-1"></i>Fork to My Decks';
            }
        } catch (_) {
            alert('Network error — please try again.');
            this.disabled = false;
            this.innerHTML = '<i class="bi bi-diagram-2 me-1"></i>Fork to My Decks';
        }
    });
})();
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
