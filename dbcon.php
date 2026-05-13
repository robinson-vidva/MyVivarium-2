<?php

/**
 * Database Connection Script
 *
 * Loads environment variables from .env and opens a mysqli connection to
 * the MyVivarium database. Composer is optional — if vendor/ is missing
 * (e.g. on a fresh production deploy that hasn't run `composer install`)
 * we fall back to the built-in env parser in includes/env.php so the app
 * still boots.
 */

// Composer autoload is optional. Load it if present so existing `use`
// statements for PHPMailer / Dotenv keep resolving, but don't crash if
// vendor/ hasn't been installed.
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

// Built-in zero-dependency env loader. Safe to load unconditionally.
require_once __DIR__ . '/includes/env.php';
env_load_into_superglobals();

// Retrieve database connection credentials from environment variables.
$servername = env_get('DB_HOST')     ?? 'localhost';
$username   = env_get('DB_USERNAME') ?? 'root';
$password   = env_get('DB_PASSWORD') ?? '';
$dbname     = env_get('DB_DATABASE') ?? 'myvivarium';

$demo = env_get('DEMO') ?? '';

// Create a new connection to the database using the object-oriented style
$con = new mysqli($servername, $username, $password, $dbname);

// Check the connection to the database
if ($con->connect_error) {
    error_log('Connection Failed: ' . $con->connect_error);
    die('Connection Failed. Please try again later.');
}
