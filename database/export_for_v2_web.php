<?php
/**
 * V1 Admin Export → V2 (web UI version)
 *
 * Drop this file into the **V1 repository project root** as
 * `export_for_v2.php` (rename when you copy — V2's repo keeps it under
 * database/ but in V1 it should live where the other admin pages live so
 * the nav link works). Then add a link to it from V1's admin menu in
 * header.php.
 *
 * Admin clicks "Export for V2 Migration" in the menu, this page streams
 * back a v1_export.json download. They take that file to the V2 admin
 * Import page (admin_import.php) and upload it.
 *
 * Read-only against V1. Uses V1's existing dbcon / session_config so it
 * inherits the same auth, CSRF expectations, and connection setup as
 * every other page.
 */

require 'session_config.php';
require 'dbcon.php';

// Auth + admin gate (same pattern as V1's other admin pages).
if (!isset($_SESSION['username'])) {
    $currentUrl = urlencode($_SERVER['REQUEST_URI']);
    header("Location: index.php?redirect=$currentUrl");
    exit;
}
if (($_SESSION['role'] ?? '') !== 'admin') {
    $_SESSION['message'] = 'Admin only.';
    header('Location: home.php');
    exit;
}

// Tables we care about (same set as the CLI exporter and the V2 importer).
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
    'source_db'   => $_ENV['DB_DATABASE'] ?? null,
    'tables'      => [],
];

foreach ($tables as $t) {
    // Skip tables that don't exist (older V1 deployments may be missing
    // a few of the later additions like activity_log).
    $check = $con->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?");
    $check->bind_param("s", $t);
    $check->execute();
    if ($check->get_result()->num_rows === 0) {
        $check->close();
        $payload['tables'][$t] = [];
        continue;
    }
    $check->close();

    $rows = [];
    $res = $con->query("SELECT * FROM `$t`");
    while ($row = $res->fetch_assoc()) $rows[] = $row;
    $res->close();
    $payload['tables'][$t] = $rows;
}

$json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
if ($json === false) {
    http_response_code(500);
    echo 'JSON encode failed: ' . json_last_error_msg();
    exit;
}

// Stream as a download. Filename includes timestamp so multiple exports
// don't overwrite each other in the user's downloads folder.
$filename = 'v1_export_' . date('Ymd_His') . '.json';

// Audit the export if V1 has activity_log already migrated in.
if (function_exists('log_activity')) {
    log_activity($con, 'export', 'v1_data', null, "Exported V1 data for V2 migration ($filename)");
} else {
    // V1's helper might be at a known path; load it if so.
    $helper = __DIR__ . '/log_activity.php';
    if (file_exists($helper)) {
        require_once $helper;
        if (function_exists('log_activity')) {
            log_activity($con, 'export', 'v1_data', null, "Exported V1 data for V2 migration ($filename)");
        }
    }
}

header('Content-Type: application/json; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($json));
echo $json;
