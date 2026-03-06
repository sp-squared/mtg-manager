<?php
ini_set('display_errors', 0);
ob_start();
session_start();
include __DIR__ . '/../includes/connect.php';
include __DIR__ . '/../includes/functions.php';
header('Content-Type: application/json');

if (!isLoggedIn()) {
    ob_end_clean(); echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit();
}
requireCsrf();


$user_id = getUserId();
$new_username = strtolower(trim($_POST['username'] ?? ''));

if (empty($new_username)) {
    ob_end_clean(); echo json_encode(['success' => false, 'error' => 'Username cannot be empty.']);
    exit();
}

if (!preg_match('/^[a-zA-Z0-9_]+$/', $new_username)) {
    ob_end_clean(); echo json_encode(['success' => false, 'error' => 'Username may only contain letters, numbers, and underscores — no spaces.']);
    exit();
}

// Uniqueness check — exclude current user
$check = $dbc->prepare("SELECT id FROM player WHERE username = ? AND id != ?");
$check->bind_param("si", $new_username, $user_id);
$check->execute();
$check->store_result();
if ($check->num_rows > 0) {
    ob_end_clean(); echo json_encode(['success' => false, 'error' => 'That username is already taken.']);
    exit();
}
$check->close();

$stmt = $dbc->prepare("UPDATE player SET username = ? WHERE id = ?");
$stmt->bind_param("si", $new_username, $user_id);
$stmt->execute();
$stmt->close();
$dbc->close();

// Update session so navbar reflects the change immediately
$_SESSION['user'] = $new_username;

ob_end_clean(); echo json_encode(['success' => true, 'username' => $new_username]);
?>
