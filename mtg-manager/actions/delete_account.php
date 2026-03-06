<?php
ini_set('display_errors', 0);
session_start();
include __DIR__ . '/../includes/connect.php';
include __DIR__ . '/../includes/functions.php';
header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['error' => 'Not logged in']);
    exit();
}

requireCsrf();

$user_id = getUserId();

// Delete in dependency order
$steps = [
    // deck_cards references decks
    "DELETE dc FROM deck_cards dc JOIN decks d ON d.id = dc.deck_id WHERE d.user_id = ?",
    "DELETE FROM deck_exports WHERE owner_id = ?",
    "DELETE FROM decks WHERE user_id = ?",
    "DELETE FROM user_collection WHERE user_id = ?",
    "DELETE FROM wishlist WHERE user_id = ?",
    "DELETE FROM player WHERE id = ?",
];

foreach ($steps as $sql) {
    $stmt = $dbc->prepare($sql);
    if (!$stmt) {
        echo json_encode(['error' => 'Database error: ' . $dbc->error]);
        exit();
    }
    $stmt->bind_param("i", $user_id);
    if (!$stmt->execute()) {
        echo json_encode(['error' => 'Delete failed: ' . $stmt->error]);
        exit();
    }
    $stmt->close();
}

$dbc->close();

// Destroy session
session_unset();
session_destroy();

echo json_encode(['success' => true]);
