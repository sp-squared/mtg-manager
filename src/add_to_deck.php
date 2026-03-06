<?php
session_start();
include 'connect.php';
include 'functions.php';

if (!isLoggedIn()) {
    global $_session_kicked;
    $msg = $_session_kicked
        ? "You+were+signed+in+elsewhere.+This+session+has+ended."
        : "Please+log+in+to+continue.";
    header("Location: index.php?error=" . $msg);
    exit();
}
requireCsrf();

$user_id = getUserId();
$deck_id = (int)($_POST['deck_id'] ?? 0);
$card_id = $_POST['card_id'] ?? '';
$quantity = (int)($_POST['quantity'] ?? 1);

// Verify deck ownership
$stmt = $dbc->prepare("SELECT id FROM decks WHERE id = ? AND user_id = ?");
$stmt->bind_param("ii", $deck_id, $user_id);
$stmt->execute();
$stmt->store_result();
if ($stmt->num_rows == 0) {
    header("Location: decks.php?error=Invalid deck");
    exit();
}
$stmt->close();

// Check user owns enough copies (optional)
$check = $dbc->prepare("SELECT quantity FROM user_collection WHERE user_id = ? AND card_id = ?");
$check->bind_param("is", $user_id, $card_id);
$check->execute();
$check->bind_result($owned);
$check->fetch();
$check->close();
if ($owned < $quantity) {
    header("Location: deck_editor.php?deck_id=$deck_id&msg=error&details=Not enough copies");
    exit();
}

// Insert or update deck_cards (main deck, not sideboard)
$stmt = $dbc->prepare("INSERT INTO deck_cards (deck_id, card_id, quantity) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE quantity = quantity + ?");
$stmt->bind_param("isii", $deck_id, $card_id, $quantity, $quantity);
$stmt->execute();
$stmt->close();

// Update deck's updated_at timestamp
$update_deck = $dbc->prepare("UPDATE decks SET updated_at = NOW() WHERE id = ?");
$update_deck->bind_param("i", $deck_id);
$update_deck->execute();
$update_deck->close();

if (!empty($_POST['ajax'])) { header('Content-Type: application/json'); echo json_encode(['success'=>true,'msg'=>'added']); exit(); }
header("Location: deck_editor.php?deck_id=$deck_id&msg=added");
exit();
?>