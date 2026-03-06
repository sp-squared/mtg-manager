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
        case 'created': $message = 'New deck created.'; break;
        case 'deleted':  $message = 'Deck deleted.'; break;
        case 'exported': $message = 'Deck exported! Your code is ready.'; break;
    }
}
?>

<div class="container my-4">
    <div class="d-flex align-items-center justify-content-between mb-4">
        <h1 class="mb-0">My Decks</h1>
        <a href="import_deck.php" class="btn btn-outline-primary">
            <i class="bi bi-box-arrow-in-down me-1"></i>Import Deck
        </a>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <?= htmlspecialchars($message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Create new deck form -->
    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <h5 class="card-title">Create New Deck</h5>
            <form action="actions/create_deck.php" method="post">
            <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                <div class="mb-3">
                    <label for="name" class="form-label">Deck Name</label>
                    <input type="text" class="form-control" name="name" required>
                </div>
                <div class="mb-3">
                    <label for="description" class="form-label">Description (optional)</label>
                    <textarea class="form-control" name="description" rows="3"></textarea>
                </div>
                <button type="submit" class="btn btn-primary">Create Deck</button>
            </form>
        </div>
    </div>

    <?php
    // Fetch decks including is_favorite
    $stmt = $dbc->prepare("SELECT d.id, d.name, d.description, d.created_at, d.updated_at, d.is_favorite, COUNT(IF(c.type_line NOT LIKE '%Token%', dc.card_id, NULL)) as unique_count, COALESCE(SUM(IF(c.type_line NOT LIKE '%Token%', dc.quantity, 0)), 0) as total_count FROM decks d LEFT JOIN deck_cards dc ON dc.deck_id = d.id LEFT JOIN cards c ON c.id = dc.card_id WHERE d.user_id = ? GROUP BY d.id ORDER BY d.created_at DESC");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $decks = $stmt->get_result();

    if ($decks->num_rows == 0): ?>
        <div class="alert alert-info">You haven't created any decks yet.</div>
    <?php else: ?>
        <div class="row row-cols-1 row-cols-sm-2 row-cols-md-3 g-4">
            <?php while ($deck = $decks->fetch_assoc()): ?>
                <div class="col">
                    <div class="card h-100 shadow-sm">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start">
                                <h5 class="card-title mb-0">
                                    <?= htmlspecialchars($deck['name']) ?>
                                    <?php if ($deck['total_count'] > 0): ?>
                                        <span class="badge bg-secondary ms-1"
                                              title="<?= $deck['total_count'] ?> total cards, <?= $deck['unique_count'] ?> unique">
                                            <?= $deck['total_count'] ?>
                                            <span style="opacity:0.7;font-weight:400;font-size:0.75em;">/ <?= $deck['unique_count'] ?> uniq</span>
                                        </span>
                                    <?php endif; ?>
                                </h5>
                                <i class="bi <?= $deck['is_favorite'] ? 'bi-star-fill text-warning' : 'bi-star' ?> favorite-star" 
                                   data-deck-id="<?= $deck['id'] ?>" 
                                   style="cursor: pointer; font-size: 1.2rem;"></i>
                            </div>
                            <p class="card-text mt-2"><?= htmlspecialchars($deck['description'] ?: 'No description') ?></p>
                            <p class="card-text">
                                <small class="text-muted">
                                    Created: <?= $deck['created_at'] ?><br>
                                    Updated: <?= $deck['updated_at'] ?? 'Never' ?>
                                </small>
                            </p>
                        </div>
                        <div class="card-footer bg-transparent d-flex justify-content-between align-items-center">
                            <a href="deck_editor.php?deck_id=<?= $deck['id'] ?>" class="btn btn-sm btn-success">Edit Deck</a>
                            <button class="btn btn-sm btn-outline-warning export-deck-btn"
                                    data-deck-id="<?= $deck['id'] ?>"
                                    data-deck-name="<?= htmlspecialchars($deck['name']) ?>"
                                    title="Export this deck to share with others">
                                <i class="bi bi-box-arrow-up me-1"></i>Export
                            </button>
                            <button type="button" class="btn btn-sm btn-danger"
        onclick="if(confirm('Delete this deck? This cannot be undone.')) {
            var f=document.createElement('form');
            f.method='POST';f.action='actions/delete_deck.php';
            var d=document.createElement('input');d.type='hidden';d.name='deck_id';d.value='<?= $deck['id'] ?>';
            var c=document.createElement('input');c.type='hidden';c.name='csrf_token';c.value=document.querySelector('meta[name=csrf-token]').content;
            f.appendChild(d);f.appendChild(c);document.body.appendChild(f);f.submit();
        }">Delete</button>
                        </div>
                    </div>
                </div>
            <?php endwhile; ?>
        </div>
    <?php endif; ?>
    <?php $stmt->close(); $dbc->close(); ?>
</div>

<!-- Export Code Modal -->
<div class="modal fade" id="exportModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" style="color:#c9a227;">
                    <i class="bi bi-box-arrow-up me-2"></i>Export Deck
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>

            <!-- Step 1: Expiry picker (shown before export) -->
            <div id="export-step-config" class="modal-body py-4">
                <p style="color:#e8e8e8;" id="export-config-deck-name" class="mb-3 fw-bold"></p>
                <label class="form-label" style="color:#e8e8e8;">Code Expiry</label>
                <small class="d-block mb-2" style="color:#8899aa;">
                    How long should this share code remain valid?
                </small>
                <div class="d-flex flex-column gap-2" id="expiry-options">
                    <label class="d-flex align-items-center gap-2 p-2 rounded"
                           style="cursor:pointer;border:1px solid rgba(255,255,255,0.1);">
                        <input type="radio" name="expires_in" value="never" checked class="form-check-input mt-0">
                        <span style="color:#e8e8e8;">Never expires</span>
                        <span class="ms-auto badge bg-secondary">∞</span>
                    </label>
                    <label class="d-flex align-items-center gap-2 p-2 rounded"
                           style="cursor:pointer;border:1px solid rgba(255,255,255,0.1);">
                        <input type="radio" name="expires_in" value="30d" class="form-check-input mt-0">
                        <span style="color:#e8e8e8;">30 days</span>
                    </label>
                    <label class="d-flex align-items-center gap-2 p-2 rounded"
                           style="cursor:pointer;border:1px solid rgba(255,255,255,0.1);">
                        <input type="radio" name="expires_in" value="7d" class="form-check-input mt-0">
                        <span style="color:#e8e8e8;">7 days</span>
                    </label>
                    <label class="d-flex align-items-center gap-2 p-2 rounded"
                           style="cursor:pointer;border:1px solid rgba(255,255,255,0.1);">
                        <input type="radio" name="expires_in" value="1d" class="form-check-input mt-0">
                        <span style="color:#e8e8e8;">24 hours</span>
                        <span class="ms-auto badge" style="background:rgba(201,162,39,0.2);color:#c9a227;">Short-lived</span>
                    </label>
                </div>
            </div>
            <div id="export-step-config-footer" class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-warning" id="confirm-export-btn">
                    <i class="bi bi-box-arrow-up me-1"></i>Generate Code
                </button>
            </div>

            <!-- Step 2: Show generated code (hidden until export completes) -->
            <div id="export-step-result" class="modal-body text-center py-4" style="display:none;">
                <p style="color:#e8e8e8;" id="export-deck-name-label"></p>
                <p class="mb-2" style="color:#8899aa;font-size:0.85rem;">Share this code with anyone:</p>
                <div class="d-flex align-items-center justify-content-center gap-2 my-3">
                    <code id="export-code-display"
                          style="font-size:1.6rem;font-weight:700;color:#c9a227;letter-spacing:0.12em;
                                 background:rgba(201,162,39,0.1);padding:0.4rem 1rem;border-radius:8px;
                                 border:1px solid rgba(201,162,39,0.3);"></code>
                    <button class="btn btn-sm btn-outline-secondary" id="copy-code-btn" title="Copy to clipboard">
                        <i class="bi bi-clipboard"></i>
                    </button>
                </div>
                <div id="export-expiry-note" class="small mb-2" style="color:#8899aa;"></div>
                <p class="small mb-0" style="color:#8899aa;">
                    Anyone with this code can import a copy on the
                    <a href="import_deck.php" style="color:#c9a227;">Import Deck</a> page.
                    Manage your codes on your <a href="profile.php" style="color:#c9a227;">Profile</a>.
                </p>
            </div>
            <div id="export-step-result-footer" class="modal-footer" style="display:none;">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>

        </div>
    </div>
</div>

<script>
let _exportDeckId = null;

// Reset modal to config step when opened
document.getElementById('exportModal').addEventListener('show.bs.modal', function () {
    document.getElementById('export-step-config').style.display  = '';
    document.getElementById('export-step-config-footer').style.display = '';
    document.getElementById('export-step-result').style.display  = 'none';
    document.getElementById('export-step-result-footer').style.display = 'none';
    document.querySelector('input[name="expires_in"][value="never"]').checked = true;
});

document.querySelectorAll('.export-deck-btn').forEach(btn => {
    btn.addEventListener('click', function () {
        _exportDeckId = this.dataset.deckId;
        document.getElementById('export-config-deck-name').textContent =
            '"' + this.dataset.deckName + '"';
        new bootstrap.Modal(document.getElementById('exportModal')).show();
    });
});

document.getElementById('confirm-export-btn').addEventListener('click', async function () {
    const expiresIn = document.querySelector('input[name="expires_in"]:checked').value;
    const btn = this;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Generating…';

    const fd = new FormData();
    fd.append('deck_id',    _exportDeckId);
    fd.append('expires_in', expiresIn);

    try {
        const res  = await fetch('ajax/export_deck.php', { method: 'POST', body: fd });
        const data = await res.json();

        if (!data.success) {
            alert(data.error || 'Export failed');
        } else {
            document.getElementById('export-code-display').textContent  = data.code;
            document.getElementById('export-deck-name-label').textContent =
                '"' + data.deck_name + '" is ready to share.';

            const expiryLabels = {
                'never': '⏳ This code never expires.',
                '30d':   '⏳ This code expires in 30 days.',
                '7d':    '⏳ This code expires in 7 days.',
                '1d':    '⏳ This code expires in 24 hours.',
            };
            if (data.expires_at) {
                const d = new Date(data.expires_at.replace(' ', 'T'));
                document.getElementById('export-expiry-note').textContent =
                    '⏳ Expires ' + d.toLocaleDateString('en-US', {month:'short',day:'numeric',year:'numeric'});
            } else {
                document.getElementById('export-expiry-note').textContent = '♾️ Never expires.';
            }

            document.getElementById('export-step-config').style.display  = 'none';
            document.getElementById('export-step-config-footer').style.display = 'none';
            document.getElementById('export-step-result').style.display  = '';
            document.getElementById('export-step-result-footer').style.display = '';
        }
    } catch (_) {
        alert('Network error — please try again.');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-box-arrow-up me-1"></i>Generate Code';
    }
});

document.getElementById('copy-code-btn').addEventListener('click', function () {
    const code = document.getElementById('export-code-display').textContent;
    navigator.clipboard.writeText(code).then(() => {
        this.innerHTML = '<i class="bi bi-clipboard-check"></i>';
        setTimeout(() => { this.innerHTML = '<i class="bi bi-clipboard"></i>'; }, 2000);
    });
});
</script>

<!-- JavaScript for favorite toggle -->
<script>
document.querySelectorAll('.favorite-star').forEach(star => {
    star.addEventListener('click', function(e) {
        e.stopPropagation();
        const deckId = this.dataset.deckId;
        const icon = this;

        const fd = new FormData();
        fd.append('deck_id', deckId);
        fetch('ajax/toggle_favorite.php', { method: 'POST', body: fd })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                if (data.is_favorite) {
                    icon.classList.remove('bi-star');
                    icon.classList.add('bi-star-fill', 'text-warning');
                } else {
                    icon.classList.remove('bi-star-fill', 'text-warning');
                    icon.classList.add('bi-star');
                }
            } else {
                alert(data.error || 'Error toggling favorite');
            }
        })
        .catch(err => {
            alert('Network error');
        });
    });
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>