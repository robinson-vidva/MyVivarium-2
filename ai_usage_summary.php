<?php
require 'session_config.php';
require 'dbcon.php';
require_once __DIR__ . '/includes/ai_settings.php';
require_once __DIR__ . '/includes/ai_configs.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

if (!isset($_SESSION['username']) || ($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Admin role required.']);
    exit;
}

try {
    $summary = ai_configs_usage_summary($con);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Summary error: ' . $e->getMessage()]);
    exit;
}

echo json_encode(['ok' => true, 'summary' => $summary], JSON_UNESCAPED_SLASHES);
