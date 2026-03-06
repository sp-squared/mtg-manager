<?php
session_start();
include "connect.php";
include "functions.php";

if (!isset($_POST['user'], $_POST['pass'])) {
    header("Location: index.php");
    exit();
}

// ── CSRF ──────────────────────────────────────────────────────────────────────
$csrf = $_POST['csrf_token'] ?? '';
if (!verifyCsrfToken($csrf)) {
    header("Location: index.php?error=Your+session+expired.+Please+try+again.");
    exit();
}

// ── Normalize input ───────────────────────────────────────────────────────────
$username = strtolower(trim($_POST['user']));
$password = $_POST['pass'];

if (empty($username)) {
    header("Location: index.php?error=Username+is+required");
    exit();
}
if (empty($password)) {
    header("Location: index.php?error=Password+is+required");
    exit();
}

// ── Look up account ───────────────────────────────────────────────────────────
$stmt = $dbc->prepare("SELECT id, username, password FROM player WHERE username = ?");
$stmt->bind_param("s", $username);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    $stmt->close();
    header("Location: index.php?error=No+account+found+with+that+username.");
    exit();
}

$row = $result->fetch_assoc();
$stmt->close();

// ── Password check BEFORE lockout ─────────────────────────────────────────────
// Correct password always wins — prevents malicious lockouts by third parties.
if (password_verify($password, $row['password'])) {
    $was_locked = isLockedOut($dbc, $username);

    if ($was_locked) {
        // Record bypass event but DO NOT clear attempts — rows stay visible in
        // admin panel for the full 15-min history window so the pattern is auditable.
        recordBypassedLockout($dbc, $username);
    } else {
        // Normal successful login — clear the lockout window attempts cleanly
        clearLoginAttempts($dbc, $username);
    }

    session_regenerate_id(true);

    // Generate a unique token and write it to the DB, invalidating any prior session
    $session_token = bin2hex(random_bytes(32));
    $tok_stmt = $dbc->prepare("UPDATE player SET session_token = ? WHERE id = ?");
    $tok_stmt->bind_param("si", $session_token, $row['id']);
    $tok_stmt->execute();
    $tok_stmt->close();

    $_SESSION['user']          = $row['username'];
    $_SESSION['id']            = $row['id'];
    $_SESSION['session_token'] = $session_token;
    header("Location: dashboard.php");
    exit();
}

// ── Wrong password — now check lockout ───────────────────────────────────────
$lockout = getLoginLockoutMinutes($dbc, $username);
if ($lockout > 0) {
    $min_word = $lockout === 1 ? 'minute' : 'minutes';
    header("Location: index.php?error=Account+locked.+Try+again+in+{$lockout}+{$min_word}.&locked=1&lockout={$lockout}");
    exit();
}

// ── Record failure and report remaining attempts ──────────────────────────────
recordFailedLogin($dbc, $username);
$left = getLoginAttemptsLeft($dbc, $username);

if ($left <= 0) {
    $min_word = LOCKOUT_MINUTES === 1 ? 'minute' : 'minutes';
    header("Location: index.php?error=Account+locked+for+" . LOCKOUT_MINUTES . "+{$min_word}+due+to+too+many+failed+attempts.&locked=1&lockout=" . LOCKOUT_MINUTES);
} else {
    $attempt_word = $left === 1 ? 'attempt' : 'attempts';
    header("Location: index.php?error=Incorrect+password.+{$left}+{$attempt_word}+remaining.");
}
exit();
?>
