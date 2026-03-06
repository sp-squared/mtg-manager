<?php
session_start();
include __DIR__ . '/../includes/connect.php';
include __DIR__ . '/../includes/functions.php';

if (!isLoggedIn()) {
    global $_session_kicked;
    $msg = $_session_kicked
        ? "You+were+signed+in+elsewhere.+This+session+has+ended."
        : "Please+log+in+to+continue.";
    header("Location: ../index.php?error=" . $msg);
    exit();
}
requireCsrf();


$user_id = getUserId();
$code    = strtoupper(trim($_POST['export_code'] ?? ''));

if (!$code) {
    header("Location: ../import_deck.php?error=Missing code");
    exit();
}

// Fetch export
$s = $dbc->prepare(
    "SELECT id, deck_name, description, card_data, expires_at
     FROM deck_exports WHERE export_code = ?"
);
$s->bind_param("s", $code);
$s->execute();
$export = $s->get_result()->fetch_assoc();
$s->close();

if (!$export) {
    header("Location: ../import_deck.php?code={$code}&error=Code not found");
    exit();
}
if ($export['expires_at'] && strtotime($export['expires_at']) < time()) {
    header("Location: ../import_deck.php?code={$code}&error=Code expired");
    exit();
}

$cards = json_decode($export['card_data'], true);
if (!$cards) {
    header("Location: ../import_deck.php?code={$code}&error=Invalid card data");
    exit();
}

// ── Atomic import: deck creation + card inserts + counter in one transaction ──
$deck_name = $export['deck_name'] . ' (Imported)';
$desc      = $export['description'] ?? '';

$dbc->begin_transaction();
try {
    $ins = $dbc->prepare("INSERT INTO decks (user_id, name, description) VALUES (?, ?, ?)");
    $ins->bind_param("iss", $user_id, $deck_name, $desc);
    $ins->execute();
    $new_deck_id = $dbc->insert_id;
    $ins->close();

    // Validate card IDs against the local cards table
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

    $card_ins = $dbc->prepare(
        "INSERT INTO deck_cards (deck_id, card_id, quantity, is_sideboard)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE quantity = VALUES(quantity)"
    );
    foreach ($cards as $card) {
        $card_id = $card['card_id'];
        if (!isset($valid_set[$card_id])) continue;
        $qty  = (int)$card['quantity'];
        $side = (int)$card['is_sideboard'];
        $card_ins->bind_param("isii", $new_deck_id, $card_id, $qty, $side);
        $card_ins->execute();
    }
    $card_ins->close();

    // Increment import counter atomically
    $upd = $dbc->prepare("UPDATE deck_exports SET import_count = import_count + 1 WHERE export_code = ?");
    $upd->bind_param("s", $code);
    $upd->execute();
    $upd->close();

    $dbc->commit();
} catch (Exception $e) {
    $dbc->rollback();
    error_log("Import failed for code $code: " . $e->getMessage());
    header("Location: ../import_deck.php?code={$code}&error=Import+failed.+Please+try+again.");
    exit();
}

$dbc->close();
header("Location: ../deck_editor.php?deck_id={$new_deck_id}&msg=imported");
exit();
?>
