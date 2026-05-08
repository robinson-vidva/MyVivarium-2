<?php
/**
 * Schema installer.
 *
 * Reads DB_HOST / DB_USERNAME / DB_PASSWORD / DB_DATABASE from the project
 * .env file (same loader as dbcon.php) and applies database/schema.sql to
 * the target database. Intended to be run once on a fresh, empty database.
 *
 * Usage (from the project root):
 *
 *   php database/install.php           # install into an empty DB; abort if
 *                                      # any tables already exist.
 *   php database/install.php --reset   # drop every table in the DB first,
 *                                      # then install. Destructive — only
 *                                      # use on dev databases you control.
 *
 * Both forms keep the database itself intact (no DROP DATABASE / CREATE
 * DATABASE), so the user / grants you've already configured stay valid.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

$projectRoot = dirname(__DIR__);
$autoload    = $projectRoot . '/vendor/autoload.php';
$envFile     = $projectRoot . '/.env';
$schemaFile  = __DIR__ . '/schema.sql';

if (!file_exists($autoload)) {
    fwrite(STDERR, "vendor/autoload.php not found. Run `composer install` first.\n");
    exit(1);
}
if (!file_exists($envFile)) {
    fwrite(STDERR, ".env not found at $envFile. Copy .env.example and fill it in.\n");
    exit(1);
}
if (!file_exists($schemaFile)) {
    fwrite(STDERR, "schema.sql not found at $schemaFile.\n");
    exit(1);
}

require $autoload;
Dotenv\Dotenv::createImmutable($projectRoot)->load();

$host = $_ENV['DB_HOST']     ?? 'localhost';
$user = $_ENV['DB_USERNAME'] ?? 'root';
$pass = $_ENV['DB_PASSWORD'] ?? '';
$db   = $_ENV['DB_DATABASE'] ?? 'myvivarium';

$reset = in_array('--reset', $argv ?? [], true);

echo "Target: {$user}@{$host}/{$db}" . ($reset ? "  [--reset]" : "") . "\n";

$con = new mysqli($host, $user, $pass, $db);
if ($con->connect_error) {
    fwrite(STDERR, "Connection failed: {$con->connect_error}\n");
    exit(1);
}
$con->set_charset('utf8mb4');

// Inspect existing tables.
$existing = [];
$res = $con->query("SHOW TABLES");
while ($row = $res->fetch_array()) $existing[] = $row[0];
$res->close();

if ($existing) {
    if (!$reset) {
        fwrite(STDERR, "Database `$db` is not empty (" . count($existing) . " tables present). "
            . "Re-run with --reset to drop them, or pick an empty database.\n");
        exit(1);
    }
    echo "Dropping " . count($existing) . " existing table(s)...\n";
    $con->query("SET FOREIGN_KEY_CHECKS = 0");
    foreach ($existing as $t) {
        if (!$con->query("DROP TABLE IF EXISTS `$t`")) {
            fwrite(STDERR, "Failed to drop `$t`: {$con->error}\n");
            exit(1);
        }
    }
    $con->query("SET FOREIGN_KEY_CHECKS = 1");
}

echo "Applying schema.sql...\n";
$sql = file_get_contents($schemaFile);
if (!$con->multi_query($sql)) {
    fwrite(STDERR, "Schema apply failed: {$con->error}\n");
    exit(1);
}
// Drain the multi-query result set so subsequent queries on the connection work.
do {
    if ($r = $con->store_result()) $r->free();
} while ($con->more_results() && $con->next_result());

if ($con->errno) {
    fwrite(STDERR, "Error during schema apply: {$con->error}\n");
    exit(1);
}

// Verify.
$created = [];
$res = $con->query("SHOW TABLES");
while ($row = $res->fetch_array()) $created[] = $row[0];
$res->close();

echo "Done. " . count($created) . " tables in `$db`:\n";
foreach ($created as $t) echo "  - $t\n";

$con->close();
