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

$user_id  = getUserId();
$deck_id  = (int)($_POST['deck_id']  ?? 0);
$card_id  = trim($_POST['card_id']   ?? '');
$quantity = max(1, (int)($_POST['quantity'] ?? 1));

if (!$deck_id || !$card_id) {
    ob_end_clean(); echo json_encode(['success' => false, 'error' => 'Missing deck or card.']);
    exit();
}

// Verify deck belongs to this user
$own = $dbc->prepare("SELECT name FROM decks WHERE id = ? AND user_id = ?");
$own->bind_param("ii", $deck_id, $user_id);
$own->execute();
$deck = $own->get_result()->fetch_assoc();
$own->close();

if (!$deck) {
    ob_end_clean(); echo json_encode(['success' => false, 'error' => 'Deck not found.']);
    exit();
}

// Verify card exists
$chk = $dbc->prepare("SELECT name FROM cards WHERE id = ?");
$chk->bind_param("s", $card_id);
$chk->execute();
$card = $chk->get_result()->fetch_assoc();
$chk->close();

if (!$card) {
    ob_end_clean(); echo json_encode(['success' => false, 'error' => 'Card not found.']);
    exit();
}

// Insert or increment — no collection ownership check (search → deck flow)
$ins = $dbc->prepare(
    "INSERT INTO deck_cards (deck_id, card_id, quantity)
     VALUES (?, ?, ?)
     ON DUPLICATE KEY UPDATE quantity = quantity + ?"
);
$ins->bind_param("isii", $deck_id, $card_id, $quantity, $quantity);
$ins->execute();
$ins->close();

// Touch the deck's updated_at
$upd = $dbc->prepare("UPDATE decks SET updated_at = NOW() WHERE id = ?");
$upd->bind_param("i", $deck_id);
$upd->execute();
$upd->close();

$dbc->close();
ob_end_clean(); echo json_encode([
    'success'   => true,
    'card_name' => $card['name'],
    'deck_name' => $deck['name'],
    'quantity'  => $quantity,
]);
?>
