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
$deck_id = (int)($_POST['deck_id'] ?? 0);
$name = trim($_POST['name'] ?? '');
$description = trim($_POST['description'] ?? '');

if ($deck_id <= 0 || empty($name)) {
    ob_end_clean(); echo json_encode(['error' => 'Invalid deck name or ID']);
    exit;
}

// Verify ownership
$stmt = $dbc->prepare("SELECT id FROM decks WHERE id = ? AND user_id = ?");
$stmt->bind_param("ii", $deck_id, $user_id);
$stmt->execute();
$stmt->store_result();
if ($stmt->num_rows == 0) {
    ob_end_clean(); echo json_encode(['error' => 'Deck not found']);
    exit;
}
$stmt->close();

// Update
$stmt = $dbc->prepare("UPDATE decks SET name = ?, description = ? WHERE id = ? AND user_id = ?");
$stmt->bind_param("ssii", $name, $description, $deck_id, $user_id);
if ($stmt->execute()) {
    ob_end_clean(); echo json_encode(['success' => true, 'name' => $name, 'description' => $description]);
} else {
    ob_end_clean(); echo json_encode(['error' => 'Update failed']);
}
$stmt->close();
$dbc->close();