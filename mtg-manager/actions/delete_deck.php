<?php
session_start();
include __DIR__ . '/../includes/connect.php';
include __DIR__ . '/../includes/functions.php';
if (!isLoggedIn()) {
    global $_session_kicked;
    $msg = $_session_kicked ? "You+were+signed+in+elsewhere.+This+session+has+ended." : "Please+log+in+to+continue.";
    header("Location: ../index.php?error=" . $msg);
    exit();
}
requireCsrf();

$user_id = getUserId();
$deck_id = (int)($_POST['deck_id'] ?? 0);

// Verify ownership
$stmt = $dbc->prepare("SELECT id FROM decks WHERE id = ? AND user_id = ?");
$stmt->bind_param("ii", $deck_id, $user_id);
$stmt->execute();
$stmt->store_result();
if ($stmt->num_rows == 0) {
    header("Location: ../decks.php?error=Deck+not+found");
    exit();
}
$stmt->close();

// Decrement fork_count on the template this deck was forked from, if any
$ud = $dbc->prepare("SELECT template_id FROM user_decks WHERE deck_id = ?");
$ud->bind_param("i", $deck_id);
$ud->execute();
$ud_row = $ud->get_result()->fetch_assoc();
$ud->close();

if ($ud_row) {
    $dec = $dbc->prepare("UPDATE deck_templates SET fork_count = GREATEST(0, fork_count - 1) WHERE id = ?");
    $dec->bind_param("i", $ud_row['template_id']);
    $dec->execute();
    $dec->close();

    $del_ud = $dbc->prepare("DELETE FROM user_decks WHERE deck_id = ?");
    $del_ud->bind_param("i", $deck_id);
    $del_ud->execute();
    $del_ud->close();
}

// Delete deck (cascade removes deck_cards automatically)
$stmt = $dbc->prepare("DELETE FROM decks WHERE id = ? AND user_id = ?");
$stmt->bind_param("ii", $deck_id, $user_id);
$stmt->execute();
$stmt->close();
$dbc->close();

header("Location: ../decks.php?msg=deleted");
exit();
?>
