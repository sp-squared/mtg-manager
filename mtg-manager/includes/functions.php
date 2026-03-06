<?php
/**
 * functions.php
 * Helper functions for MTG Collection Manager
 */

$_session_kicked = false; // set true when a concurrent login displaces this session

function isLoggedIn() {
    global $_session_kicked;
    if (!isset($_SESSION['user'], $_SESSION['id'], $_SESSION['session_token'])) {
        return false;
    }
    // Validate token against DB — mismatch means another login took over
    global $dbc;
    if (!$dbc || $dbc->connect_errno) return false;
    $stmt = $dbc->prepare("SELECT session_token FROM player WHERE id = ?");
    $stmt->bind_param("i", $_SESSION['id']);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row || !hash_equals((string)$row['session_token'], (string)$_SESSION['session_token'])) {
        $_session_kicked = true;
        session_unset();
        session_destroy();
        return false;
    }
    return true;
}

function getCurrentUser() {
    return $_SESSION['user'] ?? '';
}

function getUserId() {
    return $_SESSION['id'] ?? 0;
}

/** First registered user (id=1) is the admin */
function isAdmin() {
    return isset($_SESSION['id']) && (int)$_SESSION['id'] === 1;
}

// ── CSRF ──────────────────────────────────────────────────────────────────────
function generateCsrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrfToken(string $token): bool {
    if (empty($_SESSION['csrf_token'])) return false;
    return hash_equals($_SESSION['csrf_token'], $token);
}

function requireCsrf(): void {
    $token = $_POST['csrf_token'] ?? '';
    if (!verifyCsrfToken($token)) {
        http_response_code(403);
        // Detect AJAX: fetch() sets Accept: */* but Content-Type header hints at AJAX,
        // or we check if the response is expected to be JSON by the calling file.
        // Safest: if no explicit HTML Accept, return JSON; HTML form endpoints
        // always send redirect anyway so checking for non-html accept works.
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        $is_ajax = !str_contains($accept, 'text/html')
                   || !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
                   || str_contains($accept, 'application/json');
        if ($is_ajax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Session expired. Please reload the page.']);
        } else {
            $_base = defined('APP_BASE') ? APP_BASE : '';
        header("Location: {$_base}/index.php?error=Your+session+expired.+Please+try+again.");
        }
        exit;
    }
}

// ── Login Rate Limiting ───────────────────────────────────────────────────────
define('MAX_LOGIN_ATTEMPTS',    5);
define('LOCKOUT_MINUTES',       1);   // how long account is locked
define('ATTEMPT_HISTORY_MINUTES', 15);  // how far back admin log looks

/**
 * Returns minutes remaining in lockout, or 0 if not locked.
 * Uses MySQL NOW() for all time comparisons to avoid PHP/MySQL timezone mismatches.
 */
function getLoginLockoutMinutes(mysqli $dbc, string $username): int {
    $minutes = LOCKOUT_MINUTES;
    $max     = MAX_LOGIN_ATTEMPTS;

    $stmt = $dbc->prepare(
        "SELECT COUNT(*) as cnt, MIN(attempted_at) as oldest
         FROM login_attempts
         WHERE username = ?
           AND attempted_at > NOW() - INTERVAL ? MINUTE"
    );
    $stmt->bind_param("si", $username, $minutes);
    $stmt->execute();
    $row   = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $count = (int)$row['cnt'];
    if ($count < $max) return 0;

    // Calculate minutes until the oldest attempt rolls out of the window
    $unlock_at = strtotime($row['oldest']) + ($minutes * 60);
    return max(1, (int)ceil(($unlock_at - time()) / 60));
}

/**
 * Returns how many attempts remain before lockout (0 = already locked).
 * Uses MySQL NOW() for the window to stay in sync with recordFailedLogin().
 */
function getLoginAttemptsLeft(mysqli $dbc, string $username): int {
    $minutes = LOCKOUT_MINUTES;
    $max     = MAX_LOGIN_ATTEMPTS;

    $stmt = $dbc->prepare(
        "SELECT COUNT(*) as cnt
         FROM login_attempts
         WHERE username = ?
           AND attempted_at > NOW() - INTERVAL ? MINUTE"
    );
    $stmt->bind_param("si", $username, $minutes);
    $stmt->execute();
    $count = (int)$stmt->get_result()->fetch_assoc()['cnt'];
    $stmt->close();
    return max(0, $max - $count);
}

function recordFailedLogin(mysqli $dbc, string $username): void {
    $stmt = $dbc->prepare(
        "INSERT INTO login_attempts (username, attempted_at, event_type) VALUES (?, NOW(), 'failed')"
    );
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $stmt->close();
}

/**
 * Record that a user submitted the correct password while their account was
 * locked out — indicates a possible third-party lockout attack in progress.
 */
function recordBypassedLockout(mysqli $dbc, string $username): void {
    $stmt = $dbc->prepare(
        "INSERT INTO login_attempts (username, attempted_at, event_type) VALUES (?, NOW(), 'bypassed')"
    );
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $stmt->close();
}

/**
 * Clear only the active lockout window attempts (preserves history rows).
 * Called on successful login so the counter resets without wiping history.
 */
function clearLoginAttempts(mysqli $dbc, string $username): void {
    $minutes = LOCKOUT_MINUTES;
    $stmt = $dbc->prepare(
        "DELETE FROM login_attempts
         WHERE username = ?
           AND attempted_at > NOW() - INTERVAL ? MINUTE"
    );
    $stmt->bind_param("si", $username, $minutes);
    $stmt->execute();
    $stmt->close();
}

/**
 * Wipe ALL attempts for a username (admin manual unlock).
 */
function clearAllLoginAttempts(mysqli $dbc, string $username): void {
    $stmt = $dbc->prepare("DELETE FROM login_attempts WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $stmt->close();
}

/**
 * Returns true if username currently has an active lockout.
 */
function isLockedOut(mysqli $dbc, string $username): bool {
    return getLoginLockoutMinutes($dbc, $username) > 0;
}

// ── Other Helpers ─────────────────────────────────────────────────────────────
function redirectWithMessage($url, $msg, $type = 'success') {
    $separator = (strpos($url, '?') === false) ? '?' : '&';
    header("Location: $url{$separator}msg=$msg");
    exit;
}

function getPriorityLabel($priority) {
    $labels = [
        1 => 'Low (can wait)',
        2 => 'Medium',
        3 => 'High (want really bad)'
    ];
    return $labels[$priority] ?? 'Unknown';
}

function renderFlashMessage() {
    if (isset($_SESSION['flash'])) {
        $type = $_SESSION['flash']['type'] ?? 'success';
        $msg  = htmlspecialchars($_SESSION['flash']['message'] ?? '');
        unset($_SESSION['flash']);
        return "<div class='alert alert-$type alert-dismissible fade show' role='alert'>
                $msg
                <button type='button' class='btn-close' data-bs-dismiss='alert'></button>
                </div>";
    }
    return '';
}
