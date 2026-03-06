<?php
ini_set('display_errors', 0);
session_start();
require_once __DIR__ . '/../includes/connect.php';
require_once __DIR__ . '/../includes/functions.php';
header('Content-Type: application/json');

$q = trim($_GET['q'] ?? '');
if (strlen($q) < 2) { echo json_encode([]); exit(); }

$like = '%' . $q . '%';
$stmt = $dbc->prepare(
    "SELECT c.id, c.name, c.type_line, cp.price_usd
     FROM cards c
     LEFT JOIN card_prices cp ON cp.card_id = c.id
     WHERE c.name LIKE ?
       AND c.type_line NOT LIKE '%Token%'
     ORDER BY c.name ASC
     LIMIT 10"
);
$stmt->bind_param("s", $like);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$dbc->close();

echo json_encode($rows);
