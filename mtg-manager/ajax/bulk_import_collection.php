<?php
ini_set('display_errors', 0);
ob_start();
session_start();
require_once __DIR__ . '/../includes/connect.php';
require_once __DIR__ . '/../includes/functions.php';
header('Content-Type: application/json');

if (!isLoggedIn()) {
    ob_end_clean(); echo json_encode(['error' => 'Not logged in']); exit();
}
requireCsrf();

$user_id = getUserId();
$raw = trim($_POST['list'] ?? '');

if ($raw === '') {
    ob_end_clean(); echo json_encode(['error' => 'No card list provided']); exit();
}

$lines = preg_split('/\r\n|\r|\n/', $raw);

$parsed   = []; // [['name' => ..., 'qty' => ...], ...]
$skipped_lines = 0;

foreach ($lines as $line) {
    $line = trim($line);
    if ($line === '' || str_starts_with($line, '//') || str_starts_with($line, '#')) continue;
    // Strip sideboard markers
    if (strtolower($line) === 'sideboard' || strtolower($line) === 'deck') continue;

    // Match optional quantity at start: "4 Card Name" or "4x Card Name"
    if (preg_match('/^(\d+)x?\s+(.+)$/i', $line, $m)) {
        $qty  = max(1, (int)$m[1]);
        $name = trim($m[2]);
    } else {
        $qty  = 1;
        $name = $line;
    }

    // Strip set/collector info that Arena appends: "Lightning Bolt (M10) 156"
    $name = preg_replace('/\s*\([^)]+\)\s*\d*$/', '', $name);
    $name = trim($name);

    if ($name === '') continue;
    $parsed[] = ['name' => $name, 'qty' => $qty];
}

$lines_parsed = count($parsed);
$found     = [];
$not_found = [];

// Look up each card by name (exact match, case-insensitive)
$lookup = $dbc->prepare("SELECT id, name, type_line FROM cards WHERE LOWER(name) = LOWER(?) LIMIT 1");
$insert = $dbc->prepare(
    "INSERT INTO user_collection (user_id, card_id, quantity, added_at)
     VALUES (?, ?, ?, NOW())
     ON DUPLICATE KEY UPDATE quantity = quantity + ?"
);

foreach ($parsed as $entry) {
    $lookup->bind_param("s", $entry['name']);
    $lookup->execute();
    $row = $lookup->get_result()->fetch_assoc();

    if (!$row) {
        $not_found[] = $entry['name'];
        continue;
    }

    $insert->bind_param("isii", $user_id, $row['id'], $entry['qty'], $entry['qty']);
    $insert->execute();

    $found[] = [
        'name'      => $row['name'],
        'type_line' => $row['type_line'],
        'quantity'  => $entry['qty'],
    ];
}

$lookup->close();
$insert->close();
$dbc->close();

ob_end_clean();
echo json_encode([
    'added'       => count($found),
    'skipped'     => count($not_found),
    'lines_parsed'=> $lines_parsed,
    'found'       => $found,
    'not_found'   => $not_found,
]);
