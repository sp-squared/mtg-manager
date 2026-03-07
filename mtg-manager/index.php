<?php
ob_start();
include __DIR__ . '/includes/header.php';
if (isLoggedIn()) {
    header("Location: dashboard.php");
    exit();
}
?>

<div class="container my-5" style="max-width: 480px;">
    <div class="text-center mb-4">
        <i class="bi bi-shield-fill fs-1" style="color:#c9a227;"></i>
        <h1 class="mt-2" style="color:#c9a227;">MTG Manager</h1>
        <p style="color:#8899aa;">Sign in to your collection</p>
    </div>

    <?php if (isset($_GET['msg']) && $_GET['msg'] === 'account_deleted'): ?>
        <div class="alert alert-info alert-dismissible fade show">
            Your account has been permanently deleted. We're sorry to see you go.
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if (isset($_GET['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show" id="login-error-alert">
            <span id="login-error-text"><?= htmlspecialchars($_GET['error']) ?></span>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php if (!empty($_GET['locked']) && !empty($_GET['lockout'])): ?>
        <script>
        // Live countdown on locked accounts
        (function() {
            let mins = parseInt(<?= (int)($_GET['lockout'] ?? 0) ?>, 10);
            const el = document.getElementById('login-error-text');
            if (!el || mins <= 0) return;
            function tick() {
                el.textContent = 'Account locked. Try again in ' + mins + (mins === 1 ? ' minute.' : ' minutes.');
                if (mins <= 0) {
                    el.textContent = 'Lockout expired — you may try again.';
                    document.getElementById('login-error-alert')
                        .classList.replace('alert-danger', 'alert-warning');
                    clearInterval(timer);
                }
                mins--;
            }
            tick();
            const timer = setInterval(tick, 60000);
        })();
        </script>
        <?php endif; ?>
    <?php endif; ?>
    <?php if (isset($_GET['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <?= htmlspecialchars($_GET['success']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="card shadow-sm">
        <div class="card-body p-4">
            <form action="actions/login.php" method="post">
                <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                <div class="mb-3">
                    <label class="form-label" style="color:#e8e8e8;">Username</label>
                    <input type="text" class="form-control" name="user"
                           placeholder="Your username" required autofocus
                           id="login-user-input" autocomplete="username">
                </div>
                <div class="mb-4">
                    <label class="form-label" style="color:#e8e8e8;">Password</label>
                    <input type="password" class="form-control" name="pass"
                           id="login-pass-input"
                           placeholder="Your password"
                           minlength="8" maxlength="32" required
                           autocomplete="current-password">
                    <div id="login-pass-hint" class="form-text mt-1" style="color:#8899aa;font-size:0.8rem;min-height:1.2em;"></div>
                </div>
                <button type="submit" name="create" value="Login"
                        class="btn btn-primary w-100">
                    <i class="bi bi-box-arrow-in-right me-2"></i>Log In
                </button>
            </form>
        </div>
        <div class="card-footer text-center" style="border-top:1px solid rgba(201,162,39,0.15);">
            <span style="color:#e8e8e8;">Don't have an account?</span>
            <a href="portal.php" class="ms-1" style="color:#c9a227;">Create one</a>
        </div>
    </div>
</div>

<script>
(function () {
    // Auto-lowercase username
    const userEl = document.getElementById('login-user-input');
    if (userEl) {
        userEl.addEventListener('input', function () {
            const pos = this.selectionStart;
            this.value = this.value.toLowerCase();
            this.setSelectionRange(pos, pos);
        });
    }

    // Password length hint — gentle, not blocking
    const passEl = document.getElementById('login-pass-input');
    const hint   = document.getElementById('login-pass-hint');
    if (passEl && hint) {
        passEl.addEventListener('input', function () {
            const len = this.value.length;
            if (len === 0) {
                hint.textContent = '';
                hint.style.color = '#8899aa';
            } else if (len < 8) {
                hint.textContent = `Password must be at least 8 characters (${len}/8).`;
                hint.style.color = '#f87171';
            } else {
                hint.textContent = '';
            }
        });
    }
})();
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
