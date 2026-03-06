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
$user_id = $_SESSION['id'];
$username = getCurrentUser();

// Get user email from player table
$stmt = $dbc->prepare("SELECT email FROM player WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$email = $user['email'] ?? 'Not provided';
$stmt->close();

// Get collection count
$col_query = "SELECT COUNT(*) as total FROM user_collection WHERE user_id = ?";
$col_stmt = $dbc->prepare($col_query);
$col_stmt->bind_param("i", $user_id);
$col_stmt->execute();
$col_count = $col_stmt->get_result()->fetch_assoc()['total'] ?? 0;
$col_stmt->close();

// Get deck count
$deck_query = "SELECT COUNT(*) as total FROM decks WHERE user_id = ?";
$deck_stmt = $dbc->prepare($deck_query);
$deck_stmt->bind_param("i", $user_id);
$deck_stmt->execute();
$deck_count = $deck_stmt->get_result()->fetch_assoc()['total'] ?? 0;
$deck_stmt->close();

// Get wishlist count
$wish_query = "SELECT COUNT(*) as total FROM wishlist WHERE user_id = ?";
$wish_stmt = $dbc->prepare($wish_query);
$wish_stmt->bind_param("i", $user_id);
$wish_stmt->execute();
$wish_count = $wish_stmt->get_result()->fetch_assoc()['total'] ?? 0;
$wish_stmt->close();

// Get user's deck exports
$exports_stmt = $dbc->prepare(
    "SELECT export_code, deck_name, description, created_at, expires_at, import_count
     FROM deck_exports WHERE owner_id = ? ORDER BY created_at DESC"
);
$exports_stmt->bind_param("i", $user_id);
$exports_stmt->execute();
$exports_result = $exports_stmt->get_result();
$exports_stmt->close();
$dbc->close();
?>

<div class="container my-4">

    <!-- Profile Header -->
    <div class="text-center mb-5">
        <div class="d-inline-flex align-items-center justify-content-center rounded-circle bg-dark text-white mb-3"
             style="width: 90px; height: 90px; font-size: 2.5rem;">
            <i class="bi bi-person-fill"></i>
        </div>
        <h1 class="mb-1 d-flex align-items-center justify-content-center gap-2">
            <span id="profile-username-display"><?= htmlspecialchars($username) ?></span>
            <button class="btn btn-sm btn-outline-secondary py-0 px-2"
                    style="font-size:0.75rem;border-color:rgba(201,162,39,0.4);color:#c9a227;"
                    data-bs-toggle="modal" data-bs-target="#editUsernameModal"
                    title="Edit username">
                <i class="bi bi-pencil-fill"></i>
            </button>
        </h1>
        <p class="mb-0 d-flex align-items-center justify-content-center gap-2">
            <i class="bi bi-envelope me-1"></i>
            <span id="profile-email-display"><?= htmlspecialchars($email) ?></span>
            <button class="btn btn-sm btn-outline-secondary py-0 px-2"
                    style="font-size:0.75rem;border-color:rgba(201,162,39,0.4);color:#c9a227;"
                    data-bs-toggle="modal" data-bs-target="#editEmailModal"
                    title="Edit email">
                <i class="bi bi-pencil-fill"></i>
            </button>
        </p>
        <p class="mt-2 mb-0">
            <button class="btn btn-sm btn-outline-warning"
                    style="font-size:0.78rem;"
                    data-bs-toggle="modal" data-bs-target="#changePasswordModal">
                <i class="bi bi-key me-1"></i>Change Password
            </button>
        </p>
    </div>

    <!-- Stat Cards -->
    <div class="row text-center g-4 mb-5">
        <div class="col-md-4">
            <div class="card shadow-sm h-100" style="border-top: 4px solid #0d6efd;">
                <div class="card-body">
                    <i class="bi bi-collection fs-2 text-primary mb-2 d-block"></i>
                    <h5 class="card-title text-muted">Collection</h5>
                    <p class="display-5 fw-bold mb-3"><?= $col_count ?></p>
                    <a href="collection.php" class="btn btn-outline-primary btn-sm">View Collection</a>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card shadow-sm h-100" style="border-top: 4px solid #198754;">
                <div class="card-body">
                    <i class="bi bi-stack fs-2 text-success mb-2 d-block"></i>
                    <h5 class="card-title text-muted">Decks</h5>
                    <p class="display-5 fw-bold mb-3"><?= $deck_count ?></p>
                    <a href="decks.php" class="btn btn-outline-success btn-sm">Manage Decks</a>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card shadow-sm h-100" style="border-top: 4px solid #ffc107;">
                <div class="card-body">
                    <i class="bi bi-heart fs-2 text-warning mb-2 d-block"></i>
                    <h5 class="card-title text-muted">Wishlist</h5>
                    <p class="display-5 fw-bold mb-3"><?= $wish_count ?></p>
                    <a href="wishlist.php" class="btn btn-outline-warning btn-sm">View Wishlist</a>
                </div>
            </div>
        </div>
    </div>

    <!-- Account Details -->
    <div class="card shadow-sm mx-auto" style="max-width: 500px;">
        <div class="card-header bg-dark text-white">
            <i class="bi bi-person-badge me-2"></i>Account Details
        </div>
        <ul class="list-group list-group-flush">
            <li class="list-group-item d-flex justify-content-between align-items-center">
                <span class="text-muted">Username</span>
                <div class="d-flex align-items-center gap-2">
                    <strong id="account-username-display"><?= htmlspecialchars($username) ?></strong>
                    <button class="btn btn-sm btn-outline-secondary py-0 px-2"
                            style="font-size:0.75rem;border-color:rgba(201,162,39,0.4);color:#c9a227;"
                            data-bs-toggle="modal" data-bs-target="#editUsernameModal"
                            title="Edit username">
                        <i class="bi bi-pencil-fill"></i>
                    </button>
                </div>
            </li>
            <li class="list-group-item d-flex justify-content-between align-items-center">
                <span class="text-muted">Email</span>
                <div class="d-flex align-items-center gap-2">
                    <strong id="account-email-display"><?= htmlspecialchars($email) ?></strong>
                    <button class="btn btn-sm btn-outline-secondary py-0 px-2"
                            style="font-size:0.75rem;border-color:rgba(201,162,39,0.4);color:#c9a227;"
                            data-bs-toggle="modal" data-bs-target="#editEmailModal"
                            title="Edit email">
                        <i class="bi bi-pencil-fill"></i>
                    </button>
                </div>
            </li>
            <li class="list-group-item d-flex justify-content-between align-items-center">
                <span class="text-muted">Password</span>
                <button class="btn btn-sm btn-outline-warning"
                        data-bs-toggle="modal" data-bs-target="#changePasswordModal">
                    <i class="bi bi-key me-1"></i>Change Password
                </button>
            </li>
            <li class="list-group-item d-flex justify-content-between">
                <span class="text-muted">Cards Collected</span>
                <strong><?= $col_count ?></strong>
            </li>
            <li class="list-group-item d-flex justify-content-between">
                <span class="text-muted">Decks Built</span>
                <strong><?= $deck_count ?></strong>
            </li>
            <li class="list-group-item d-flex justify-content-between">
                <span class="text-muted">Wishlist Items</span>
                <strong><?= $wish_count ?></strong>
            </li>
        </ul>
    </div>

</div>

    <!-- Deck Exports -->
    <div class="card shadow-sm mx-auto mt-4" style="max-width:720px;">
        <div class="card-header d-flex justify-content-between align-items-center"
             style="background:rgba(201,162,39,0.08);border-bottom:1px solid rgba(201,162,39,0.2);">
            <span style="color:#c9a227;font-weight:600;">
                <i class="bi bi-box-arrow-up me-2"></i>My Deck Export Codes
            </span>
            <a href="decks.php" class="btn btn-sm btn-outline-warning" style="font-size:0.8rem;">
                <i class="bi bi-plus me-1"></i>Export a Deck
            </a>
        </div>
        <?php
        $now = time();
        $export_rows = $exports_result->fetch_all(MYSQLI_ASSOC);
        if (empty($export_rows)):
        ?>
            <div class="card-body">
                <p class="mb-0 small" style="color:#8899aa;">
                    You haven't exported any decks yet.
                    <a href="decks.php" style="color:#c9a227;">Go to My Decks</a> and hit Export on any deck.
                </p>
            </div>
        <?php else: ?>
            <ul class="list-group list-group-flush">
            <?php foreach ($export_rows as $ex):
                $is_expired = $ex['expires_at'] && strtotime($ex['expires_at']) < $now;
                $expires_soon = !$is_expired && $ex['expires_at']
                                && strtotime($ex['expires_at']) < strtotime('+3 days');
            ?>
                <li class="list-group-item d-flex flex-wrap align-items-center gap-3 py-3
                            <?= $is_expired ? 'opacity-50' : '' ?>">

                    <!-- Code + badges -->
                    <div class="d-flex flex-column" style="min-width:160px;">
                        <code style="font-size:1rem;font-weight:700;color:#c9a227;letter-spacing:0.08em;">
                            <?= htmlspecialchars($ex['export_code']) ?>
                        </code>
                        <div class="d-flex gap-1 mt-1 flex-wrap">
                            <?php if ($is_expired): ?>
                                <span class="badge" style="background:rgba(220,53,69,0.2);color:#f87171;border:1px solid rgba(220,53,69,0.3);">Expired</span>
                            <?php elseif ($expires_soon): ?>
                                <span class="badge" style="background:rgba(255,193,7,0.15);color:#ffc107;border:1px solid rgba(255,193,7,0.3);">Expires soon</span>
                            <?php elseif (!$ex['expires_at']): ?>
                                <span class="badge" style="background:rgba(255,255,255,0.05);color:#8899aa;">Never expires</span>
                            <?php endif; ?>
                            <span class="badge bg-secondary"><?= $ex['import_count'] ?> import<?= $ex['import_count'] !== 1 ? 's' : '' ?></span>
                        </div>
                    </div>

                    <!-- Deck info -->
                    <div class="flex-grow-1">
                        <div style="color:#e8e8e8;font-weight:500;"><?= htmlspecialchars($ex['deck_name']) ?></div>
                        <div class="small" style="color:#8899aa;">
                            Created <?= date('M j, Y', strtotime($ex['created_at'])) ?>
                            <?php if ($ex['expires_at']): ?>
                                &nbsp;·&nbsp;
                                <?= $is_expired ? 'Expired' : 'Expires' ?>
                                <?= date('M j, Y', strtotime($ex['expires_at'])) ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Actions -->
                    <div class="d-flex gap-2 align-items-center">
                        <?php if (!$is_expired): ?>
                        <button class="btn btn-sm btn-outline-secondary copy-export-code-btn"
                                data-code="<?= htmlspecialchars($ex['export_code']) ?>"
                                title="Copy code">
                            <i class="bi bi-clipboard"></i>
                        </button>
                        <a href="public_deck.php?code=<?= urlencode($ex['export_code']) ?>"
                           class="btn btn-sm btn-outline-info" title="View public page" target="_blank">
                            <i class="bi bi-eye"></i>
                        </a>
                        <?php endif; ?>
                        <button class="btn btn-sm btn-outline-danger delete-export-btn"
                                data-code="<?= htmlspecialchars($ex['export_code']) ?>"
                                data-deck="<?= htmlspecialchars($ex['deck_name']) ?>"
                                title="Delete this export code">
                            <i class="bi bi-trash3"></i>
                        </button>
                    </div>
                </li>
            <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>


<!-- Danger Zone -->
<div class="card shadow-sm mx-auto mt-4" style="max-width:720px;border:1px solid rgba(220,53,69,0.3);">
    <div class="card-header d-flex justify-content-between align-items-center"
         style="background:rgba(220,53,69,0.08);border-bottom:1px solid rgba(220,53,69,0.2);">
        <span style="color:#f87171;font-weight:600;">
            <i class="bi bi-exclamation-triangle-fill me-2"></i>Danger Zone
        </span>
    </div>
    <div class="card-body d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div>
            <div style="color:#e8e8e8;font-weight:500;">Delete Account</div>
            <div class="small" style="color:#8899aa;">Permanently delete your account and all associated data. This cannot be undone.</div>
        </div>
        <button type="button" class="btn btn-danger btn-sm"
                data-bs-toggle="modal" data-bs-target="#deleteAccountModal">
            <i class="bi bi-person-x-fill me-1"></i>Delete My Account
        </button>
    </div>
</div>


<!-- Delete Account Modal -->
<div class="modal fade" id="deleteAccountModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border:1px solid rgba(220,53,69,0.4);">
            <div class="modal-header" style="background:rgba(220,53,69,0.08);border-bottom:1px solid rgba(220,53,69,0.2);">
                <h5 class="modal-title" style="color:#f87171;">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i>Delete Your Account
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" id="deleteAccountClose"></button>
            </div>
            <div class="modal-body py-4">
                <p style="color:#e8e8e8;">This will <strong>permanently delete</strong> your account and all of the following:</p>
                <ul class="mb-3" style="color:#8899aa;">
                    <li>Your entire card collection (<?= $col_count ?> card<?= $col_count !== 1 ? 's' : '' ?>)</li>
                    <li>All your decks (<?= $deck_count ?> deck<?= $deck_count !== 1 ? 's' : '' ?>)</li>
                    <li>Your wishlist (<?= $wish_count ?> item<?= $wish_count !== 1 ? 's' : '' ?>)</li>
                    <li>All your deck export codes</li>
                    <li>Your account login</li>
                </ul>
                <p class="mb-4" style="color:#f87171;font-size:0.9rem;">
                    <i class="bi bi-shield-x me-1"></i>There is no recovery. This is immediate and irreversible.
                </p>

                <div class="mb-3">
                    <label class="d-flex align-items-start gap-2 p-3 rounded"
                           style="cursor:pointer;border:1px solid rgba(220,53,69,0.3);background:rgba(220,53,69,0.06);">
                        <input type="checkbox" class="form-check-input flex-shrink-0 mt-1" id="delete-confirm-checkbox">
                        <span style="color:#e8e8e8;font-size:0.9rem;">
                            I understand this action is <strong>permanent</strong> and <strong>cannot be undone.</strong>
                        </span>
                    </label>
                </div>

                <div class="mb-1">
                    <label class="form-label mb-1" style="color:#8899aa;font-size:0.85rem;">
                        Type <strong style="color:#f87171;letter-spacing:0.05em;">I UNDERSTAND</strong> to confirm:
                    </label>
                    <input type="text" class="form-control" id="delete-confirm-input"
                           placeholder="I UNDERSTAND" autocomplete="off">
                </div>
            </div>
            <div class="modal-footer" style="border-top:1px solid rgba(220,53,69,0.2);">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" id="confirm-delete-account-btn" disabled>
                    <i class="bi bi-person-x-fill me-1"></i>Delete My Account
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Edit Username Modal -->
<div class="modal fade" id="editUsernameModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" style="color:#c9a227;">
                    <i class="bi bi-person-fill me-2"></i>Update Username
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="username-modal-error" class="alert alert-danger d-none mb-3"></div>
                <div class="mb-3">
                    <label class="form-label" style="color:#e8e8e8;">New Username</label>
                    <input type="text" class="form-control" id="username-input"
                           placeholder="Choose a username"
                           value="<?= htmlspecialchars($username) ?>"
                           pattern="[a-z0-9_]+"
                           title="Lowercase letters, numbers, and underscores only"
                           autocomplete="username">
                    <div class="form-text" style="color:#8899aa;">
                        Automatically saved as lowercase. Letters, numbers, underscores only.
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="save-username-btn">
                    <i class="bi bi-check-lg me-1"></i>Save Username
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Change Password Modal -->
<div class="modal fade" id="changePasswordModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" style="color:#c9a227;">
                    <i class="bi bi-key-fill me-2"></i>Change Password
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="pw-modal-error" class="alert alert-danger d-none mb-3"></div>
                <div id="pw-modal-success" class="alert alert-success d-none mb-3"></div>
                <div class="mb-3">
                    <label class="form-label" style="color:#e8e8e8;">Current Password</label>
                    <input type="password" class="form-control" id="current-password-input"
                           placeholder="Your current password" autocomplete="current-password">
                </div>
                <div class="mb-3">
                    <label class="form-label" style="color:#e8e8e8;">New Password</label>
                    <input type="password" class="form-control" id="new-password-input"
                           placeholder="8–32 characters" minlength="8" maxlength="32" autocomplete="new-password">
                </div>
                <div class="mb-0">
                    <label class="form-label" style="color:#e8e8e8;">Confirm New Password</label>
                    <input type="password" class="form-control" id="confirm-password-input"
                           placeholder="Type new password again" minlength="8" maxlength="32" autocomplete="new-password">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-warning" id="save-password-btn">
                    <i class="bi bi-key me-1"></i>Update Password
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Edit Email Modal -->
<div class="modal fade" id="editEmailModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" style="color:#c9a227;">
                    <i class="bi bi-envelope me-2"></i>Update Email Address
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="email-modal-error" class="alert alert-danger d-none mb-3"></div>
                <div class="mb-3">
                    <label class="form-label" style="color:#e8e8e8;">New Email Address</label>
                    <input type="email" class="form-control" id="email-input"
                           placeholder="your@email.com"
                           value="<?= htmlspecialchars($email === 'Not provided' ? '' : $email) ?>">
                    <div class="form-text" style="color:#8899aa;">
                        Email is required. Each address can only be used by one account.
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="save-email-btn">
                    <i class="bi bi-check-lg me-1"></i>Save Email
                </button>
            </div>
        </div>
    </div>
</div>

<script>
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


// ── Deck Export management on profile ────────────────────────────────────────
document.querySelectorAll('.copy-export-code-btn').forEach(btn => {
    btn.addEventListener('click', function () {
        navigator.clipboard.writeText(this.dataset.code).then(() => {
            const orig = this.innerHTML;
            this.innerHTML = '<i class="bi bi-clipboard-check"></i>';
            setTimeout(() => { this.innerHTML = orig; }, 2000);
        });
    });
});

document.querySelectorAll('.delete-export-btn').forEach(btn => {
    btn.addEventListener('click', async function () {
        const code = this.dataset.code;
        const deck = this.dataset.deck;
        if (!confirm('Delete the export code ' + code + ' for "' + deck + '"?\nAnyone using this code will no longer be able to import the deck.')) return;

        const fd = new FormData();
        fd.append('export_code', code);
        try {
            const res  = await fetch('ajax/delete_export.php', { method: 'POST', body: fd });
            const data = await res.json();
            if (data.success) {
                this.closest('li').remove();
                showToast('Export code <strong>' + code + '</strong> deleted', 'danger');
                // If list is now empty, reload to show empty state
                if (document.querySelectorAll('.delete-export-btn').length === 0) {
                    location.reload();
                }
            } else {
                showToast(data.error || 'Delete failed', 'danger');
            }
        } catch (_) {
            showToast('Network error', 'danger');
        }
    });
});
// ─────────────────────────────────────────────────────────────────────────────
// Auto-lowercase username modal input as user types
document.getElementById('username-input').addEventListener('input', function () {
    const pos = this.selectionStart;
    this.value = this.value.toLowerCase();
    this.setSelectionRange(pos, pos);
});

document.getElementById('save-username-btn').addEventListener('click', async function () {
    const input    = document.getElementById('username-input');
    const errorDiv = document.getElementById('username-modal-error');
    // Enforce lowercase before sending
    input.value    = input.value.toLowerCase().trim();
    const newUser  = input.value;

    if (!newUser) {
        errorDiv.textContent = 'Username cannot be empty.';
        errorDiv.classList.remove('d-none');
        return;
    }
    if (!/^[a-z0-9_]+$/.test(newUser)) {
        errorDiv.textContent = 'Username may only contain lowercase letters, numbers, and underscores.';
        errorDiv.classList.remove('d-none');
        return;
    }
    errorDiv.classList.add('d-none');

    const btn = this;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Saving…';

    try {
        const fd = new FormData();
        fd.append('username', newUser);
        const res  = await fetch('ajax/update_username.php', { method: 'POST', body: fd });
        const data = await res.json();

        if (!data.success) {
            errorDiv.textContent = data.error;
            errorDiv.classList.remove('d-none');
        } else {
            document.getElementById('profile-username-display').textContent = data.username;
            document.getElementById('account-username-display').textContent = data.username;
            bootstrap.Modal.getInstance(document.getElementById('editUsernameModal')).hide();
            showToast('✅ Username updated to <strong>' + data.username + '</strong>', 'success');
        }
    } catch (_) {
        errorDiv.textContent = 'Network error — please try again.';
        errorDiv.classList.remove('d-none');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-check-lg me-1"></i>Save Username';
    }
});

document.getElementById('editUsernameModal').addEventListener('show.bs.modal', function () {
    document.getElementById('username-modal-error').classList.add('d-none');
});

// ── Change Password ─────────────────────────────────────────────────────────
document.getElementById('save-password-btn').addEventListener('click', async function () {
    const errorDiv   = document.getElementById('pw-modal-error');
    const successDiv = document.getElementById('pw-modal-success');
    const currentPw  = document.getElementById('current-password-input').value;
    const newPw      = document.getElementById('new-password-input').value;
    const confirmPw  = document.getElementById('confirm-password-input').value;

    errorDiv.classList.add('d-none');
    successDiv.classList.add('d-none');

    if (!currentPw || !newPw || !confirmPw) {
        errorDiv.textContent = 'All three fields are required.';
        errorDiv.classList.remove('d-none');
        return;
    }
    if (newPw !== confirmPw) {
        errorDiv.textContent = 'New password and confirmation do not match.';
        errorDiv.classList.remove('d-none');
        return;
    }
    if (newPw.length < 8 || newPw.length > 32) {
        errorDiv.textContent = 'Password must be between 8 and 32 characters.';
        errorDiv.classList.remove('d-none');
        return;
    }

    const btn = this;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Saving…';

    try {
        const fd = new FormData();
        fd.append('current_password', currentPw);
        fd.append('new_password',     newPw);
        fd.append('confirm_password', confirmPw);
        const res  = await fetch('ajax/change_password.php', { method: 'POST', body: fd });
        const data = await res.json();

        if (!data.success) {
            errorDiv.textContent = data.error;
            errorDiv.classList.remove('d-none');
        } else {
            successDiv.textContent = 'Password updated successfully!';
            successDiv.classList.remove('d-none');
            document.getElementById('current-password-input').value = '';
            document.getElementById('new-password-input').value = '';
            document.getElementById('confirm-password-input').value = '';
            setTimeout(() => {
                bootstrap.Modal.getInstance(document.getElementById('changePasswordModal')).hide();
                showToast('✅ Password changed successfully.', 'success');
            }, 1200);
        }
    } catch (_) {
        errorDiv.textContent = 'Network error — please try again.';
        errorDiv.classList.remove('d-none');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-key me-1"></i>Update Password';
    }
});

document.getElementById('changePasswordModal').addEventListener('hidden.bs.modal', function () {
    document.getElementById('pw-modal-error').classList.add('d-none');
    document.getElementById('pw-modal-success').classList.add('d-none');
    document.getElementById('current-password-input').value = '';
    document.getElementById('new-password-input').value = '';
    document.getElementById('confirm-password-input').value = '';
});

document.getElementById('save-email-btn').addEventListener('click', async function () {
    const input    = document.getElementById('email-input');
    const errorDiv = document.getElementById('email-modal-error');
    const newEmail = input.value.trim();

    // Email is mandatory — blank is not allowed
    if (newEmail === '') {
        errorDiv.textContent = 'Email is required and cannot be removed.';
        errorDiv.classList.remove('d-none');
        return;
    }
    if (!newEmail.match(/^[^\s@]+@[^\s@]+\.[a-zA-Z]{2,}$/)) {
        errorDiv.textContent = 'Please enter a valid email address.';
        errorDiv.classList.remove('d-none');
        return;
    }
    errorDiv.classList.add('d-none');

    const btn = this;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Saving…';

    try {
        const fd = new FormData();
        fd.append('email', newEmail);
        const res  = await fetch('ajax/update_email.php', { method: 'POST', body: fd });
        const data = await res.json();

        if (!data.success) {
            errorDiv.textContent = data.error;
            errorDiv.classList.remove('d-none');
        } else {
            // Update both display spots
            const display = data.email || 'Not provided';
            document.getElementById('profile-email-display').textContent = display;
            document.getElementById('account-email-display').textContent = display;

            bootstrap.Modal.getInstance(document.getElementById('editEmailModal')).hide();

            showToast(`✅ Email updated to <strong>${data.email}</strong>`, 'success');
        }
    } catch (_) {
        errorDiv.textContent = 'Network error — please try again.';
        errorDiv.classList.remove('d-none');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-check-lg me-1"></i>Save Email';
    }
});

// Clear error when modal is reopened
document.getElementById('editEmailModal').addEventListener('show.bs.modal', function () {
    document.getElementById('email-modal-error').classList.add('d-none');
});

// ── Delete Account ───────────────────────────────────────────────────────────
function updateDeleteBtn() {
    const checked = document.getElementById('delete-confirm-checkbox').checked;
    const typed   = document.getElementById('delete-confirm-input').value.trim();
    document.getElementById('confirm-delete-account-btn').disabled = !(checked && typed === 'I UNDERSTAND');
}
document.getElementById('delete-confirm-checkbox').addEventListener('change', updateDeleteBtn);
document.getElementById('delete-confirm-input').addEventListener('input', updateDeleteBtn);

document.getElementById('deleteAccountModal').addEventListener('hidden.bs.modal', function () {
    document.getElementById('delete-confirm-checkbox').checked = false;
    document.getElementById('delete-confirm-input').value = '';
    updateDeleteBtn();
});

document.getElementById('confirm-delete-account-btn').addEventListener('click', async function () {
    const btn = this;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Deleting…';

    try {
        const fd = new FormData();
        const res  = await fetch('actions/delete_account.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.success) {
            window.location.href = 'index.php?msg=account_deleted';
        } else {
            showToast(data.error || 'Delete failed. Please try again.', 'danger');
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-person-x-fill me-1"></i>Delete My Account';
        }
    } catch (_) {
        showToast('Network error — please try again.', 'danger');
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-person-x-fill me-1"></i>Delete My Account';
    }
});
// ─────────────────────────────────────────────────────────────────────────────
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>