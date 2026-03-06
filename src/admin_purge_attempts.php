<?php
session_start();
require_once 'connect.php';
require_once 'functions.php';
header('Content-Type: application/json');

if (!isLoggedIn() || !isAdmin()) {
    echo json_encode(['success' => false, 'error' => 'Access denied.']);
    exit();
}

requireCsrf();

$stmt = $dbc->query("DELETE FROM login_attempts");
$deleted = $dbc->affected_rows;
$dbc->close();

echo json_encode(['success' => true, 'deleted' => $deleted]);
?>
