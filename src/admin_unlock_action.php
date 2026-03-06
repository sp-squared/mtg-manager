<?php
ini_set('display_errors', 0);
ob_start();
session_start();
require_once 'connect.php';
require_once 'functions.php';
header('Content-Type: application/json');

if (!isLoggedIn() || !isAdmin()) {
    ob_end_clean(); echo json_encode(['success' => false, 'error' => 'Access denied.']);
    exit();
}

requireCsrf();

$username = strtolower(trim($_POST['username'] ?? ''));

if ($username === '') {
    ob_end_clean(); echo json_encode(['success' => false, 'error' => 'Username is required.']);
    exit();
}

// Verify the user actually exists
$check = $dbc->prepare("SELECT id FROM player WHERE username = ?");
$check->bind_param("s", $username);
$check->execute();
$check->store_result();
$exists = $check->num_rows > 0;
$check->close();

if (!$exists) {
    ob_end_clean(); echo json_encode(['success' => false, 'error' => "No account found with username \"$username\"."]);
    exit();
}

// Check if actually locked right now
if (!isLockedOut($dbc, $username)) {
    ob_end_clean(); echo json_encode(['success' => false, 'error' => "\"$username\" is not currently locked."]);
    exit();
}

// Clear all attempts (full admin wipe)
clearAllLoginAttempts($dbc, $username);
$dbc->close();

ob_end_clean(); echo json_encode(['success' => true, 'username' => $username]);
?>
