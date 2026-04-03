<?php
ini_set('display_errors', 0);
session_start();
include __DIR__ . '/../includes/connect.php';
include __DIR__ . '/../includes/functions.php';
header('Content-Type: application/json');

if (!isLoggedIn()) { echo json_encode(['ok' => false]); exit(); }

$user_id = getUserId();
$card_id = isset($_POST['card_id']) ? trim($_POST['card_id']) : '';
if (!$card_id) { echo json_encode(['ok' => false]); exit(); }

$stmt = $dbc->prepare(
    "INSERT INTO recently_viewed (user_id, card_id, viewed_at) VALUES (?, ?, NOW())
     ON DUPLICATE KEY UPDATE viewed_at = NOW()"
);
$stmt->bind_param("is", $user_id, $card_id);
$stmt->execute();
$stmt->close();
$dbc->close();

echo json_encode(['ok' => true]);
