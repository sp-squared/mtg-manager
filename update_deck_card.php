<?php
session_start();
include 'connect.php';
include 'functions.php';
if (!isLoggedIn()) {
    global $_session_kicked;
    $msg = $_session_kicked ? "You+were+signed+in+elsewhere.+This+session+has+ended." : "Please+log+in+to+continue.";
    header("Location: index.php?error=" . $msg);
    exit();
}
requireCsrf();

$user_id = getUserId();
$deck_id = (int)($_POST['deck_id'] ?? 0);
$card_id = $_POST['card_id'] ?? '';
$quantity = (int)($_POST['quantity'] ?? 0);

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

if ($quantity <= 0) {
    // Remove if quantity zero
    $stmt = $dbc->prepare("DELETE FROM deck_cards WHERE deck_id = ? AND card_id = ?");
    $stmt->bind_param("is", $deck_id, $card_id);
    $stmt->execute();
    $msg = 'removed';
} else {
    // Update quantity
    $stmt = $dbc->prepare("UPDATE deck_cards SET quantity = ? WHERE deck_id = ? AND card_id = ?");
    $stmt->bind_param("iis", $quantity, $deck_id, $card_id);
    $stmt->execute();
    $msg = 'updated';
}

if (!empty($_POST['ajax'])) { header('Content-Type: application/json'); echo json_encode(['success'=>true,'msg'=>$msg]); exit(); }
header("Location: deck_editor.php?deck_id=$deck_id&msg=$msg");
exit();
?>