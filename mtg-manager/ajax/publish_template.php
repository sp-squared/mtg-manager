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
$format  = trim($_POST['format']  ?? '');

if (!$deck_id) {
    ob_end_clean(); echo json_encode(['success' => false, 'error' => 'Invalid deck']);
    exit();
}

// Verify ownership
$s = $dbc->prepare("SELECT name, description FROM decks WHERE id = ? AND user_id = ?");
$s->bind_param("ii", $deck_id, $user_id);
$s->execute();
$deck = $s->get_result()->fetch_assoc();
$s->close();

if (!$deck) {
    ob_end_clean(); echo json_encode(['success' => false, 'error' => 'Deck not found']);
    exit();
}

// Must have cards
$cnt_s = $dbc->prepare("SELECT COUNT(*) as cnt FROM deck_cards WHERE deck_id = ?");
$cnt_s->bind_param("i", $deck_id);
$cnt_s->execute();
$cnt = (int)$cnt_s->get_result()->fetch_assoc()['cnt'];
$cnt_s->close();

if ($cnt === 0) {
    ob_end_clean(); echo json_encode(['success' => false, 'error' => 'Cannot publish an empty deck']);
    exit();
}

// Snapshot card data (same shape as deck_exports)
$card_s = $dbc->prepare(
    "SELECT dc.card_id, dc.quantity, dc.zone, c.name, c.mana_cost, c.type_line, c.rarity
     FROM deck_cards dc
     JOIN cards c ON dc.card_id = c.id
     WHERE dc.deck_id = ?
     ORDER BY dc.zone, c.name"
);
$card_s->bind_param("i", $deck_id);
$card_s->execute();
$cards = $card_s->get_result()->fetch_all(MYSQLI_ASSOC);
$card_s->close();

$total_cards = (int)array_sum(array_column(
    array_filter($cards, fn($c) => $c['zone'] !== 'tokens'),
    'quantity'
));

// Generate unique TPL-XXXXXXXX code
function generateTemplateCode($dbc) {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    do {
        $code = 'TPL-';
        for ($i = 0; $i < 8; $i++) {
            $code .= $chars[random_int(0, strlen($chars) - 1)];
        }
        $chk = $dbc->prepare("SELECT id FROM deck_templates WHERE share_code = ?");
        $chk->bind_param("s", $code);
        $chk->execute();
        $chk->store_result();
        $exists = $chk->num_rows > 0;
        $chk->close();
    } while ($exists);
    return $code;
}

$share_code  = generateTemplateCode($dbc);
$card_json   = json_encode($cards);
$name        = $deck['name'];
$desc        = $deck['description'] ?? '';
$format_val  = $format ?: null;

$ins = $dbc->prepare(
    "INSERT INTO deck_templates
       (share_code, creator_user_id, name, description, format, card_data, total_cards)
     VALUES (?, ?, ?, ?, ?, ?, ?)"
);
$ins->bind_param("sissssi", $share_code, $user_id, $name, $desc, $format_val, $card_json, $total_cards);
$ins->execute();
$template_id = $dbc->insert_id;
$ins->close();
$dbc->close();

ob_end_clean();
echo json_encode(['success' => true, 'share_code' => $share_code, 'template_id' => $template_id]);
