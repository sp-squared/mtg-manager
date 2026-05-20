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

$user_id     = getUserId();
$template_id = (int)($_POST['template_id'] ?? 0);

if (!$template_id) {
    ob_end_clean(); echo json_encode(['success' => false, 'error' => 'Invalid template']);
    exit();
}

// Fetch template
$s = $dbc->prepare("SELECT id, name, description, card_data FROM deck_templates WHERE id = ?");
$s->bind_param("i", $template_id);
$s->execute();
$template = $s->get_result()->fetch_assoc();
$s->close();

if (!$template) {
    ob_end_clean(); echo json_encode(['success' => false, 'error' => 'Template not found']);
    exit();
}

// Already forked?
$chk = $dbc->prepare("SELECT deck_id FROM user_decks WHERE user_id = ? AND template_id = ?");
$chk->bind_param("ii", $user_id, $template_id);
$chk->execute();
$existing = $chk->get_result()->fetch_assoc();
$chk->close();

if ($existing) {
    ob_end_clean();
    echo json_encode(['success' => false, 'already_forked' => true, 'deck_id' => $existing['deck_id']]);
    exit();
}

$cards = json_decode($template['card_data'], true);
if (!$cards) {
    ob_end_clean(); echo json_encode(['success' => false, 'error' => 'Invalid template data']);
    exit();
}

// Validate card IDs against local cards table
$all_ids      = array_column($cards, 'card_id');
$placeholders = implode(',', array_fill(0, count($all_ids), '?'));
$types        = str_repeat('s', count($all_ids));
$valid_check  = $dbc->prepare("SELECT id FROM cards WHERE id IN ($placeholders)");
$valid_check->bind_param($types, ...$all_ids);
$valid_check->execute();
$valid_set = array_flip(
    array_column($valid_check->get_result()->fetch_all(MYSQLI_ASSOC), 'id')
);
$valid_check->close();

$valid_zones = ['mainboard', 'sideboard', 'commander', 'companion', 'maybeboard', 'tokens'];

$dbc->begin_transaction();
try {
    // Create the forked deck
    $deck_name = $template['name'];
    $deck_desc = $template['description'] ?? '';
    $ins_deck  = $dbc->prepare("INSERT INTO decks (user_id, name, description) VALUES (?, ?, ?)");
    $ins_deck->bind_param("iss", $user_id, $deck_name, $deck_desc);
    $ins_deck->execute();
    $deck_id = $dbc->insert_id;
    $ins_deck->close();

    // Insert cards
    $ins_card = $dbc->prepare(
        "INSERT INTO deck_cards (deck_id, card_id, quantity, zone)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE quantity = VALUES(quantity)"
    );
    foreach ($cards as $card) {
        $card_id = $card['card_id'];
        if (!isset($valid_set[$card_id])) continue;
        $qty  = (int)$card['quantity'];
        $zone = (isset($card['zone']) && in_array($card['zone'], $valid_zones, true))
              ? $card['zone']
              : (!empty($card['is_sideboard']) ? 'sideboard' : 'mainboard');
        if (str_contains($card['type_line'] ?? '', 'Token')) $zone = 'tokens';
        $ins_card->bind_param("isis", $deck_id, $card_id, $qty, $zone);
        $ins_card->execute();
    }
    $ins_card->close();

    // Record the fork
    $ins_ud = $dbc->prepare("INSERT INTO user_decks (user_id, template_id, deck_id) VALUES (?, ?, ?)");
    $ins_ud->bind_param("iii", $user_id, $template_id, $deck_id);
    $ins_ud->execute();
    $ins_ud->close();

    // Increment fork counter
    $upd = $dbc->prepare("UPDATE deck_templates SET fork_count = fork_count + 1 WHERE id = ?");
    $upd->bind_param("i", $template_id);
    $upd->execute();
    $upd->close();

    $dbc->commit();
} catch (Exception $e) {
    $dbc->rollback();
    error_log("Fork failed for template $template_id: " . $e->getMessage());
    ob_end_clean();
    echo json_encode(['success' => false, 'error' => 'Fork failed. Please try again.']);
    exit();
}

$dbc->close();
ob_end_clean();
echo json_encode(['success' => true, 'deck_id' => $deck_id]);
