<?php
/**
 * Chatbot backend endpoint.
 *
 *   POST /ai_chat.php  (JSON)
 *
 * Accepts {message, conversation_id?, confirm_pending_op?, cancel_pending_op?}.
 * Returns {ok, reply, conversation_id, tool_calls, pending_confirmation?}.
 *
 * Architecture (part three):
 *   - Authenticated via PHP session — only logged-in users can post.
 *   - Mints a per-session API key on first use (4h expiry) and uses it for
 *     every tool call against the local /api/v1/* REST API. The chatbot
 *     never calls service functions directly; everything goes over HTTPS
 *     using the API.
 *   - Tool definitions are 1:1 with the REST endpoints from part one.
 *   - Destructive tools (update / move / sacrifice / delete) flow through
 *     the API's confirm-before-execute path: first call sends
 *     X-Confirm-Token: pending → 202 + pending_operation_id; browser
 *     confirms, second call replays with X-Confirm-Token: <uuid>.
 *   - Up to 6 tool-call iterations per user turn. Persists every message
 *     to ai_messages and every Groq response to ai_usage_log.
 */

require 'session_config.php';
require 'dbcon.php';
require_once __DIR__ . '/log_activity.php';
require_once __DIR__ . '/includes/ai_settings.php';
require_once __DIR__ . '/includes/llm_provider.php';
require_once __DIR__ . '/includes/chatbot_session.php';
require_once __DIR__ . '/includes/chatbot_helpers.php';
require_once __DIR__ . '/includes/ai_rate_limit.php';
require_once __DIR__ . '/services/helpers.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

// -----------------------------------------------------------------------------
// Auth + method gating
// -----------------------------------------------------------------------------

if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'not_authenticated']);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

$user_id   = (int)$_SESSION['user_id'];
$username  = (string)($_SESSION['name'] ?? $_SESSION['username']);

// -----------------------------------------------------------------------------
// Body parse + safety guards
// -----------------------------------------------------------------------------

$raw = file_get_contents('php://input') ?: '';
$body = json_decode($raw, true);
if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_json']);
    exit;
}

$incomingMessage    = isset($body['message']) ? (string)$body['message'] : '';
$conversation_id    = isset($body['conversation_id'])    ? (string)$body['conversation_id']    : '';
$confirm_pending_op = isset($body['confirm_pending_op']) ? (string)$body['confirm_pending_op'] : '';
$cancel_pending_op  = isset($body['cancel_pending_op'])  ? (string)$body['cancel_pending_op']  : '';

// A user-typed message is required UNLESS this turn is purely a
// confirm/cancel decision on a previously-pending op.
$isDecisionTurn = ($confirm_pending_op !== '' || $cancel_pending_op !== '');

if (!$isDecisionTurn) {
    if ($incomingMessage === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'message_required']);
        exit;
    }
    if (strlen($incomingMessage) > 2000) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'message_too_long', 'detail' => 'Message must be 2000 chars or fewer.']);
        exit;
    }
    // Reject suspicious noise: >10 consecutive non-alphanumeric chars.
    if (preg_match('/[^A-Za-z0-9]{11,}/', $incomingMessage)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'message_rejected', 'detail' => 'Message looks like noise or injection.']);
        exit;
    }
}

// -----------------------------------------------------------------------------
// Per-user AI rate limit. Applies to USER MESSAGES, not tool calls or LLM
// round-trips. We check BEFORE the chatbot-enabled gate so a hit returns a
// clean JSON envelope no matter what the admin config looks like.
// Decision turns (confirm / cancel) do NOT count against the budget.
// -----------------------------------------------------------------------------

if (!$isDecisionTurn) {
    try {
        $rl = ai_rate_limit_check($con, $user_id);
    } catch (Throwable $e) {
        // Fail-open on rate-limit infra error: log and continue.
        error_log('ai_chat rate-limit infra error: ' . $e->getMessage());
        $rl = ['ok' => true];
    }
    if (!$rl['ok']) {
        ai_rate_limit_record_hit($con, $user_id, $rl['window'], $rl['limit'], $rl['used']);
        $window = $rl['window'] === 'minute' ? 'minute' : 'day';
        echo json_encode([
            'ok'    => false,
            'reply' => "You have reached your AI usage limit for this $window. Please try again later.",
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }
}

// -----------------------------------------------------------------------------
// Admin gate: chatbot must be enabled AND the active provider's key must be
// configured. Provider selection lives in ai_settings.llm_provider; the
// chatbot itself does not care which one is in use.
// -----------------------------------------------------------------------------

try {
    $chatbotEnabled = ai_settings_get('chatbot_enabled') === '1';
    $llmConfig      = llm_get_active_config();
    $systemPrompt   = ai_settings_get('system_prompt') ?: "You are MyVivarium's AI assistant.";
    // Compact override: aggressively short system prompt to fit the LLM
    // token cap. Admin-set system_prompt is still honored but kept compact.
    $systemPrompt   = trim($systemPrompt);
    if (strlen($systemPrompt) > 400) {
        $systemPrompt = mb_substr($systemPrompt, 0, 400);
    }
} catch (Throwable $e) {
    error_log('ai_chat ai_settings error: ' . $e->getMessage());
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'chatbot_unavailable']);
    exit;
}
if (!$chatbotEnabled || $llmConfig['api_key'] === '') {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'chatbot_disabled']);
    exit;
}
$activeProvider = $llmConfig['provider'];
$activeModel    = $llmConfig['model'];

// -----------------------------------------------------------------------------
// Session API key (mint if needed)
// -----------------------------------------------------------------------------

$sessionApiKey = chatbot_session_key_get($con, $user_id, $username);

// -----------------------------------------------------------------------------
// Conversation persistence helpers
// -----------------------------------------------------------------------------

function chatbot_conversation_load(mysqli $con, int $user_id, string $cid): ?array
{
    $stmt = $con->prepare("SELECT id, user_id, title FROM ai_conversations WHERE id = ? AND user_id = ?");
    $stmt->bind_param('si', $cid, $user_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $row;
}

function chatbot_conversation_create(mysqli $con, int $user_id): string
{
    $id = svc_uuid_v4();
    $stmt = $con->prepare("INSERT INTO ai_conversations (id, user_id) VALUES (?, ?)");
    $stmt->bind_param('si', $id, $user_id);
    $stmt->execute();
    $stmt->close();
    return $id;
}

function chatbot_message_persist(mysqli $con, string $cid, string $role, ?string $content, ?array $tool_call = null, ?array $tool_result = null, ?string $pending_op_id = null, ?array $suggestions = null): int
{
    $callJson        = $tool_call   ? json_encode($tool_call,   JSON_UNESCAPED_SLASHES) : null;
    $resultJson      = $tool_result ? json_encode($tool_result, JSON_UNESCAPED_SLASHES) : null;
    $suggestionsJson = is_array($suggestions) ? json_encode(array_values($suggestions), JSON_UNESCAPED_SLASHES) : null;

    // suggestions_json was added in a later migration; if the column is
    // absent (older DB), fall back to the legacy 6-column INSERT so the
    // chatbot keeps working until the operator runs database/api_setup.php.
    static $hasSuggCol = null;
    if ($hasSuggCol === null) {
        $r = @$con->query("SHOW COLUMNS FROM ai_messages LIKE 'suggestions_json'");
        $hasSuggCol = ($r && $r->num_rows > 0);
        if ($r) $r->close();
    }

    if ($hasSuggCol) {
        $stmt = $con->prepare("INSERT INTO ai_messages (conversation_id, role, content, tool_call_json, tool_result_json, pending_op_id, suggestions_json) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param('sssssss', $cid, $role, $content, $callJson, $resultJson, $pending_op_id, $suggestionsJson);
    } else {
        $stmt = $con->prepare("INSERT INTO ai_messages (conversation_id, role, content, tool_call_json, tool_result_json, pending_op_id) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param('ssssss', $cid, $role, $content, $callJson, $resultJson, $pending_op_id);
    }
    $stmt->execute();
    $id = (int)$stmt->insert_id;
    $stmt->close();

    // Bump conversation updated_at so it sorts to the top of the history list.
    $u = $con->prepare("UPDATE ai_conversations SET updated_at = CURRENT_TIMESTAMP WHERE id = ?");
    $u->bind_param('s', $cid);
    $u->execute();
    $u->close();
    return $id;
}

function chatbot_messages_recent(mysqli $con, string $cid, int $limit = 10): array
{
    // Last $limit messages, oldest-first for Groq.
    $stmt = $con->prepare("SELECT role, content, tool_call_json, tool_result_json
                             FROM ai_messages
                            WHERE conversation_id = ?
                            ORDER BY id DESC LIMIT ?");
    $stmt->bind_param('si', $cid, $limit);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return array_reverse($rows);
}

function chatbot_context_message_limit(): int
{
    $raw = ai_settings_get('chatbot_context_messages');
    $n = is_numeric($raw) ? (int)$raw : 10;
    if ($n < 4) $n = 4;
    if ($n > 30) $n = 30;
    return $n;
}

function chatbot_usage_log(mysqli $con, int $user_id, string $cid, string $model, array $usage, int $estPrompt = 0, string $provider = ''): void
{
    $pt = (int)($usage['prompt_tokens']     ?? 0);
    $ct = (int)($usage['completion_tokens'] ?? 0);
    // ai_usage_log may or may not have estimated_prompt_tokens / provider
    // columns (added in subsequent schema revisions). Detect & fall back.
    static $hasEstCol = null;
    static $hasProvCol = null;
    if ($hasEstCol === null) {
        $r = @$con->query("SHOW COLUMNS FROM ai_usage_log LIKE 'estimated_prompt_tokens'");
        $hasEstCol = ($r && $r->num_rows > 0);
        if ($r) $r->close();
    }
    if ($hasProvCol === null) {
        $r = @$con->query("SHOW COLUMNS FROM ai_usage_log LIKE 'provider'");
        $hasProvCol = ($r && $r->num_rows > 0);
        if ($r) $r->close();
    }
    if ($hasEstCol && $hasProvCol) {
        $stmt = $con->prepare("INSERT INTO ai_usage_log (user_id, conversation_id, prompt_tokens, completion_tokens, model, estimated_prompt_tokens, provider) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param('isiisis', $user_id, $cid, $pt, $ct, $model, $estPrompt, $provider);
    } elseif ($hasEstCol) {
        $stmt = $con->prepare("INSERT INTO ai_usage_log (user_id, conversation_id, prompt_tokens, completion_tokens, model, estimated_prompt_tokens) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param('isiisi', $user_id, $cid, $pt, $ct, $model, $estPrompt);
    } else {
        $stmt = $con->prepare("INSERT INTO ai_usage_log (user_id, conversation_id, prompt_tokens, completion_tokens, model) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param('isiis', $user_id, $cid, $pt, $ct, $model);
    }
    $stmt->execute();
    $stmt->close();
}

function chatbot_title_autogen(mysqli $con, string $cid, string $firstUserMessage): void
{
    $stmt = $con->prepare("SELECT title, (SELECT COUNT(*) FROM ai_messages WHERE conversation_id = ? AND role IN ('user','assistant')) AS turns FROM ai_conversations WHERE id = ?");
    $stmt->bind_param('ss', $cid, $cid);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) return;
    if ($row['title'] !== null && $row['title'] !== '') return;
    if ((int)$row['turns'] < 4) return;
    $title = mb_substr(trim($firstUserMessage), 0, 60);
    $u = $con->prepare("UPDATE ai_conversations SET title = ? WHERE id = ?");
    $u->bind_param('ss', $title, $cid);
    $u->execute();
    $u->close();
}

// -----------------------------------------------------------------------------
// Resolve / create conversation
// -----------------------------------------------------------------------------

$conv = null;
if ($conversation_id !== '') {
    $conv = chatbot_conversation_load($con, $user_id, $conversation_id);
}
if (!$conv) {
    $conversation_id = chatbot_conversation_create($con, $user_id);
} else {
    $conversation_id = $conv['id'];
}

// -----------------------------------------------------------------------------
// Tool definitions are loaded from MyVivarium's OpenAPI specification at
// api/openapi.yaml — see chatbot_all_tool_defs() in chatbot_helpers.php.
// chatbot_select_tools($userMessage) picks a relevant subset per turn.
// -----------------------------------------------------------------------------


// -----------------------------------------------------------------------------
// Tool dispatch: chatbot_resolve_tool() lives in includes/chatbot_helpers.php
// alongside chatbot_sanitize_for_llm() and chatbot_truncate(), so tests can
// import them without standing up the request pipeline.
// -----------------------------------------------------------------------------

// -----------------------------------------------------------------------------
// HTTP call to /api/v1/*
//
// Determines our own base URL from the current request so the chatbot truly
// hits the same host. Tests can override with CHATBOT_API_BASE in env.
// -----------------------------------------------------------------------------

function chatbot_api_base(): string
{
    $override = $_ENV['CHATBOT_API_BASE'] ?? getenv('CHATBOT_API_BASE');
    if (is_string($override) && $override !== '') return rtrim($override, '/');

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host;
}

/**
 * Make one HTTP call to the local REST API.
 *
 * Returns ['status' => int, 'body' => array, 'raw' => string, 'content_type' => string].
 * On 401 the caller is expected to mint a fresh session key and retry once;
 * this function does not auto-retry.
 *
 * Hard guard: if the response is not 2xx OR the content-type is not
 * application/json, body is replaced with a synthetic JSON error envelope.
 * This prevents Groq from ever seeing raw HTML (e.g. a login page) as a
 * tool result and looping on it.
 */
function chatbot_api_call(string $sessionKey, string $method, string $path, array $query, array $body, ?string $confirmToken): array
{
    $base = chatbot_api_base() . '/api/v1' . $path;
    if ($query) {
        $base .= '?' . http_build_query($query);
    }

    $ch = curl_init($base);
    $headers = [
        'X-API-Key: ' . $sessionKey,
        'Accept: application/json',
        'Content-Type: application/json',
    ];
    if ($confirmToken !== null && $confirmToken !== '') {
        $headers[] = 'X-Confirm-Token: ' . $confirmToken;
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_FOLLOWLOCATION => false,   // never follow a redirect to a login page
        CURLOPT_MAXREDIRS      => 0,
        CURLOPT_SSL_VERIFYPEER => false,   // self-hosted, dev-friendly
        CURLOPT_SSL_VERIFYHOST => 0,
    ]);
    if ($method !== 'GET' && $method !== 'DELETE') {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_SLASHES));
    }
    $raw = curl_exec($ch);
    if ($raw === false) {
        $err = curl_error($ch);
        curl_close($ch);
        return [
            'status' => 0,
            'body'   => ['ok' => false, 'error' => ['code' => 'network', 'message' => $err]],
            'raw'    => $err,
            'content_type' => '',
        ];
    }
    $status      = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);

    $isJsonCT = stripos($contentType, 'application/json') !== false;
    $decoded  = $isJsonCT ? json_decode($raw, true) : null;

    // Hard guard: any non-JSON response (HTML login page, gateway error,
    // proxy banner) becomes a structured error so Groq does NOT see the
    // raw HTML — that's what was triggering retry storms and dumping the
    // login page into the chat reply.
    if (!$isJsonCT || !is_array($decoded)) {
        $snippet = substr(preg_replace('/\s+/', ' ', strip_tags((string)$raw)), 0, 200);
        return [
            'status' => $status,
            'body'   => ['ok' => false, 'error' => [
                'code'    => 'api_not_json',
                'message' => "The API returned a non-JSON response with status $status (content-type: " . ($contentType ?: 'unknown') . ").",
                'snippet' => $snippet,
            ]],
            'raw'          => (string)$raw,
            'content_type' => $contentType,
        ];
    }

    // JSON came back but the API itself reported non-2xx (e.g. 401).
    return [
        'status'       => $status,
        'body'         => $decoded,
        'raw'          => (string)$raw,
        'content_type' => $contentType,
    ];
}

/**
 * Call the API, transparently re-minting the session key on 401.
 */
function chatbot_api_call_with_retry(mysqli $con, int $user_id, string $username, string $method, string $path, array $query, array $body, ?string $confirmToken): array
{
    $key = chatbot_session_key_get($con, $user_id, $username);
    $res = chatbot_api_call($key, $method, $path, $query, $body, $confirmToken);
    if ($res['status'] === 401) {
        $snippet = substr((string)($res['raw'] ?? ''), 0, 200);
        error_log("Chatbot session key rejected by API (user $user_id), body: $snippet");
        $key = chatbot_session_key_mint($con, $user_id, $username);
        $res = chatbot_api_call($key, $method, $path, $query, $body, $confirmToken);
    }
    return $res;
}

/**
 * Ping the API surface. Used once per conversation turn so we fail fast
 * with a clear message if /api/v1/* is being misrouted (e.g. to the login
 * HTML) instead of dragging Groq through a retry storm.
 */
function chatbot_api_health(): array
{
    $url = chatbot_api_base() . '/api/v1/health';
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_MAXREDIRS      => 0,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
    ]);
    $raw = curl_exec($ch);
    if ($raw === false) {
        $err = curl_error($ch);
        curl_close($ch);
        return ['ok' => false, 'status' => 0, 'reason' => 'network: ' . $err];
    }
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $ct     = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);
    if ($status < 200 || $status >= 300) {
        return ['ok' => false, 'status' => $status, 'reason' => "status $status"];
    }
    if (stripos($ct, 'application/json') === false) {
        return ['ok' => false, 'status' => $status, 'reason' => "non-JSON content-type ($ct)"];
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded) || empty($decoded['ok'])) {
        return ['ok' => false, 'status' => $status, 'reason' => 'unexpected JSON shape'];
    }
    return ['ok' => true, 'status' => $status, 'body' => $decoded];
}

// -----------------------------------------------------------------------------
// LLM call — all chat-completions HTTP goes through includes/llm_provider.php
// so the chatbot never depends on which provider is configured.
// -----------------------------------------------------------------------------

// -----------------------------------------------------------------------------
// Build the message stack for the LLM from persisted history + system prompt.
// -----------------------------------------------------------------------------

function chatbot_build_messages(mysqli $con, string $cid, string $systemPrompt, string $username, int $user_id): array
{
    // Hardcoded blocks. NOT admin-editable: prepended to the
    // admin-configured system prompt so an admin can't accidentally remove
    // the safety rules, formatting rules, or follow-up suggestion contract.
    $hardcoded   = chatbot_security_rules_block();
    $formatting  = chatbot_response_formatting_rules_block();
    $suggestions = chatbot_follow_up_suggestions_block();

    // Compact admin-configurable system prompt — role / capabilities /
    // runtime — under ~300 tokens. Verbose phrasing was the single largest
    // per-call payload contributor.
    $augmented = $hardcoded . "\n\n" . $formatting . "\n\n" . $suggestions . "\n\n" . $systemPrompt
        . " Tool definitions are loaded from MyVivarium's OpenAPI specification."
        . " If the user asks for something you do not have a tool for, tell them honestly and list 3-5 example things you CAN do based on the available tools (call listCapabilities)."
        . " Do not invent tools or substitute a different tool for what the user asked."
        . " User: $username (id $user_id). Time: " . date('c') . ".";

    $messages = [['role' => 'system', 'content' => $augmented]];

    $msgLimit = chatbot_context_message_limit();
    foreach (chatbot_messages_recent($con, $cid, $msgLimit) as $r) {
        if ($r['role'] === 'system_event') {
            // Hint Groq about prior pending op decisions etc.
            $messages[] = ['role' => 'system', 'content' => '[event] ' . ($r['content'] ?? '')];
            continue;
        }
        if ($r['role'] === 'tool') {
            $call = $r['tool_call_json'] ? json_decode($r['tool_call_json'], true) : null;
            $content = $r['content'] ?? '';
            $msg = [
                'role'    => 'tool',
                'content' => $content,
            ];
            if (is_array($call) && !empty($call['id'])) {
                $msg['tool_call_id'] = $call['id'];
            }
            $messages[] = $msg;
            continue;
        }
        if ($r['role'] === 'assistant') {
            $call = $r['tool_call_json'] ? json_decode($r['tool_call_json'], true) : null;
            $msg = ['role' => 'assistant', 'content' => $r['content'] ?? ''];
            if (is_array($call) && isset($call['tool_calls'])) {
                $msg['tool_calls'] = $call['tool_calls'];
                // OpenAI/Groq schema: content may be null when tool_calls present.
                if ($msg['content'] === '') $msg['content'] = null;
            }
            $messages[] = $msg;
            continue;
        }
        // role=user
        $messages[] = ['role' => 'user', 'content' => $r['content'] ?? ''];
    }

    // Hard token budget on the conversation portion. If the joined history
    // alone exceeds 4000 estimated tokens, drop the oldest non-system message
    // until we're back under budget.
    $budgetTokens = 4000;
    while (count($messages) > 2) {
        $est = chatbot_estimate_tokens($messages);
        if ($est <= $budgetTokens) break;
        // Find the first non-system message and drop it (preserve [0] system).
        for ($i = 1; $i < count($messages); $i++) {
            if (($messages[$i]['role'] ?? '') !== 'system') {
                array_splice($messages, $i, 1);
                break;
            }
        }
        if ($i >= count($messages)) break; // safety
    }
    return $messages;
}

/**
 * Final aggressive trim before sending to Groq. Estimates tokens for
 * (system prompt + tools + messages); if over $hardCap, drops the largest
 * tool-result messages, then the oldest non-system messages, until under.
 * Logs the final estimate so the admin can see what each turn is using.
 */
function chatbot_fit_to_budget(array $messages, array $tools, int $hardCap = 8000): array
{
    $est = chatbot_estimate_tokens($messages) + chatbot_estimate_tokens($tools);
    if ($est <= $hardCap) {
        error_log("Chatbot turn estimated $est tokens (cap $hardCap)");
        return $messages;
    }

    // Pass 1: shrink the biggest tool results.
    while ($est > $hardCap) {
        $biggestIdx = -1; $biggestLen = 0;
        foreach ($messages as $i => $m) {
            if (($m['role'] ?? '') !== 'tool') continue;
            $len = strlen((string)($m['content'] ?? ''));
            if ($len > $biggestLen) { $biggestLen = $len; $biggestIdx = $i; }
        }
        if ($biggestIdx < 0 || $biggestLen <= 500) break;
        $messages[$biggestIdx]['content'] = substr((string)$messages[$biggestIdx]['content'], 0, 500) . ' ...[trimmed for budget]';
        $est = chatbot_estimate_tokens($messages) + chatbot_estimate_tokens($tools);
    }

    // Pass 2: drop oldest non-system messages.
    while ($est > $hardCap && count($messages) > 2) {
        for ($i = 1; $i < count($messages); $i++) {
            if (($messages[$i]['role'] ?? '') !== 'system') {
                array_splice($messages, $i, 1);
                break;
            }
        }
        $est = chatbot_estimate_tokens($messages) + chatbot_estimate_tokens($tools);
    }
    error_log("Chatbot turn estimated $est tokens (cap $hardCap, after trim)");
    return $messages;
}

// -----------------------------------------------------------------------------
// Handle the cancel-pending-op branch (no Groq tool loop).
// -----------------------------------------------------------------------------

if ($cancel_pending_op !== '') {
    chatbot_message_persist($con, $conversation_id, 'system_event',
        'User cancelled pending operation ' . $cancel_pending_op . '.',
        null, null, $cancel_pending_op);

    // Build a tiny message sequence telling Groq about the cancellation and
    // get a one-line acknowledgement.
    $messages = chatbot_build_messages($con, $conversation_id, $systemPrompt, $username, $user_id);
    $messages[] = ['role' => 'user', 'content' => 'I cancelled the pending action — please acknowledge briefly.'];
    // Cancellation reply doesn't need tools — empty tools array.
    $tools = [];
    $messages = chatbot_fit_to_budget($messages, $tools);
    $estPrompt = chatbot_estimate_tokens($messages) + chatbot_estimate_tokens($tools);
    $llm = llm_chat_completions($messages, $tools);
    if ($llm['ok']) {
        $reply = $llm['body']['choices'][0]['message']['content'] ?? 'Cancelled.';
        if (!is_string($reply) || $reply === '') $reply = 'Cancelled.';
        if (isset($llm['body']['usage'])) {
            $u = $llm['body']['usage'];
            error_log("Chatbot call: prompt=" . (int)($u['prompt_tokens'] ?? 0) . ", completion=" . (int)($u['completion_tokens'] ?? 0) . ", provider={$llm['provider']}, model={$llm['model']}, est=$estPrompt");
            chatbot_usage_log($con, $user_id, $conversation_id, $llm['model'], $u, $estPrompt, $llm['provider']);
        }
    } else {
        $reply = 'Cancelled.';
    }
    // No follow-up suggestions on cancellation acknowledgements — the
    // conversation is in a transitional state.
    $parsed = chatbot_parse_suggestions($reply);
    $reply  = $parsed['content'] !== '' ? $parsed['content'] : 'Cancelled.';
    chatbot_message_persist($con, $conversation_id, 'assistant', $reply, null, null, null, []);
    log_activity($con, 'ai_chat_message', 'ai_conversation', $conversation_id, 'cancel');
    echo json_encode([
        'ok' => true,
        'conversation_id' => $conversation_id,
        'reply' => $reply,
        'tool_calls' => [],
        'pending_confirmation' => null,
        'suggestions' => [],
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

// -----------------------------------------------------------------------------
// Handle confirm-pending-op: replay the original tool call with the token.
// We persisted the tool_call_json that produced the pending op; we look it
// up so we know which tool to re-invoke and where to send the result.
// -----------------------------------------------------------------------------

$replayToolName = null;
$replayToolArgs = null;
$replayToolCallId = null;
if ($confirm_pending_op !== '') {
    $stmt = $con->prepare("SELECT content, tool_call_json FROM ai_messages
                            WHERE conversation_id = ? AND pending_op_id = ? AND role = 'system_event'
                            ORDER BY id DESC LIMIT 1");
    $stmt->bind_param('ss', $conversation_id, $confirm_pending_op);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row || !$row['tool_call_json']) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'unknown_pending_op']);
        exit;
    }
    $tc = json_decode($row['tool_call_json'], true);
    $replayToolName    = $tc['function']['name'] ?? null;
    $replayToolArgs    = isset($tc['function']['arguments']) ? (is_string($tc['function']['arguments']) ? (json_decode($tc['function']['arguments'], true) ?: []) : $tc['function']['arguments']) : [];
    $replayToolCallId  = $tc['id'] ?? ('call_' . substr(md5($confirm_pending_op), 0, 12));
}

// -----------------------------------------------------------------------------
// Persist the user's message (when this is a normal turn or a confirm turn).
// -----------------------------------------------------------------------------

$firstUserMessageForTitle = $incomingMessage;
if ($incomingMessage !== '') {
    chatbot_message_persist($con, $conversation_id, 'user', $incomingMessage);
    log_activity($con, 'ai_chat_message', 'ai_conversation', $conversation_id, 'message');
}

// -----------------------------------------------------------------------------
// Main loop
// -----------------------------------------------------------------------------

$MAX_ITERATIONS = 6;
$toolCallsForResponse = [];
$pendingConfirmation = null;
$finalReply = '';
$finalSuggestions = [];
// When the LLM yields a normal "no more tools" final message, we defer its
// persistence to the end of the script so we can strip the SUGGESTIONS::
// marker and store the parsed suggestions in the same row.
$finalReplyDeferred = false;
$toolsForTurn = chatbot_select_tools($incomingMessage);

// Health pre-check: if /api/v1 is unreachable or returning HTML, abort
// before we burn Groq tokens looping on bad tool results.
$health = chatbot_api_health();
if (!$health['ok']) {
    error_log("Chatbot health check failed for user $user_id: " . ($health['reason'] ?? 'unknown'));
    $finalReply = 'The API is not responding correctly. Check that /api/v1 is reachable and the session key is valid.';
    chatbot_message_persist($con, $conversation_id, 'assistant', $finalReply, null, null, null, []);
    echo json_encode([
        'ok'                  => true,
        'reply'               => $finalReply,
        'conversation_id'     => $conversation_id,
        'tool_calls'          => [],
        'pending_confirmation'=> null,
        'suggestions'         => [],
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

// Track repeated failures of the same tool call so we can bail out of the
// loop rather than letting Groq retry indefinitely on a broken API.
$toolFailureStreak = [];

try {
    for ($iter = 0; $iter < $MAX_ITERATIONS; $iter++) {

        // Special case: first iteration of a confirm turn replays the
        // original destructive tool with the real confirm token, skipping
        // the Groq round-trip that would otherwise re-decide.
        if ($iter === 0 && $confirm_pending_op !== '' && $replayToolName !== null) {
            $resolved = chatbot_resolve_tool($replayToolName, is_array($replayToolArgs) ? $replayToolArgs : []);
            if (!$resolved) {
                $finalReply = 'That action expired, please ask again.';
                chatbot_message_persist($con, $conversation_id, 'assistant', $finalReply);
                break;
            }
            $apiRes = chatbot_api_call_with_retry($con, $user_id, $username, $resolved['method'], $resolved['path'], $resolved['query'], $resolved['body'], $confirm_pending_op);
            $toolCallsForResponse[] = ['name' => $replayToolName, 'status' => $apiRes['status'], 'request' => ['method' => $resolved['method'], 'path' => $resolved['path']]];
            log_activity($con, 'ai_chat_write', 'ai_conversation', $conversation_id, $replayToolName . ' pending_op=' . $confirm_pending_op);

            if ($apiRes['status'] === 410) {
                $finalReply = 'That action expired, please ask again.';
                chatbot_message_persist($con, $conversation_id, 'system_event', 'Pending op ' . $confirm_pending_op . ' expired before confirmation.', null, null, $confirm_pending_op);
                chatbot_message_persist($con, $conversation_id, 'assistant', $finalReply);
                break;
            }

            $taggedBody = is_array($apiRes['body'])
                ? chatbot_tag_user_content($apiRes['body'], chatbot_user_content_fields_for($replayToolName))
                : $apiRes['body'];
            $rawResult = chatbot_sanitize_for_llm(json_encode($taggedBody, JSON_UNESCAPED_SLASHES));
            $rawResult = chatbot_cap_list_result($replayToolName, $rawResult);
            $resultStr = chatbot_truncate($rawResult);
            chatbot_message_persist($con, $conversation_id, 'tool', $resultStr,
                ['id' => $replayToolCallId, 'function' => ['name' => $replayToolName]],
                ['status' => $apiRes['status'], 'body' => $apiRes['body']],
                $confirm_pending_op
            );
            // Fall through to a Groq call for the natural-language reply.
        }

        $messages = chatbot_build_messages($con, $conversation_id, $systemPrompt, $username, $user_id);
        // Per-turn tool subset: route by the user's message on iteration 0;
        // afterwards just keep the same set so Groq can finish a multi-call
        // workflow without losing visibility on tools it already used.
        if ($iter === 0) {
            $toolsForTurn = chatbot_select_tools($incomingMessage);
        }
        $messages = chatbot_fit_to_budget($messages, $toolsForTurn);
        $estPrompt = chatbot_estimate_tokens($messages) + chatbot_estimate_tokens($toolsForTurn);
        $llm = llm_chat_completions($messages, $toolsForTurn);

        if (!$llm['ok']) {
            error_log('chatbot llm error provider=' . $llm['provider'] . ' status=' . $llm['status'] . ' err=' . ($llm['error'] ?? '') . ' raw=' . ($llm['raw'] ?? ''));
            if ($llm['status'] === 429) {
                $finalReply = 'I am getting too many requests right now, try again in a minute.';
            } else {
                $finalReply = 'AI assistant is temporarily unavailable.';
            }
            chatbot_message_persist($con, $conversation_id, 'assistant', $finalReply);
            break;
        }

        if (isset($llm['body']['usage'])) {
            $u = $llm['body']['usage'];
            error_log("Chatbot call: prompt=" . (int)($u['prompt_tokens'] ?? 0) . ", completion=" . (int)($u['completion_tokens'] ?? 0) . ", provider={$llm['provider']}, model={$llm['model']}, est=$estPrompt");
            chatbot_usage_log($con, $user_id, $conversation_id, $llm['model'], $u, $estPrompt, $llm['provider']);
        }

        $choice = $llm['body']['choices'][0]['message'] ?? null;
        if (!$choice) {
            $finalReply = 'AI assistant returned an empty response.';
            chatbot_message_persist($con, $conversation_id, 'assistant', $finalReply);
            break;
        }
        $toolCalls = $choice['tool_calls'] ?? [];

        // No more tools to call → final assistant message. Defer persistence
        // until after we've stripped the SUGGESTIONS:: marker out so the
        // stored content matches what the user sees, and so the parsed
        // suggestions land in the same DB row.
        if (empty($toolCalls)) {
            $finalReply = (string)($choice['content'] ?? '');
            $finalReplyDeferred = true;
            break;
        }

        // Persist assistant's "I want to call these tools" turn before we
        // resolve them, so the next Groq call sees the same shape.
        chatbot_message_persist($con, $conversation_id, 'assistant',
            (string)($choice['content'] ?? ''),
            ['tool_calls' => $toolCalls],
            null
        );

        $hadPending = false;
        foreach ($toolCalls as $tc) {
            $name = $tc['function']['name'] ?? '';
            $args = $tc['function']['arguments'] ?? [];
            if (is_string($args)) $args = json_decode($args, true) ?: [];
            // Pseudo-tool: listCapabilities answers from the loaded spec
            // without making any HTTP call.
            if ($name === 'listCapabilities') {
                $body = chatbot_list_capabilities();
                $resultStr = chatbot_truncate(json_encode($body, JSON_UNESCAPED_SLASHES));
                chatbot_message_persist($con, $conversation_id, 'tool', $resultStr,
                    ['id' => $tc['id'] ?? null, 'function' => ['name' => $name]],
                    ['status' => 200, 'body' => $body]
                );
                $toolCallsForResponse[] = ['name' => $name, 'status' => 200];
                continue;
            }

            $resolved = chatbot_resolve_tool($name, $args);
            if (!$resolved) {
                $resultStr = json_encode(['ok' => false, 'error' => 'unknown_tool', 'tool' => $name]);
                chatbot_message_persist($con, $conversation_id, 'tool', $resultStr,
                    ['id' => $tc['id'] ?? null, 'function' => ['name' => $name]],
                    ['error' => 'unknown_tool']
                );
                $toolCallsForResponse[] = ['name' => $name, 'status' => 0, 'error' => 'unknown_tool'];
                continue;
            }

            $confirmHeader = $resolved['destructive'] ? 'pending' : null;
            $apiRes = chatbot_api_call_with_retry($con, $user_id, $username, $resolved['method'], $resolved['path'], $resolved['query'], $resolved['body'], $confirmHeader);

            $toolCallsForResponse[] = [
                'name'   => $name,
                'status' => $apiRes['status'],
                'request' => ['method' => $resolved['method'], 'path' => $resolved['path']],
            ];

            // Loop guard: if the same tool keeps coming back with a
            // non-2xx / non-JSON response, abort the turn instead of
            // letting Groq spin on bad data. Two strikes per tool.
            $isFailure = $apiRes['status'] < 200 || $apiRes['status'] >= 300;
            $isNotJson = isset($apiRes['body']['error']['code']) && $apiRes['body']['error']['code'] === 'api_not_json';
            if ($isFailure || $isNotJson) {
                $toolFailureStreak[$name] = ($toolFailureStreak[$name] ?? 0) + 1;
                if ($toolFailureStreak[$name] >= 2) {
                    error_log("Chatbot aborting loop: tool '$name' failed " . $toolFailureStreak[$name] . "x in one turn (status={$apiRes['status']})");
                    $finalReply = 'The API is not responding correctly. Check that /api/v1 is reachable and the session key is valid.';
                    chatbot_message_persist($con, $conversation_id, 'tool',
                        json_encode($apiRes['body'], JSON_UNESCAPED_SLASHES),
                        ['id' => $tc['id'] ?? null, 'function' => ['name' => $name]],
                        ['status' => $apiRes['status'], 'body' => $apiRes['body']]
                    );
                    chatbot_message_persist($con, $conversation_id, 'assistant', $finalReply, null, null, null, []);
                    echo json_encode([
                        'ok'                  => true,
                        'reply'               => $finalReply,
                        'conversation_id'     => $conversation_id,
                        'tool_calls'          => $toolCallsForResponse,
                        'pending_confirmation'=> null,
                        'suggestions'         => [],
                    ], JSON_UNESCAPED_SLASHES);
                    exit;
                }
            } else {
                $toolFailureStreak[$name] = 0;
            }

            // Destructive tool → 202 with pending_operation_id. Halt the
            // loop and surface the confirmation card to the browser.
            if ($apiRes['status'] === 202 && isset($apiRes['body']['data']['pending_operation_id'])) {
                $pop = $apiRes['body']['data'];
                $pendingConfirmation = [
                    'pending_operation_id' => $pop['pending_operation_id'],
                    'summary'              => $pop['diff']['summary'] ?? ('Confirm ' . $name),
                    'diff'                 => $pop['diff'] ?? [],
                    'expires_at'           => $pop['expires_at'] ?? null,
                    'tool_name'            => $name,
                ];
                chatbot_message_persist($con, $conversation_id, 'system_event',
                    "Pending destructive op: $name (id={$pop['pending_operation_id']}) — awaiting user confirmation.",
                    ['id' => $tc['id'] ?? null, 'function' => ['name' => $name, 'arguments' => is_array($args) ? json_encode($args) : $args]],
                    ['status' => 202, 'pending_operation_id' => $pop['pending_operation_id'], 'diff' => $pop['diff'] ?? null],
                    (string)$pop['pending_operation_id']
                );
                $hadPending = true;
                break; // stop processing further tool_calls this turn
            }

            // Log writes (non-202, non-error).
            if (in_array($resolved['method'], ['POST', 'PATCH', 'DELETE'], true) && $apiRes['status'] >= 200 && $apiRes['status'] < 300) {
                log_activity($con, 'ai_chat_write', 'ai_conversation', $conversation_id, $name);
            }

            $taggedBody = is_array($apiRes['body'])
                ? chatbot_tag_user_content($apiRes['body'], chatbot_user_content_fields_for($name))
                : $apiRes['body'];
            $rawResult = chatbot_sanitize_for_llm(json_encode($taggedBody, JSON_UNESCAPED_SLASHES));
            $rawResult = chatbot_cap_list_result($name, $rawResult);
            $resultStr = chatbot_truncate($rawResult);
            chatbot_message_persist($con, $conversation_id, 'tool', $resultStr,
                ['id' => $tc['id'] ?? null, 'function' => ['name' => $name]],
                ['status' => $apiRes['status'], 'body' => $apiRes['body']]
            );
        }

        if ($hadPending) {
            // We have a pending_confirmation to return; do NOT call Groq
            // again — that would lose the requirement that the user
            // approve. The browser will post back with confirm/cancel.
            $finalReply = $pendingConfirmation['summary'] ?? 'Please confirm to continue.';
            break;
        }
        // Otherwise loop: feed tool results back to Groq for the next move.
    }

    if ($iter >= $MAX_ITERATIONS && $finalReply === '') {
        $finalReply = 'I hit the tool-call limit for this turn; please rephrase.';
        chatbot_message_persist($con, $conversation_id, 'assistant', $finalReply, null, null, null, []);
    }
} catch (Throwable $e) {
    error_log('ai_chat fatal: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
    $finalReply = 'AI assistant is temporarily unavailable.';
}

// Suggestion parsing + fallback. Only applies when the LLM produced a real
// final assistant reply (deferred persistence path). Pending-confirmation
// turns get no suggestions because the confirm/cancel buttons drive the
// next step. Error and abort turns kept empty suggestions inline.
if ($finalReplyDeferred && $pendingConfirmation === null) {
    $parsed     = chatbot_parse_suggestions($finalReply);
    $finalReply = $parsed['content'];
    $finalSuggestions = $parsed['suggestions'];
    if (empty($finalSuggestions)) {
        $finalSuggestions = chatbot_fallback_suggestions($toolCallsForResponse, $finalReply);
    }
    chatbot_message_persist($con, $conversation_id, 'assistant', $finalReply, null, null, null, $finalSuggestions);
}

// Auto-title once we have a few turns under our belt.
if ($firstUserMessageForTitle !== '') {
    chatbot_title_autogen($con, $conversation_id, $firstUserMessageForTitle);
}

echo json_encode([
    'ok'                  => true,
    'reply'               => $finalReply,
    'conversation_id'     => $conversation_id,
    'tool_calls'          => $toolCallsForResponse,
    'pending_confirmation'=> $pendingConfirmation,
    'suggestions'         => $finalSuggestions,
], JSON_UNESCAPED_SLASHES);
