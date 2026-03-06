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
$deck_id = (int)($_POST['deck_id'] ?? 0);

if ($deck_id <= 0) {
    ob_end_clean(); echo json_encode(['error' => 'Invalid deck']);
    exit;
}

// Verify ownership and get current favorite status
$stmt = $dbc->prepare("SELECT is_favorite FROM decks WHERE id = ? AND user_id = ?");
$stmt->bind_param("ii", $deck_id, $user_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows == 0) {
    ob_end_clean(); echo json_encode(['error' => 'Deck not found']);
    exit;
}
$row = $result->fetch_assoc();
$current = (int)$row['is_favorite'];
$new = $current ? 0 : 1;

// Enforce hard cap of 18 favorites atomically — single UPDATE with subquery
// prevents race condition where two simultaneous requests both pass the count check
if ($new === 1) {
    $stmt = $dbc->prepare(
        "UPDATE decks SET is_favorite = 1
         WHERE id = ? AND user_id = ?
           AND (SELECT COUNT(*) FROM (
                   SELECT id FROM decks WHERE user_id = ? AND is_favorite = 1
               ) AS sub) < 18"
    );
    $stmt->bind_param("iii", $deck_id, $user_id, $user_id);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();

    if ($affected === 0) {
        $cap_stmt = $dbc->prepare("SELECT COUNT(*) as cnt FROM decks WHERE user_id = ? AND is_favorite = 1");
        $cap_stmt->bind_param("i", $user_id);
        $cap_stmt->execute();
        $cap_count = (int)$cap_stmt->get_result()->fetch_assoc()['cnt'];
        $cap_stmt->close();
        if ($cap_count >= 18) {
            ob_end_clean(); echo json_encode(['error' => 'Favorite limit reached (max 18). Remove a favorite first.', 'at_limit' => true]);
        } else {
            ob_end_clean(); echo json_encode(['error' => 'Update failed']);
        }
        exit();
    }
    ob_end_clean(); echo json_encode(['success' => true, 'is_favorite' => 1]);
} else {
    $stmt = $dbc->prepare("UPDATE decks SET is_favorite = 0 WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $deck_id, $user_id);
    $stmt->execute();
    $stmt->close();
    ob_end_clean(); echo json_encode(['success' => true, 'is_favorite' => 0]);
}
$dbc->close();