<?php
/**
 * Admin-only AJAX endpoint: tests the stored Groq API key by hitting
 * GET https://api.groq.com/openai/v1/models with a 10-second cURL
 * timeout. Returns small JSON with success status, model count, or the
 * verbatim Groq error message. **The API key itself is never included
 * in any field of the response.**
 */

require 'session_config.php';
require 'dbcon.php';
require_once __DIR__ . '/includes/ai_settings.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

if (!isset($_SESSION['username']) || ($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Admin role required.']);
    exit;
}

try {
    $key = ai_settings_get('groq_api_key');
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Encryption error: ' . $e->getMessage()]);
    exit;
}
if ($key === null || $key === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'No Groq API key is configured.']);
    exit;
}

$ch = curl_init('https://api.groq.com/openai/v1/models');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_HTTPHEADER     => [
        'Authorization: Bearer ' . $key,
        'Accept: application/json',
    ],
]);
$body = curl_exec($ch);
$err  = curl_error($ch);
$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

// Defensive: don't leak the key in any failure-path string.
if ($body === false) {
    echo json_encode(['ok' => false, 'message' => 'Network error: ' . ($err ?: 'unknown')]);
    exit;
}

if ($code === 200) {
    $decoded = json_decode((string)$body, true);
    $count = 0;
    if (is_array($decoded) && isset($decoded['data']) && is_array($decoded['data'])) {
        $count = count($decoded['data']);
    }
    echo json_encode(['ok' => true, 'model_count' => $count]);
    exit;
}

// Non-200: surface Groq's error message verbatim, but only the message — never the key.
$decoded = json_decode((string)$body, true);
$msg = null;
if (is_array($decoded)) {
    if (isset($decoded['error']['message'])) $msg = (string)$decoded['error']['message'];
    elseif (isset($decoded['message']))      $msg = (string)$decoded['message'];
}
if ($msg === null || $msg === '') {
    $msg = 'HTTP ' . $code . ' from Groq';
}

echo json_encode(['ok' => false, 'http_status' => $code, 'message' => $msg]);
