<?php
require 'session_config.php';
require 'dbcon.php';
require_once __DIR__ . '/includes/ai_settings.php';
require_once __DIR__ . '/includes/llm_provider.php';
require_once __DIR__ . '/includes/ai_configs.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

if (!isset($_SESSION['username']) || ($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Admin role required.']);
    exit;
}

$configId = isset($_GET['config_id']) ? (int)$_GET['config_id'] : 0;
if ($configId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Missing config_id.']);
    exit;
}

try {
    $res = ai_configs_test_connection($con, $configId);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Test error: ' . $e->getMessage()]);
    exit;
}

if (!empty($res['ok'])) {
    echo json_encode([
        'ok' => true,
        'used_key'    => $res['used_key'] ?? 'primary',
        'model_count' => $res['model_count'] ?? null,
        'http_status' => $res['http_status'] ?? 200,
    ]);
    exit;
}
echo json_encode([
    'ok' => false,
    'used_key'    => $res['used_key'] ?? '',
    'http_status' => $res['http_status'] ?? 0,
    'message'     => $res['error'] ?? 'Unknown error',
]);
