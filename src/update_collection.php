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
$card_id = $_POST['card_id'] ?? '';
$quantity = (int)($_POST['quantity'] ?? 0);

if ($quantity <= 0) {
    // Remove card
    $stmt = $dbc->prepare("DELETE FROM user_collection WHERE user_id = ? AND card_id = ?");
    $stmt->bind_param("is", $user_id, $card_id);
    $stmt->execute();
    header("Location: collection.php?msg=removed");
} else {
    // Update quantity
    $stmt = $dbc->prepare("UPDATE user_collection SET quantity = ? WHERE user_id = ? AND card_id = ?");
    $stmt->bind_param("iis", $quantity, $user_id, $card_id);
    $stmt->execute();
    header("Location: collection.php?msg=updated");
}
exit();
?>