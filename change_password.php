<?php
ini_set('display_errors', 0);
ob_start();
session_start();
require_once 'connect.php';
require_once 'functions.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    ob_end_clean(); echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit();
}

requireCsrf();

$user_id      = getUserId();
$current_pass = $_POST['current_password'] ?? '';
$new_pass     = $_POST['new_password']     ?? '';
$confirm_pass = $_POST['confirm_password'] ?? '';

// ── Validation ────────────────────────────────────────────────────────────────
if (empty($current_pass) || empty($new_pass) || empty($confirm_pass)) {
    ob_end_clean(); echo json_encode(['success' => false, 'error' => 'All three fields are required.']);
    exit();
}

if ($new_pass !== $confirm_pass) {
    ob_end_clean(); echo json_encode(['success' => false, 'error' => 'New password and confirmation do not match.']);
    exit();
}

if (preg_match_all('/./su', $new_pass) < 8 || preg_match_all('/./su', $new_pass) > 32) {
    ob_end_clean(); echo json_encode(['success' => false, 'error' => 'Password must be between 8 and 32 characters.']);
    exit();
}

if ($current_pass === $new_pass) {
    ob_end_clean(); echo json_encode(['success' => false, 'error' => 'New password must be different from your current password.']);
    exit();
}

// ── Verify current password ───────────────────────────────────────────────────
$stmt = $dbc->prepare("SELECT password FROM player WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row || !password_verify($current_pass, $row['password'])) {
    ob_end_clean(); echo json_encode(['success' => false, 'error' => 'Current password is incorrect.']);
    exit();
}

// ── Hash and store new password ───────────────────────────────────────────────
$new_hash = password_hash($new_pass, PASSWORD_DEFAULT);
$upd = $dbc->prepare("UPDATE player SET password = ? WHERE id = ?");
$upd->bind_param("si", $new_hash, $user_id);
$upd->execute();
$upd->close();
$dbc->close();

ob_end_clean(); echo json_encode(['success' => true]);
?>
