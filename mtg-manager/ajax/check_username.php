<?php
ini_set('display_errors', 0);
ob_start();
include __DIR__ . '/../includes/connect.php';
header('Content-Type: application/json');

$username = strtolower(trim($_GET['user'] ?? ''));
if (empty($username)) {
    ob_end_clean(); echo json_encode(['taken' => false]);
    exit();
}

$stmt = $dbc->prepare("SELECT id FROM player WHERE username = ?");
$stmt->bind_param("s", $username);
$stmt->execute();
$stmt->store_result();
$taken = $stmt->num_rows > 0;
$stmt->close();
$dbc->close();

ob_end_clean(); echo json_encode(['taken' => $taken]);
?>
