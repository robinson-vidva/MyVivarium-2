<?php
/**
 * V1 → V2 Exporter
 *
 * Drop this file into the **V1 MyVivarium repository** (alongside dbcon.php)
 * and run it on the V1 server. It reads from the configured V1 database
 * (using the same .env that V1's dbcon.php reads) and writes a single JSON
 * file containing every row of every table V2 needs to know about.
 *
 * The resulting JSON is then uploaded into V2's admin Import page
 * (admin_import.php), which transforms and applies the data.
 *
 * Usage on the V1 server (project root):
 *
 *   php database/export_for_v2.php > v1_export.json
 *   # or, if writing the file directly:
 *   php database/export_for_v2.php --out=v1_export.json
 *
 * No write access to V1's database is needed. The exporter is read-only.
 *
 * The JSON format is intentionally simple: a top-level object whose
 * `tables` key holds one array of rows per table. The V2 importer is the
 * authoritative consumer — see admin_import.php for the consumed schema.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run from CLI: php database/export_for_v2.php\n");
    exit(1);
}

$projectRoot = dirname(__DIR__);
$autoload    = $projectRoot . '/vendor/autoload.php';
$envFile     = $projectRoot . '/.env';

if (!file_exists($autoload)) {
    fwrite(STDERR, "vendor/autoload.php not found. Run `composer install` in V1 first.\n");
    exit(1);
}
if (!file_exists($envFile)) {
    fwrite(STDERR, ".env not found at $envFile.\n");
    exit(1);
}

require $autoload;
Dotenv\Dotenv::createImmutable($projectRoot)->load();

$host = $_ENV['DB_HOST']     ?? 'localhost';
$user = $_ENV['DB_USERNAME'] ?? 'root';
$pass = $_ENV['DB_PASSWORD'] ?? '';
$db   = $_ENV['DB_DATABASE'] ?? 'myvivarium';

$outFile = null;
foreach ($argv as $arg) {
    if (strpos($arg, '--out=') === 0) $outFile = substr($arg, 6);
}

fwrite(STDERR, "Connecting to V1 at {$user}@{$host}/{$db}...\n");
$con = new mysqli($host, $user, $pass, $db);
if ($con->connect_error) {
    fwrite(STDERR, "Connection failed: {$con->connect_error}\n");
    exit(1);
}
$con->set_charset('utf8mb4');

// Tables we care about. Order doesn't matter for export (the importer handles
// FK ordering); we list them so unknown / cache / temp tables are ignored.
$tables = [
    'users', 'iacuc', 'strains', 'settings',
    'cages', 'cage_users', 'cage_iacuc',
    'holding', 'mice', 'breeding', 'litters',
    'files', 'notes', 'tasks', 'maintenance',
    'reminders', 'notifications', 'outbox',
    'activity_log',
];

$payload = [
    'exported_at' => gmdate('c'),
    'source_db'   => $db,
    'tables'      => [],
];

foreach ($tables as $t) {
    // Skip tables that don't exist in this V1 install (older deployments may
    // be missing a few of the later additions like activity_log).
    $check = $con->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?");
    $check->bind_param("s", $t);
    $check->execute();
    if ($check->get_result()->num_rows === 0) {
        fwrite(STDERR, "  skip: $t (not in source)\n");
        $check->close();
        $payload['tables'][$t] = [];
        continue;
    }
    $check->close();

    $rows = [];
    $res = $con->query("SELECT * FROM `$t`");
    while ($row = $res->fetch_assoc()) $rows[] = $row;
    $res->close();
    fwrite(STDERR, "  $t: " . count($rows) . " rows\n");
    $payload['tables'][$t] = $rows;
}

$json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
if ($json === false) {
    fwrite(STDERR, "JSON encode failed: " . json_last_error_msg() . "\n");
    exit(1);
}

if ($outFile) {
    if (file_put_contents($outFile, $json) === false) {
        fwrite(STDERR, "Failed to write $outFile\n");
        exit(1);
    }
    fwrite(STDERR, "Wrote " . number_format(strlen($json)) . " bytes to $outFile\n");
} else {
    echo $json;
}

$con->close();
