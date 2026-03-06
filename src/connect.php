<?php
/**
 * connect.php
 * Database connection with secure config and error handling.
 * Safe to include multiple times — connection is only created once.
 */
if (isset($dbc)) return;  // already connected

require_once 'C:/secure-config/db_config.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $dbc = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    $dbc->set_charset('utf8mb4');
} catch (Exception $e) {
    error_log("Database connection failed: " . $e->getMessage());
    die("Service temporarily unavailable. Please try again later.");
}
