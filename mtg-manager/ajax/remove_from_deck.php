<?php
ini_set('display_errors', 0);
ob_start();
session_start();
include __DIR__ . '/../includes/connect.php';
include __DIR__ . '/../includes/functions.php';

if (!isLoggedIn()) {
    global $_session_kicked;
    $msg = $_session_kicked
        ? "You+were+signed+in+elsewhere.+This+session+has+ended."
        : "Please+log+in+to+continue.";
    header("Location: ../index.php?error=" . $msg);
    exit();
}
requireCsrf();

$user_id = getUserId();
$deck_id = (int)($_POST['deck_id'] ?? 0);
$card_id = $_POST['card_id'] ?? '';

// Verify deck ownership
$stmt = $dbc->prepare("SELECT id FROM decks WHERE id = ? AND user_id = ?");
$stmt->bind_param("ii", $deck_id, $user_id);
$stmt->execute();
$stmt->store_result();
if ($stmt->num_rows == 0) {
    header("Location: ../decks.php?error=Invalid deck");
    exit();
}
$stmt->close();

// Delete the card from the deck (all copies)
$stmt = $dbc->prepare("DELETE FROM deck_cards WHERE deck_id = ? AND card_id = ?");
$stmt->bind_param("is", $deck_id, $card_id);
$stmt->execute();
$stmt->close();

// Update deck's updated_at timestamp
$update_deck = $dbc->prepare("UPDATE decks SET updated_at = NOW() WHERE id = ?");
$update_deck->bind_param("i", $deck_id);
$update_deck->execute();
$update_deck->close();

if (!empty($_POST['ajax'])) { header('Content-Type: application/json'); ob_end_clean(); echo json_encode(['success'=>true,'msg'=>'removed']); exit(); }
header("Location: ../deck_editor.php?deck_id=$deck_id&msg=removed");
exit();
?>