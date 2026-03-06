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
$email   = trim($_POST['email'] ?? '');

// Email is now mandatory — blank is not allowed
if ($email === '') {
    ob_end_clean(); echo json_encode(['success' => false, 'error' => 'Email is required and cannot be removed.']);
    exit();
}

// Format validation
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !preg_match('/\.([a-zA-Z]{2,})$/', explode('@', $email)[1])) {
    ob_end_clean(); echo json_encode(['success' => false, 'error' => 'Please enter a valid email address (e.g. you@example.com).']);
    exit();
}

// Uniqueness check — exclude current user
$check = $dbc->prepare("SELECT id FROM player WHERE email = ? AND id != ?");
$check->bind_param("si", $email, $user_id);
$check->execute();
$check->store_result();
if ($check->num_rows > 0) {
    ob_end_clean(); echo json_encode(['success' => false, 'error' => 'That email is already in use by another account.']);
    exit();
}
$check->close();

$stmt = $dbc->prepare("UPDATE player SET email = ? WHERE id = ?");
$stmt->bind_param("si", $email, $user_id);
$stmt->execute();
$stmt->close();
$dbc->close();

ob_end_clean(); echo json_encode(['success' => true, 'email' => $email]);
?>
