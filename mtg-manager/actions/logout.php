<?php
session_start();
include __DIR__ . '/../includes/connect.php';
include __DIR__ . '/../includes/functions.php';

// Clear the session token from DB so the slot is freed cleanly
if (isset($_SESSION['id'])) {
    $stmt = $dbc->prepare("UPDATE player SET session_token = NULL WHERE id = ?");
    $stmt->bind_param("i", $_SESSION['id']);
    $stmt->execute();
    $stmt->close();
}

session_unset();
session_destroy();
$dbc->close();
header("Location: ../index.php");
exit();
?>
