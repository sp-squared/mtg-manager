<?php
include __DIR__ . '/includes/header.php';
include_once __DIR__ . '/includes/connect.php';

if (isLoggedIn()) {
    header("Location: dashboard.php");
    exit();
}
?>

<div class="container my-5" style="max-width: 480px;">
    <div class="text-center mb-4">
        <i class="bi bi-person-plus-fill fs-1" style="color:#c9a227;"></i>
        <h1 class="mt-2" style="color:#c9a227;">Create Account</h1>
        <p style="color:#8899aa;">Join the MTG Collection Manager</p>
    </div>

    <?php if (isset($_GET['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <?= htmlspecialchars($_GET['error']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if (isset($_GET['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <?= htmlspecialchars($_GET['success']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="card shadow-sm">
        <div class="card-body p-4">
            <form action="actions/register.php" method="post" id="register-form">
                <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                <div class="mb-3">
                    <label class="form-label" style="color:#e8e8e8;">Username</label>
                    <small style="color:#8899aa;">Letters, numbers, and underscores only — no spaces</small>
                    <input type="text" class="form-control" name="user" placeholder="e.g. voyager_21"
                           pattern="[a-zA-Z0-9_]+" title="Letters, numbers, and underscores only"
                           required autocomplete="username" id="user-input">
                    <div class="form-text" style="color:#8899aa;font-size:0.78rem;">
                        Automatically saved as lowercase. Letters, numbers, underscores only.
                    </div>
                    <div id="username-taken-msg" class="mt-1 small" style="color:#f87171;display:none;"></div>
                </div>
                <div class="mb-3">
                    <label class="form-label" style="color:#e8e8e8;">Email</label>
                    <small style="color:#8899aa;">Required — must be a valid email address (e.g. you@example.com)</small>
                    <input type="email" class="form-control" name="email" placeholder="you@example.com" required>
                    <div id="email-taken-msg" class="mt-1 small" style="color:#f87171;display:none;"></div>
                </div>
                <div class="mb-4">
                    <label class="form-label" style="color:#e8e8e8;">Password</label>
                    <input type="password" class="form-control" name="pass" id="pass-input"
                           placeholder="8–32 characters" minlength="8" maxlength="32" required autocomplete="new-password">
                    <div class="mt-2">
                        <div style="height:4px;border-radius:2px;background:rgba(255,255,255,0.1);overflow:hidden;">
                            <div id="pw-strength-bar" style="height:100%;width:0%;transition:width 0.3s,background 0.3s;border-radius:2px;"></div>
                        </div>
                        <div id="pw-strength-text" class="mt-1" style="font-size:0.75rem;color:#8899aa;">
                            Minimum 8 characters
                        </div>
                    </div>
                </div>
                <button type="button" class="btn btn-primary w-100" id="register-btn">
                    <i class="bi bi-person-check me-2"></i>Create Account
                </button>
            </form>
        </div>
        <div class="card-footer text-center" style="border-top:1px solid rgba(201,162,39,0.15);">
            <span style="color:#e8e8e8;">Already have an account?</span>
            <a href="index.php" class="ms-1" style="color:#c9a227;">Log in</a>
        </div>
    </div>
</div>

<!-- Password Confirmation Modal -->
<div class="modal fade" id="passwordModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header" style="border-bottom:1px solid rgba(201,162,39,0.2);">
                <h5 class="modal-title" style="color:#c9a227;">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i>Write Down Your Password
                </h5>
            </div>
            <div class="modal-body text-center py-4">
                <p style="color:#e8e8e8;">Your password cannot be recovered if lost. Please write it down somewhere safe before continuing.</p>
                <div class="my-4 p-3 rounded" style="background:rgba(201,162,39,0.1);border:1px solid rgba(201,162,39,0.3);">
                    <p class="mb-1" style="color:#8899aa;font-size:0.8rem;text-transform:uppercase;letter-spacing:0.05em;">Your password is</p>
                    <span id="modal-password-display" style="font-size:1.75rem;font-weight:700;color:#c9a227;letter-spacing:0.08em;word-break:break-all;"></span>
                </div>
                <p style="color:#8899aa;font-size:0.85rem;">Once your account is created, no one — not even the site administrator — can retrieve it for you.</p>
            </div>
            <div class="modal-footer" style="border-top:1px solid rgba(201,162,39,0.2);">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Go Back & Change It</button>
                <button type="button" class="btn btn-success" id="confirm-register-btn">
                    <i class="bi bi-check-lg me-1"></i>I've Written It Down — Create Account
                </button>
            </div>
        </div>
    </div>
</div>

<script>
// Auto-lowercase the username field as the user types
document.getElementById('user-input').addEventListener('input', function () {
    const pos = this.selectionStart;
    this.value = this.value.toLowerCase();
    this.setSelectionRange(pos, pos);
});

// Password strength indicator
document.getElementById('pass-input').addEventListener('input', function () {
    const pw  = this.value;
    const bar = document.getElementById('pw-strength-bar');
    const txt = document.getElementById('pw-strength-text');
    const len = pw.length;
    let score = 0;
    if (len >= 8)  score++;
    if (len >= 12) score++;
    if (/[A-Z]/.test(pw) && /[a-z]/.test(pw)) score++;
    if (/[0-9]/.test(pw)) score++;
    if (/[^A-Za-z0-9]/.test(pw)) score++;

    const levels = [
        { pct: '0%',   color: '',          label: '8–32 characters',         style: '#8899aa' },
        { pct: '25%',  color: '#f87171',   label: 'Weak',                   style: '#f87171' },
        { pct: '50%',  color: '#fbbf24',   label: 'Fair',                   style: '#fbbf24' },
        { pct: '65%',  color: '#fbbf24',   label: 'Good',                   style: '#fbbf24' },
        { pct: '82%',  color: '#75b798',   label: 'Strong',                 style: '#75b798' },
        { pct: '100%', color: '#4ade80',   label: 'Very strong',            style: '#4ade80' },
    ];
    const lvl = len === 0 ? levels[0] : levels[Math.min(score, 5)];
    bar.style.width      = lvl.pct;
    bar.style.background = lvl.color;
    txt.textContent      = lvl.label;
    txt.style.color      = lvl.style;
});

document.getElementById('register-btn').addEventListener('click', async function () {
    const form = document.getElementById('register-form');
    if (!form.reportValidity()) return;

    // Enforce lowercase before any checks
    const userInput   = document.getElementById('user-input');
    userInput.value   = userInput.value.toLowerCase().trim();

    const username    = userInput.value;
    const email       = form.querySelector('input[name="email"]').value.trim();
    const password    = document.getElementById('pass-input').value;
    const userErrDiv  = document.getElementById('username-taken-msg');
    const emailErrDiv = document.getElementById('email-taken-msg');

    userErrDiv.style.display  = 'none';
    emailErrDiv.style.display = 'none';

    // Password length (8–32 characters)
    if (password.length < 8 || password.length > 32) {
        document.getElementById('pw-strength-text').textContent = 'Password must be between 8 and 32 characters.';
        document.getElementById('pw-strength-text').style.color = '#f87171';
        document.getElementById('pass-input').focus();
        return;
    }

    // Client-side email format
    if (!email.match(/^[^\s@]+@[^\s@]+\.[a-zA-Z]{2,}$/)) {
        emailErrDiv.style.display = 'block';
        emailErrDiv.textContent   = 'Please enter a valid email address (e.g. you@example.com).';
        return;
    }

    // Live username check (sends lowercase)
    let userTaken = false, emailTaken = false;
    try {
        const ur = await fetch('ajax/check_username.php?user=' + encodeURIComponent(username));
        const ud = await ur.json();
        if (ud.taken) {
            userErrDiv.style.display = 'block';
            userErrDiv.textContent   = 'Username "' + username + '" is already taken.';
            userTaken = true;
        }
    } catch (_) {}

    // Live email check
    try {
        const er = await fetch('ajax/check_email.php?email=' + encodeURIComponent(email));
        const ed = await er.json();
        if (ed.taken) {
            emailErrDiv.style.display = 'block';
            emailErrDiv.textContent   = 'That email is already registered to an account.';
            emailTaken = true;
        }
    } catch (_) {}

    if (userTaken || emailTaken) return;

    document.getElementById('modal-password-display').textContent = password;
    new bootstrap.Modal(document.getElementById('passwordModal')).show();
});

document.getElementById('confirm-register-btn').addEventListener('click', function () {
    document.getElementById('register-form').submit();
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
