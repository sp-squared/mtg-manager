<?php
ini_set('display_errors', 0);
ob_start();
include 'connect.php';
header('Content-Type: application/json');

$email = trim($_GET['email'] ?? '');
if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL) || !preg_match('/\.([a-zA-Z]{2,})$/', explode('@', $email)[1])) {
    ob_end_clean(); echo json_encode(['taken' => false]);
    exit();
}

// Optionally exclude current user when logged in (for profile update use)
$exclude_id = 0;
if (isset($_GET['exclude_id']) && is_numeric($_GET['exclude_id'])) {
    $exclude_id = (int)$_GET['exclude_id'];
}

$stmt = $dbc->prepare("SELECT id FROM player WHERE email = ? AND id != ?");
$stmt->bind_param("si", $email, $exclude_id);
$stmt->execute();
$stmt->store_result();
$taken = $stmt->num_rows > 0;
$stmt->close();
$dbc->close();

ob_end_clean(); echo json_encode(['taken' => $taken]);
?>
