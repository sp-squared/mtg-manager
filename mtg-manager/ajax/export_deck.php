<?php
ini_set('display_errors', 0);
ob_start();
session_start();
include __DIR__ . '/../includes/connect.php';
include __DIR__ . '/../includes/functions.php';
header('Content-Type: application/json');

if (!isLoggedIn()) {
    ob_end_clean(); echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit();
}
requireCsrf();


$user_id = getUserId();
$deck_id = (int)($_POST['deck_id'] ?? 0);

if (!$deck_id) {
    ob_end_clean(); echo json_encode(['success' => false, 'error' => 'Invalid deck']);
    exit();
}

// Verify ownership
$s = $dbc->prepare("SELECT id, name, description FROM decks WHERE id = ? AND user_id = ?");
$s->bind_param("ii", $deck_id, $user_id);
$s->execute();
$deck = $s->get_result()->fetch_assoc();
$s->close();

if (!$deck) {
    ob_end_clean(); echo json_encode(['success' => false, 'error' => 'Deck not found']);
    exit();
}

// Check deck has cards
$count_s = $dbc->prepare("SELECT COUNT(*) as cnt FROM deck_cards WHERE deck_id = ?");
$count_s->bind_param("i", $deck_id);
$count_s->execute();
$cnt = (int)$count_s->get_result()->fetch_assoc()['cnt'];
$count_s->close();

if ($cnt === 0) {
    ob_end_clean(); echo json_encode(['success' => false, 'error' => 'Cannot export an empty deck']);
    exit();
}

// Snapshot card data
$card_s = $dbc->prepare(
    "SELECT dc.card_id, dc.quantity, dc.is_sideboard, c.name, c.mana_cost, c.type_line, c.rarity
     FROM deck_cards dc
     JOIN cards c ON dc.card_id = c.id
     WHERE dc.deck_id = ?
     ORDER BY dc.is_sideboard, c.name"
);
$card_s->bind_param("i", $deck_id);
$card_s->execute();
$cards = $card_s->get_result()->fetch_all(MYSQLI_ASSOC);
$card_s->close();

// Generate unique 12-char code: MTG-XXXXXXXX
function generateCode($dbc) {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // no ambiguous 0/O/1/I
    do {
        $code = 'MTG-';
        for ($i = 0; $i < 8; $i++) {
            $code .= $chars[random_int(0, strlen($chars) - 1)];
        }
        $check = $dbc->prepare("SELECT id FROM deck_exports WHERE export_code = ?");
        $check->bind_param("s", $code);
        $check->execute();
        $check->store_result();
        $exists = $check->num_rows > 0;
        $check->close();
    } while ($exists);
    return $code;
}

$code      = generateCode($dbc);
$card_json = json_encode($cards);
$name      = $deck['name'];
$desc      = $deck['description'] ?? '';

// Optional expiry: "1d","7d","30d","never"
$expires_in = $_POST['expires_in'] ?? 'never';
$expires_at = null;
$expires_map = ['1d' => '+1 day', '7d' => '+7 days', '30d' => '+30 days'];
if (isset($expires_map[$expires_in])) {
    $expires_at = date('Y-m-d H:i:s', strtotime($expires_map[$expires_in]));
}

$ins = $dbc->prepare(
    "INSERT INTO deck_exports (export_code, owner_id, deck_name, description, card_data, expires_at)
     VALUES (?, ?, ?, ?, ?, ?)"
);
$ins->bind_param("sissss", $code, $user_id, $name, $desc, $card_json, $expires_at);
$ins->execute();
$ins->close();
$dbc->close();

ob_end_clean(); echo json_encode(['success' => true, 'code' => $code, 'deck_name' => $name, 'expires_at' => $expires_at]);
?>
