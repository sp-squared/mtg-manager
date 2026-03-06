<?php
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

if (!$deck_id) {
    header("Location: ../decks.php");
    exit();
}

// Verify deck ownership before clearing
$check = $dbc->prepare("SELECT id FROM decks WHERE id = ? AND user_id = ?");
$check->bind_param("ii", $deck_id, $user_id);
$check->execute();
$check->store_result();
if ($check->num_rows === 0) {
    header("Location: ../decks.php?error=Deck not found");
    exit();
}
$check->close();

// Delete all cards from deck
$stmt = $dbc->prepare("DELETE FROM deck_cards WHERE deck_id = ?");
$stmt->bind_param("i", $deck_id);
$stmt->execute();
$stmt->close();

// Update deck updated_at timestamp
$update = $dbc->prepare("UPDATE decks SET updated_at = NOW() WHERE id = ?");
$update->bind_param("i", $deck_id);
$update->execute();
$update->close();

$dbc->close();
header("Location: ../deck_editor.php?deck_id=$deck_id&msg=cleared");
exit();
?>
