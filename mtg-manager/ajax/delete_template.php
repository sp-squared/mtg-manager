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

// Verify the current user is the creator
$s = $dbc->prepare("SELECT id FROM deck_templates WHERE id = ? AND creator_user_id = ?");
$s->bind_param("ii", $template_id, $user_id);
$s->execute();
$s->store_result();
$owns = $s->num_rows > 0;
$s->close();

if (!$owns) {
    ob_end_clean(); echo json_encode(['success' => false, 'error' => 'Not your template']);
    exit();
}

// Delete — user_decks rows cascade automatically; forked decks remain as independent personal decks
$del = $dbc->prepare("DELETE FROM deck_templates WHERE id = ? AND creator_user_id = ?");
$del->bind_param("ii", $template_id, $user_id);
$del->execute();
$del->close();
$dbc->close();

ob_end_clean();
echo json_encode(['success' => true]);
