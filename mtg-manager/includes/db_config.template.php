<?php
/**
 * db_config.php — Database credentials
 *
 * SETUP:
 *   1. Copy this file: cp db_config.template.php db_config.php
 *   2. Fill in your credentials below
 *   3. db_config.php is gitignored — never commit it
 *
 * APP_BASE should match the subfolder name you dropped the app into.
 *   e.g. if your URL is http://localhost/mtg-manager/   → APP_BASE = '/mtg-manager'
 *   e.g. if your URL is http://localhost/               → APP_BASE = ''
 */

define('APP_BASE', '/mtg-manager'); // ← change to match your htdocs subfolder

define('DB_HOST', 'localhost');
define('DB_USER', 'mtg_collection');
define('DB_PASS', 'change_this_password');
define('DB_NAME', 'mtg_database');
