<?php
/**
 * Chatbot conversation history endpoint.
 *
 *   GET /ai_chat_history.php                → list user's conversations
 *   GET /ai_chat_history.php?id=<uuid>      → load messages for one conversation
 *
 * Authenticated via PHP session. Restricted to the current user.
 */

require 'session_config.php';
require 'dbcon.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'not_authenticated']);
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$id = isset($_GET['id']) ? (string)$_GET['id'] : '';

if ($id === '') {
    $stmt = $con->prepare("SELECT id, title, created_at, updated_at FROM ai_conversations WHERE user_id = ? ORDER BY updated_at DESC LIMIT 50");
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    echo json_encode(['ok' => true, 'conversations' => $rows], JSON_UNESCAPED_SLASHES);
    exit;
}

// Verify ownership.
$stmt = $con->prepare("SELECT id, title FROM ai_conversations WHERE id = ? AND user_id = ?");
$stmt->bind_param('si', $id, $user_id);
$stmt->execute();
$conv = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$conv) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'not_found']);
    exit;
}

// suggestions_json column may not exist on older deployments; degrade gracefully.
$hasSuggestions = false;
$probe = @$con->query("SHOW COLUMNS FROM ai_messages LIKE 'suggestions_json'");
if ($probe) {
    $hasSuggestions = $probe->num_rows > 0;
    $probe->close();
}
$columns = "id, role, content, tool_call_json, tool_result_json, pending_op_id, created_at"
    . ($hasSuggestions ? ", suggestions_json" : "");
$stmt = $con->prepare("SELECT $columns FROM ai_messages WHERE conversation_id = ? ORDER BY id ASC");
$stmt->bind_param('s', $id);
$stmt->execute();
$msgs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Trim tool messages to a UI-friendly summary line so the browser can
// render the inline collapsible tool-call card without dumping raw JSON.
foreach ($msgs as &$m) {
    if ($m['tool_call_json'])   $m['tool_call_json']   = json_decode($m['tool_call_json'], true);
    if ($m['tool_result_json']) $m['tool_result_json'] = json_decode($m['tool_result_json'], true);
    if (isset($m['suggestions_json']) && $m['suggestions_json'] !== null && $m['suggestions_json'] !== '') {
        $decoded = json_decode($m['suggestions_json'], true);
        $m['suggestions'] = is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
    } else {
        $m['suggestions'] = [];
    }
    unset($m['suggestions_json']);
}
unset($m);

echo json_encode(['ok' => true, 'conversation' => $conv, 'messages' => $msgs], JSON_UNESCAPED_SLASHES);
