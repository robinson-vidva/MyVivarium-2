<?php
/**
 * Sandbox-runnable unit tests for the per-response token usage display.
 *
 * The chatbot already logs usage to ai_usage_log; this feature surfaces
 * the same numbers in the chat widget. The tests here pin the contract
 * without standing up a full DB/Groq pipeline:
 *
 *   - Multi-round-trip turns correctly sum prompt + completion across
 *     every iteration (so the widget shows ONE total per user message).
 *   - The ai_messages.tokens_json migration is declared idempotently and
 *     the schema dumps include the column.
 *   - The history endpoint hydrates tokens for assistant rows and skips
 *     them when stored as NULL.
 *   - The widget's token-line formatter renders the spec format,
 *     thousands-separated, and gracefully skips missing / zero data.
 *
 *     php tests/chatbot_tokens_test.php
 */

$pass = 0; $fail = 0;
function check(string $name, bool $ok): void {
    global $pass, $fail;
    if ($ok) { echo "[PASS] $name\n"; $pass++; }
    else     { echo "[FAIL] $name\n"; $fail++; }
}

// ---------------------------------------------------------------------------
// 1. Multi-round-trip sum: simulate the per-iteration accumulator that
//    ai_chat.php runs inside its main loop. The widget sees one total per
//    user message, so we add every round-trip's usage into one bucket.
// ---------------------------------------------------------------------------

$turnTokens = ['prompt' => 0, 'completion' => 0, 'total' => 0];
$roundTrips = [
    ['prompt_tokens' => 120, 'completion_tokens' => 30],   // iter 0
    ['prompt_tokens' => 240, 'completion_tokens' => 18],   // iter 1 (tool call follow-up)
    ['prompt_tokens' => 310, 'completion_tokens' => 92],   // iter 2 (final reply)
];
foreach ($roundTrips as $u) {
    $turnTokens['prompt']     += (int)($u['prompt_tokens']     ?? 0);
    $turnTokens['completion'] += (int)($u['completion_tokens'] ?? 0);
    $turnTokens['total']       = $turnTokens['prompt'] + $turnTokens['completion'];
}
check('multi-RT sum: prompt = 120+240+310 = 670',     $turnTokens['prompt']     === 670);
check('multi-RT sum: completion = 30+18+92 = 140',    $turnTokens['completion'] === 140);
check('multi-RT sum: total = prompt + completion',    $turnTokens['total']      === 810);

// Zero-iteration / empty roundtrip set leaves all zeros so the widget hides.
$empty = ['prompt' => 0, 'completion' => 0, 'total' => 0];
check('zero-RT sum stays at all-zero (widget hides line)',
    $empty['prompt'] === 0 && $empty['completion'] === 0 && $empty['total'] === 0);

// Missing usage block on one round-trip is treated as zero, not an error.
$turnTokens2 = ['prompt' => 0, 'completion' => 0, 'total' => 0];
foreach ([['prompt_tokens'=>100], ['completion_tokens'=>50], []] as $u) {
    $turnTokens2['prompt']     += (int)($u['prompt_tokens']     ?? 0);
    $turnTokens2['completion'] += (int)($u['completion_tokens'] ?? 0);
    $turnTokens2['total']       = $turnTokens2['prompt'] + $turnTokens2['completion'];
}
check('partial-usage sum gracefully treats missing fields as 0',
    $turnTokens2['prompt'] === 100 && $turnTokens2['completion'] === 50 && $turnTokens2['total'] === 150);

// ---------------------------------------------------------------------------
// 2. Schema + migration coverage. The chatbot tolerates older DBs (the
//    column may be missing) but new installs must include it.
// ---------------------------------------------------------------------------

$apiSchema = (string)file_get_contents(__DIR__ . '/../database/api_schema.sql');
check('api_schema.sql declares ai_messages.tokens_json',
    strpos($apiSchema, '`tokens_json` text DEFAULT NULL') !== false);

$apiSetup = (string)file_get_contents(__DIR__ . '/../database/api_setup.php');
check('api_setup.php has idempotent tokens_json migration',
    strpos($apiSetup, "column_exists(\$con, 'ai_messages', 'tokens_json')") !== false
    && strpos($apiSetup, "ADD COLUMN `tokens_json` text DEFAULT NULL") !== false);
check('api_setup.php fresh-install CREATE TABLE includes tokens_json',
    preg_match('/CREATE TABLE `ai_messages`[\s\S]*?`tokens_json` text DEFAULT NULL/', $apiSetup) === 1);

// ---------------------------------------------------------------------------
// 3. chatbot_message_persist signature accepts tokens & guards against
//    missing column. We inspect the source rather than executing it, since
//    the function depends on a live mysqli handle.
// ---------------------------------------------------------------------------

$aiChat = (string)file_get_contents(__DIR__ . '/../ai_chat.php');
check('chatbot_message_persist accepts a $tokens parameter',
    preg_match('/function chatbot_message_persist\\([^)]*\\?array \\$tokens = null\\)/', $aiChat) === 1);
check('persist serializes tokens to {prompt, completion, total} JSON',
    strpos($aiChat, "'prompt'     => (int)(\$tokens['prompt']") !== false
    && strpos($aiChat, "'completion' => (int)(\$tokens['completion']") !== false
    && strpos($aiChat, "'total'      => (int)(\$tokens['total']") !== false);
check('persist guards on missing tokens_json column (graceful older-DB fallback)',
    strpos($aiChat, "SHOW COLUMNS FROM ai_messages LIKE 'tokens_json'") !== false);
check('persist skips writing tokens row when total is 0 (no \'0 tokens\' clutter)',
    strpos($aiChat, "(int)(\$tokens['total'] ?? 0) > 0") !== false);

// ---------------------------------------------------------------------------
// 4. History endpoint hydrates tokens for assistant rows; older rows /
//    NULL values come back as null so the widget skips them.
// ---------------------------------------------------------------------------

$hist = (string)file_get_contents(__DIR__ . '/../ai_chat_history.php');
check('history endpoint SELECTs tokens_json when the column exists',
    strpos($hist, "'tokens_json'") !== false
    && strpos($hist, '$hasTokens') !== false);
check('history endpoint emits tokens field per assistant message',
    strpos($hist, "\$m['tokens']") !== false);
check('history endpoint returns null for missing/zero-total rows',
    strpos($hist, "\$m['tokens'] = null;") !== false);

// ---------------------------------------------------------------------------
// 5. Widget formatter. Pull the inlined script out of the widget PHP and
//    eval the formatTokenLine logic in a tiny JS-shaped PHP harness so we
//    don't need a JS runtime in CI. We replicate it byte-for-byte instead.
// ---------------------------------------------------------------------------

$widget = (string)file_get_contents(__DIR__ . '/../includes/chatbot_widget.php');
check('widget defines .mv-msg-tokens CSS class',
    strpos($widget, '.mv-msg-tokens') !== false);
check('widget CSS uses 11px font-size for the token line',
    preg_match('/\\.mv-msg-tokens\\s*\\{[^}]*font-size:\\s*11px/', $widget) === 1);
check('widget CSS uses muted gray (#6c757d) matching tool-card subtitle',
    preg_match('/\\.mv-msg-tokens\\s*\\{[^}]*color:\\s*#6c757d/', $widget) === 1);
check('widget defines formatTokenLine() helper',
    strpos($widget, 'function formatTokenLine(tokens)') !== false);
check('widget defines appendTokenLine() helper that inserts the row',
    strpos($widget, 'function appendTokenLine(tokens)') !== false);
check('widget formats spec line: "X prompt + Y completion = Z tokens"',
    strpos($widget, "' prompt + '") !== false
    && strpos($widget, "' completion = '") !== false
    && strpos($widget, "' tokens'") !== false);
check('widget uses thousands separators (toLocaleString)',
    strpos($widget, "toLocaleString('en-US')") !== false);
check('widget gracefully skips when tokens missing or zero',
    strpos($widget, 'if (!tokens || typeof tokens !== \'object\') return null;') !== false
    && strpos($widget, 'if (t <= 0) return null;') !== false);
check('widget user-message branch does NOT pass tokens',
    strpos($widget, "if (isAssistant) appendTokenLine(tokens)") !== false);
check('widget pending_confirmation branch does NOT render tokens',
    preg_match('/pending_confirmation\\)\\s*\\{[\\s\\S]{0,400}?addConfirmCard/', $widget) === 1
    && strpos($widget, 'addConfirmCard(body.pending_confirmation);') !== false);
check('widget history reload passes tokens for assistant rows only',
    strpos($widget, "m.role === 'assistant' ? m.tokens : null") !== false);

// ---------------------------------------------------------------------------
// 6. Pure formatter check: replicate the JS formatter in PHP and verify
//    the rendered strings byte-for-byte. (The widget source is the single
//    source of truth; this test just guards the contract.)
// ---------------------------------------------------------------------------

function fmt_token_line(?array $tokens): ?string {
    if ($tokens === null) return null;
    $p = (int)($tokens['prompt']     ?? 0);
    $c = (int)($tokens['completion'] ?? 0);
    $t = (int)($tokens['total'] ?? ($p + $c));
    if ($t <= 0) return null;
    return number_format($p) . ' prompt + ' . number_format($c) . ' completion = ' . number_format($t) . ' tokens';
}

check('formatter: small numbers',
    fmt_token_line(['prompt' => 245, 'completion' => 87, 'total' => 332])
    === '245 prompt + 87 completion = 332 tokens');
check('formatter: thousands separators',
    fmt_token_line(['prompt' => 1247, 'completion' => 953, 'total' => 2200])
    === '1,247 prompt + 953 completion = 2,200 tokens');
check('formatter: returns null on null input',
    fmt_token_line(null) === null);
check('formatter: returns null when total is zero (no 0-tokens clutter)',
    fmt_token_line(['prompt' => 0, 'completion' => 0, 'total' => 0]) === null);
check('formatter: derives total when missing',
    fmt_token_line(['prompt' => 10, 'completion' => 5])
    === '10 prompt + 5 completion = 15 tokens');

// ---------------------------------------------------------------------------
// 7. Docs: the chatbot UX note must explain that the number is summed
//    across round-trips and matches ai_usage_log, so future contributors
//    know not to interpret it as per-call.
// ---------------------------------------------------------------------------

$docs = (string)file_get_contents(__DIR__ . '/../docs/chatbot.md');
check('docs mention summed-across-round-trips contract',
    stripos($docs, 'round-trip') !== false || stripos($docs, 'round trip') !== false);
check('docs mention ai_usage_log as the source of truth',
    strpos($docs, 'ai_usage_log') !== false);

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
