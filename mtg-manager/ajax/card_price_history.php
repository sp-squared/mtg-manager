<?php
/**
 * card_price_history.php
 * Returns price history for a card as JSON.
 *
 * GET params:
 *   card_id  — Scryfall UUID
 *   days     — number of days of history (default 30, max 365)
 */

header('Content-Type: application/json');

include __DIR__ . '/../includes/connect.php';

$card_id = trim($_GET['card_id'] ?? '');
$days    = min(365, max(1, (int)($_GET['days'] ?? 30)));

// Validate UUID format
if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $card_id)) {
    echo json_encode(['error' => 'Invalid card_id']);
    exit;
}

// Check that card_price_history table exists — if not, return empty gracefully
$table_check = $dbc->query("SHOW TABLES LIKE 'card_price_history'");
if (!$table_check || $table_check->num_rows === 0) {
    echo json_encode(['history' => [], 'current' => null]);
    exit;
}

// Fetch history for the requested window
$stmt = $dbc->prepare("
    SELECT recorded_date, price_usd, price_usd_foil, price_eur, price_eur_foil, price_tix
    FROM card_price_history
    WHERE card_id = ?
      AND recorded_date >= CURDATE() - INTERVAL ? DAY
    ORDER BY recorded_date ASC
");
$stmt->bind_param("si", $card_id, $days);
$stmt->execute();
$result = $stmt->get_result();
$history = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Fetch latest price from card_prices
$cur_stmt = $dbc->prepare("
    SELECT price_usd, price_usd_foil, price_eur, price_eur_foil, price_tix, updated_at
    FROM card_prices
    WHERE card_id = ?
");
$cur_stmt->bind_param("s", $card_id);
$cur_stmt->execute();
$current = $cur_stmt->get_result()->fetch_assoc();
$cur_stmt->close();

$dbc->close();

echo json_encode([
    'history' => $history,
    'current' => $current,
]);
