<?php
session_start();
include 'connect.php';
include 'functions.php';
if (!isLoggedIn()) {
    global $_session_kicked;
    $msg = $_session_kicked ? "You+were+signed+in+elsewhere.+This+session+has+ended." : "Please+log+in+to+continue.";
    header("Location: index.php?error=" . $msg);
    exit();
}
requireCsrf();


$user_id = getUserId();
$name = trim($_POST['name'] ?? '');
$description = trim($_POST['description'] ?? '');

if (empty($name)) {
    header("Location: decks.php?error=Name required");
    exit();
}

$stmt = $dbc->prepare("INSERT INTO decks (user_id, name, description) VALUES (?, ?, ?)");
$stmt->bind_param("iss", $user_id, $name, $description);
$stmt->execute();

header("Location: decks.php?msg=created");
exit();
?>