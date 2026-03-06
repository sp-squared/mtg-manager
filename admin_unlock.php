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
if (!isAdmin()) {
    header("Location: dashboard.php?error=Access+denied.");
    exit();
}

include 'connect.php';

$lockout_minutes = LOCKOUT_MINUTES;
$history_minutes = ATTEMPT_HISTORY_MINUTES;
$max_attempts    = MAX_LOGIN_ATTEMPTS;

// Prune rows older than the history window so tables stay in sync
$prune = $dbc->prepare(
    "DELETE FROM login_attempts WHERE attempted_at <= NOW() - INTERVAL ? MINUTE"
);
$prune->bind_param("i", $history_minutes);
$prune->execute();
$prune->close();

// ── Currently locked: >= MAX_LOGIN_ATTEMPTS within the lockout window ─────────
$locked_stmt = $dbc->prepare(
    "SELECT username,
            COUNT(*) as attempts,
            MIN(attempted_at) as first_attempt,
            MAX(attempted_at) as last_attempt
     FROM login_attempts
     WHERE attempted_at > NOW() - INTERVAL ? MINUTE
     GROUP BY username
     HAVING attempts >= ?
     ORDER BY last_attempt DESC"
);
$locked_stmt->bind_param("ii", $lockout_minutes, $max_attempts);
$locked_stmt->execute();
$locked_accounts = $locked_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$locked_stmt->close();

// ── Recent history: all activity in the past ATTEMPT_HISTORY_MINUTES ─────────
// For each username in history, also check if they are currently locked
$history_stmt = $dbc->prepare(
    "SELECT h.username,
            h.attempts,
            h.first_attempt,
            h.last_attempt,
            COALESCE(l.locked_attempts, 0) >= ? AS is_locked,
            COALESCE(b.bypass_count, 0) > 0     AS has_bypass
     FROM (
         SELECT username,
                COUNT(*) as attempts,
                MIN(attempted_at) as first_attempt,
                MAX(attempted_at) as last_attempt
         FROM login_attempts
         WHERE attempted_at > NOW() - INTERVAL ? MINUTE
         GROUP BY username
     ) h
     LEFT JOIN (
         SELECT username, COUNT(*) as locked_attempts
         FROM login_attempts
         WHERE attempted_at > NOW() - INTERVAL ? MINUTE
           AND event_type = 'failed'
         GROUP BY username
     ) l ON h.username = l.username
     LEFT JOIN (
         SELECT username, COUNT(*) as bypass_count
         FROM login_attempts
         WHERE attempted_at > NOW() - INTERVAL ? MINUTE
           AND event_type = 'bypassed'
         GROUP BY username
     ) b ON h.username = b.username
     ORDER BY h.last_attempt DESC"
);
$history_stmt->bind_param("iiii", $max_attempts, $history_minutes, $lockout_minutes, $history_minutes);
$history_stmt->execute();
$history_accounts = $history_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$history_stmt->close();

// ── Recent attempt log (last 50 rows) ─────────────────────────────────────────
$log_stmt = $dbc->query(
    "SELECT username, attempted_at, event_type
     FROM login_attempts
     ORDER BY attempted_at DESC"
);
$recent_attempts = $log_stmt->fetch_all(MYSQLI_ASSOC);
$dbc->close();
?>

<div class="container my-4" style="max-width:860px;">
    <h1 class="mb-1" style="color:#c9a227;">
        <i class="bi bi-shield-lock-fill me-2"></i>Admin — Login Security
    </h1>
    <p class="mb-4 small" style="color:#8899aa;">
        Lockout: <strong style="color:#e8e8e8;"><?= MAX_LOGIN_ATTEMPTS ?> failed attempts</strong>
        locks an account for <strong style="color:#e8e8e8;"><?= LOCKOUT_MINUTES === 1 ? '1 minute' : LOCKOUT_MINUTES . ' minutes' ?></strong>.
        History window: <strong style="color:#e8e8e8;"><?= ATTEMPT_HISTORY_MINUTES ?> minutes</strong>.
        Only visible to the admin account.
    </p>

    <!-- Currently Locked -->
    <div class="card shadow-sm mb-4" style="border-top:4px solid #f97316;">
        <div class="card-body">
            <h5 style="color:#f97316;"><i class="bi bi-lock-fill me-2"></i>Currently Locked Accounts</h5>
            <?php if (empty($locked_accounts)): ?>
                <p class="mb-0 small" style="color:#8899aa;">No accounts are currently locked. ✓</p>
            <?php else: ?>
                <table class="table table-sm mb-0" style="color:#e8e8e8;">
                    <thead>
                        <tr style="color:#8899aa;font-size:0.8rem;">
                            <th>Username</th>
                            <th class="text-center">Attempts</th>
                            <th>Last Attempt</th>
                            <th>Unlocks At</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($locked_accounts as $acct):
                        $unlock_ts  = strtotime($acct['last_attempt']) + (LOCKOUT_MINUTES * 60);
                        $unlock_str = date('g:i:s a', $unlock_ts);
                        $remaining  = max(0, (int)ceil(($unlock_ts - time()) / 60));
                    ?>
                        <tr id="locked-row-<?= htmlspecialchars($acct['username']) ?>">
                            <td><strong><?= htmlspecialchars($acct['username']) ?></strong></td>
                            <td class="text-center">
                                <span class="badge bg-danger"><?= $acct['attempts'] ?></span>
                            </td>
                            <td class="small" style="color:#8899aa;">
                                <?= date('g:i:s a', strtotime($acct['last_attempt'])) ?>
                            </td>
                            <td class="small" style="color:#8899aa;">
                                <?= $unlock_str ?> (~<?= $remaining ?> min)
                            </td>
                            <td>
                                <button class="btn btn-sm btn-outline-warning unlock-btn"
                                        data-username="<?= htmlspecialchars($acct['username']) ?>">
                                    <i class="bi bi-unlock me-1"></i>Unlock
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <!-- Recent History -->
    <div class="card shadow-sm mb-4" style="border-top:4px solid #8899aa;">
        <div class="card-body">
            <h5 style="color:#aab4c8;"><i class="bi bi-clock-history me-2"></i>Recent Activity (last <?= ATTEMPT_HISTORY_MINUTES ?> minutes)</h5>
            <p class="small mb-3" style="color:#8899aa;">
                All accounts with failed attempts in the past <?= ATTEMPT_HISTORY_MINUTES ?> minutes.
            </p>
            <?php if (empty($history_accounts)): ?>
                <p class="mb-0 small" style="color:#8899aa;">No failed attempts in the past <?= ATTEMPT_HISTORY_MINUTES ?> minutes.</p>
            <?php else: ?>
                <table class="table table-sm mb-0" style="color:#e8e8e8;">
                    <thead>
                        <tr style="color:#8899aa;font-size:0.8rem;">
                            <th>Username</th>
                            <th class="text-center">Attempts (15 min)</th>
                            <th>Last Attempt</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($history_accounts as $ha): ?>
                        <tr style="<?= $ha['has_bypass'] ? 'background:rgba(234,179,8,0.07);' : '' ?>">
                            <td><?= htmlspecialchars($ha['username']) ?></td>
                            <td class="text-center">
                                <?php
                                $badge = $ha['is_locked'] ? 'bg-danger'
                                       : ($ha['has_bypass'] ? 'bg-warning text-dark' : 'bg-secondary');
                                ?>
                                <span class="badge <?= $badge ?>">
                                    <?= $ha['attempts'] ?>
                                </span>
                            </td>
                            <td class="small" style="color:#8899aa;">
                                <?= date('g:i:s a', strtotime($ha['last_attempt'])) ?>
                            </td>
                            <td class="small">
                                <?php if ($ha['is_locked']): ?>
                                    <span style="color:#f87171;">
                                        <i class="bi bi-lock-fill me-1"></i>Locked
                                    </span>
                                <?php elseif ($ha['has_bypass']): ?>
                                    <span style="color:#fbbf24;">
                                        <i class="bi bi-exclamation-triangle-fill me-1"></i>Logged in while locked
                                    </span>
                                <?php else: ?>
                                    <span style="color:#75b798;">
                                        <i class="bi bi-unlock me-1"></i>Unlocked — within <?= ATTEMPT_HISTORY_MINUTES ?>min window
                                    </span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <!-- Manual Unlock -->
    <div class="card shadow-sm mb-4" style="border-top:4px solid #c9a227;">
        <div class="card-body">
            <h5 style="color:#c9a227;"><i class="bi bi-key-fill me-2"></i>Manual Unlock</h5>
            <p class="small mb-3" style="color:#8899aa;">
                Enter a locked username to clear their lockout immediately.
            </p>
            <div id="manual-unlock-error" class="alert alert-danger d-none mb-3 py-2"></div>
            <div class="d-flex gap-2 align-items-end" style="max-width:400px;">
                <div class="flex-grow-1">
                    <label class="form-label small" style="color:#e8e8e8;">Username</label>
                    <input type="text" class="form-control" id="manual-username-input"
                           placeholder="e.g. voyager" autocomplete="off">
                </div>
                <button class="btn btn-warning" id="manual-unlock-btn">
                    <i class="bi bi-unlock me-1"></i>Unlock
                </button>
            </div>
        </div>
    </div>

    <!-- Recent Attempt Log -->
    <div class="card shadow-sm" style="border-top:4px solid #6ea8fe;">
        <div class="card-body">
            <h5 style="color:#6ea8fe;"><i class="bi bi-journal-text me-2"></i>Recent Failed Attempts</h5>
            <?php if (empty($recent_attempts)): ?>
                <p class="mb-0 small" style="color:#8899aa;">No failed attempts currently.</p>
            <?php else: ?>
                <div style="max-height:320px;overflow-y:auto;">
                    <table class="table table-sm mb-0" style="color:#e8e8e8;font-size:0.82rem;">
                        <thead>
                            <tr style="color:#8899aa;">
                                <th>Username</th>
                                <th>Time</th>
                                <th>Event</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($recent_attempts as $att): ?>
                            <tr <?= $att['event_type'] === 'bypassed' ? 'style="background:rgba(234,179,8,0.08);"' : '' ?>>
                                <td><?= htmlspecialchars($att['username']) ?></td>
                                <td style="color:#8899aa;"><?= $att['attempted_at'] ?></td>
                                <td>
                                <?php if ($att['event_type'] === 'bypassed'): ?>
                                    <span class="badge" style="background:#854d0e;color:#fef08a;" title="Correct password submitted while account was locked — possible third-party attack">
                                        <i class="bi bi-exclamation-triangle-fill me-1"></i>Bypassed Lockout
                                    </span>
                                <?php else: ?>
                                    <span style="color:#8899aa;font-size:0.8rem;">Failed</span>
                                <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
function showAdminToast(message, type = 'success') {
    const container = document.getElementById('toast-container');
    const el = document.createElement('div');
    el.className = `toast align-items-center text-white bg-${type} border-0`;
    el.setAttribute('role', 'alert');
    el.innerHTML = `<div class="d-flex">
        <div class="toast-body">${message}</div>
        <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
    </div>`;
    container.appendChild(el);
    new bootstrap.Toast(el, { delay: 4000 }).show();
    el.addEventListener('hidden.bs.toast', () => el.remove());
}

async function doUnlock(username) {
    const fd = new FormData();
    fd.append('username', username);
    try {
        const res  = await fetch('admin_unlock_action.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.success) {
            // Remove the row from Currently Locked table immediately
            const row = document.getElementById('locked-row-' + data.username);
            if (row) row.remove();
            showAdminToast('🔓 Lockout cleared for <strong>' + data.username + '</strong>', 'success');
            // Reload after short delay so all tables refresh
            setTimeout(() => location.reload(), 1500);
        } else {
            showAdminToast(data.error || 'Unlock failed.', 'danger');
        }
    } catch (_) {
        showAdminToast('Network error — please try again.', 'danger');
    }
}

// Unlock buttons in the locked accounts table
document.querySelectorAll('.unlock-btn').forEach(btn => {
    btn.addEventListener('click', function () {
        doUnlock(this.dataset.username);
    });
});

// Manual unlock button
document.getElementById('manual-unlock-btn').addEventListener('click', async function () {
    const input    = document.getElementById('manual-username-input');
    const errorDiv = document.getElementById('manual-unlock-error');
    const username = input.value.toLowerCase().trim();

    errorDiv.classList.add('d-none');

    if (!username) {
        errorDiv.textContent = 'Please enter a username.';
        errorDiv.classList.remove('d-none');
        return;
    }

    const btn = this;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Unlocking…';

    const fd = new FormData();
    fd.append('username', username);
    try {
        const res  = await fetch('admin_unlock_action.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.success) {
            input.value = '';
            showAdminToast('🔓 Lockout cleared for <strong>' + data.username + '</strong>', 'success');
            setTimeout(() => location.reload(), 1500);
        } else {
            errorDiv.textContent = data.error || 'Unlock failed.';
            errorDiv.classList.remove('d-none');
        }
    } catch (_) {
        errorDiv.textContent = 'Network error — please try again.';
        errorDiv.classList.remove('d-none');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-unlock me-1"></i>Unlock';
    }
});
</script>

<?php include 'footer.php'; ?>
