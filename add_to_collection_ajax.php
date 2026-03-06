<?php
ini_set('display_errors', 0);
ob_start();
session_start();
require_once 'connect.php';
require_once 'functions.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    http_response_code(401);
    ob_end_clean(); echo json_encode(['error' => 'Not logged in']);
    exit;
}
requireCsrf();


$user_id = getUserId();
$card_id = $_POST['card_id'] ?? '';
$quantity = (int)($_POST['quantity'] ?? 1);
if ($quantity < 1) $quantity = 1;

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
$stmt = $dbc->prepare("INSERT INTO user_collection (user_id, card_id, quantity) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE quantity = quantity + ?");
$stmt->bind_param("isii", $user_id, $card_id, $quantity, $quantity);
$stmt->execute();

ob_end_clean(); echo json_encode(['success' => true, 'message' => 'Card added to collection']);
exit;