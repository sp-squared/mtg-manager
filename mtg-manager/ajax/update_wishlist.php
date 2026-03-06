<?php
ini_set('display_errors', 0);
ob_start();
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
$card_id = $_POST['card_id'] ?? '';
$priority = (int)($_POST['priority'] ?? 3);

$stmt = $dbc->prepare("UPDATE wishlist SET priority = ? WHERE user_id = ? AND card_id = ?");
$stmt->bind_param("iis", $priority, $user_id, $card_id);
$stmt->execute();

// Return JSON for AJAX requests, redirect otherwise
if (!empty($_POST['ajax'])) {
    header('Content-Type: application/json');
    ob_end_clean(); echo json_encode(['success' => true]);
    exit();
}

header("Location: ../wishlist.php?msg=updated");
exit();
?>