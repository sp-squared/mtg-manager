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


$user_id     = getUserId();
$export_code = trim($_POST['export_code'] ?? '');

if (!$export_code) {
    ob_end_clean(); echo json_encode(['success' => false, 'error' => 'Missing code']);
    exit();
}

// Only allow deleting your own exports
$stmt = $dbc->prepare("DELETE FROM deck_exports WHERE export_code = ? AND owner_id = ?");
$stmt->bind_param("si", $export_code, $user_id);
$stmt->execute();

if ($stmt->affected_rows === 0) {
    ob_end_clean(); echo json_encode(['success' => false, 'error' => 'Export not found or not yours']);
    exit();
}

$stmt->close();
$dbc->close();
ob_end_clean(); echo json_encode(['success' => true]);
?>
