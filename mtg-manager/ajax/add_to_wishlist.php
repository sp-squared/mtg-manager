<?php
ini_set('display_errors', 0);
ob_start();
session_start();
require_once __DIR__ . '/../includes/connect.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    http_response_code(401);
    ob_end_clean(); echo json_encode(['error' => 'Not logged in']);
    exit;
}
requireCsrf();


$user_id = getUserId();
$card_id = $_POST['card_id'] ?? '';
$priority = (int)($_POST['priority'] ?? 3);

// Verify card exists
$check = $dbc->prepare("SELECT id FROM cards WHERE id = ?");
$check->bind_param("s", $card_id);
$check->execute();
$check->store_result();
if ($check->num_rows == 0) {
    ob_end_clean(); echo json_encode(['error' => 'Invalid card']);
    exit;
}
$check->close();

// Insert or update
$stmt = $dbc->prepare("INSERT INTO wishlist (user_id, card_id, priority) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE priority = VALUES(priority)");
$stmt->bind_param("isi", $user_id, $card_id, $priority);
$stmt->execute();

ob_end_clean(); echo json_encode(['success' => true, 'message' => 'Card added to wishlist']);
exit;