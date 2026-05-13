<?php
/**
 * Admin-only AJAX endpoint: tests the stored API key for a specified
 * provider by hitting its GET /models endpoint via llm_test_connection().
 *
 * Accepts ?provider=groq or ?provider=openai. Defaults to the active
 * provider when no parameter is given. Returns small JSON with success
 * status, model count, or a verbatim provider error message.
 *
 * **The API key itself is never included in any field of the response.**
 */

require 'session_config.php';
require 'dbcon.php';
require_once __DIR__ . '/includes/ai_settings.php';
require_once __DIR__ . '/includes/llm_provider.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

if (!isset($_SESSION['username']) || ($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Admin role required.']);
    exit;
}

$provider = (string)($_GET['provider'] ?? '');
if ($provider === '') {
    $provider = llm_get_active_provider();
}
if (!in_array($provider, [LLM_PROVIDER_GROQ, LLM_PROVIDER_OPENAI], true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => "Unknown provider '$provider'."]);
    exit;
}

try {
    $res = llm_test_connection($provider);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Test error: ' . $e->getMessage()]);
    exit;
}

if ($res['ok']) {
    echo json_encode(['ok' => true, 'provider' => $provider, 'model_count' => $res['model_count']]);
    exit;
}

echo json_encode([
    'ok'          => false,
    'provider'    => $provider,
    'http_status' => $res['http_status'] ?? 0,
    'message'     => $res['error'] ?? 'Unknown error',
]);
