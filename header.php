<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once 'functions.php';
require_once 'connect.php';   // ensures $dbc is always available before isLoggedIn() runs
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>MTG Collection Manager</title>
    <meta name="csrf-token" content="<?= generateCsrfToken() ?>">
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <!-- Custom CSS -->
    <link rel="stylesheet" href="style.css?v=<?= filemtime('style.css') ?>">
</head>
<body>
<?php $current_page = basename($_SERVER['PHP_SELF']); ?>
<nav class="navbar navbar-expand-lg navbar-dark navbar-mtg">
    <div class="container-fluid">
        <!-- Brand on the left -->
        <a class="navbar-brand" href="<?= isLoggedIn() ? 'dashboard.php' : 'index.php' ?>">MTG Manager</a>

        <!-- Toggle button for mobile (hamburger) -->
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
            <span class="navbar-toggler-icon"></span>
        </button>

        <!-- Nav links: left-aligned after brand -->
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav me-auto">
                <li class="nav-item">
                    <a class="nav-link <?= $current_page === 'search.php' ? 'active' : '' ?>" href="search.php">Search</a>
                </li>
                <?php if (isLoggedIn()): ?>
                    <li class="nav-item">
                        <a class="nav-link <?= $current_page === 'collection.php' ? 'active' : '' ?>" href="collection.php">Collection</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= in_array($current_page, ['decks.php','deck_editor.php']) ? 'active' : '' ?>" href="decks.php">Decks</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= $current_page === 'wishlist.php' ? 'active' : '' ?>" href="wishlist.php">Wishlist</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= $current_page === 'import_deck.php' ? 'active' : '' ?>" href="import_deck.php">Import Deck</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= $current_page === 'profile.php' ? 'active' : '' ?>" href="profile.php">Profile</a>
                    </li>
                <?php else: ?>
                    <li class="nav-item">
                        <a class="nav-link <?= $current_page === 'portal.php' ? 'active' : '' ?>" href="portal.php">Register</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= $current_page === 'index.php' ? 'active' : '' ?>" href="index.php">Login</a>
                    </li>
                <?php endif; ?>
            </ul>
            <?php if (isLoggedIn()): ?>
            <ul class="navbar-nav ms-auto">
                <?php if (isAdmin()): ?>
                <li class="nav-item">
                    <a class="nav-link <?= $current_page === 'admin_unlock.php' ? 'active' : '' ?>"
                       href="admin_unlock.php"
                       style="color:#f97316;">
                        <i class="bi bi-shield-lock me-1"></i>Admin
                    </a>
                </li>
                <?php endif; ?>
                <li class="nav-item">
                    <a class="nav-link" href="logout.php"
                       style="color:#f87171;"
                       onmouseover="this.style.color='#ff9999'" onmouseout="this.style.color='#f87171'">
                        <i class="bi bi-box-arrow-right me-1"></i>Logout (<?= htmlspecialchars(getCurrentUser()) ?>)
                    </a>
                </li>
            </ul>
            <?php endif; ?>
        </div>
    </div>
</nav>

<!-- Toast container for AJAX notifications -->
<div id="toast-container" class="toast-container position-fixed top-0 end-0 p-3" style="z-index: 1100;"></div>

<!-- Main content starts here -->
<script>
// Auto-inject CSRF token into all POST fetch() calls that use FormData
(function() {
    const _fetch = window.fetch;
    window.fetch = function(url, opts = {}) {
        if (opts.method === 'POST' && opts.body instanceof FormData) {
            const tok = document.querySelector('meta[name="csrf-token"]')?.content;
            if (tok) opts.body.append('csrf_token', tok);
        }
        return _fetch.call(this, url, opts);
    };
})();
</script>