<?php
session_start();
include "connect.php";
include "functions.php";

if (isset($_POST['user']) && isset($_POST['pass'])) {
    requireCsrf();
    $username = strtolower(trim($_POST['user']));   // always stored lowercase
    $password = $_POST['pass'];                      // never sanitize passwords
    $email    = strtolower(trim($_POST['email'] ?? ''));

    if (empty($username)) {
        header("Location: portal.php?error=Username+is+required");
        exit();
    } elseif (strlen($username) < 3) {
        header("Location: portal.php?error=Username+must+be+at+least+3+characters");
        exit();
    } elseif (!preg_match('/^[a-z0-9_]+$/', $username)) {
        header("Location: portal.php?error=Username+may+only+contain+letters,+numbers,+and+underscores");
        exit();
    } elseif (empty($password)) {
        header("Location: portal.php?error=Password+is+required");
        exit();
    } elseif (preg_match_all('/./su', $password) < 8 || preg_match_all('/./su', $password) > 32) {
        header("Location: portal.php?error=Password+must+be+between+8+and+32+characters");
        exit();
    } elseif (empty($email)) {
        header("Location: portal.php?error=Email address is required");
        exit();
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || !preg_match('/\.([a-zA-Z]{2,})$/', explode('@', $email)[1])) {
        header("Location: portal.php?error=Please enter a valid email address (e.g. you@example.com)");
        exit();
    } else {
        // Check username uniqueness
        $check_sql = "SELECT id FROM player WHERE username = ?";
        $check_stmt = mysqli_prepare($dbc, $check_sql);
        mysqli_stmt_bind_param($check_stmt, "s", $username);
        mysqli_stmt_execute($check_stmt);
        mysqli_stmt_store_result($check_stmt);

        if (mysqli_stmt_num_rows($check_stmt) > 0) {
            header("Location: portal.php?error=Username already exists");
            exit();
        }
        mysqli_stmt_close($check_stmt);

        // Check email uniqueness
        $email_stmt = mysqli_prepare($dbc, "SELECT id FROM player WHERE email = ?");
        mysqli_stmt_bind_param($email_stmt, "s", $email);
        mysqli_stmt_execute($email_stmt);
        mysqli_stmt_store_result($email_stmt);
        if (mysqli_stmt_num_rows($email_stmt) > 0) {
            header("Location: portal.php?error=That email is already registered to an account");
            exit();
        }
        mysqli_stmt_close($email_stmt);

        if (true) {
            // Hash the password
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);

            // Insert new user with prepared statement
            $insert_sql = "INSERT INTO player (username, password, email) VALUES (?, ?, ?)";
            $insert_stmt = mysqli_prepare($dbc, $insert_sql);
            mysqli_stmt_bind_param($insert_stmt, "sss", $username, $hashed_password, $email);

            if (mysqli_stmt_execute($insert_stmt)) {
                header("Location: index.php?success=Account created successfully! Please login.");
                exit();
            } else {
                error_log("Registration failed: " . mysqli_error($dbc));
            header("Location: portal.php?error=Registration+failed.+Please+try+again.");
                exit();
            }
            mysqli_stmt_close($insert_stmt);
        }
        mysqli_stmt_close($check_stmt);
    }
} else {
    header("Location: portal.php");
    exit();
}
?>