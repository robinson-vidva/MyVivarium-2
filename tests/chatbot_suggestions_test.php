<?php
/**
 * Sandbox-runnable unit tests for the follow-up suggestion mechanism.
 *
 *   - chatbot_parse_suggestions() valid / invalid / empty / oversized / count cap
 *   - chatbot_fallback_suggestions() picks the right map entry per last tool
 *   - malformed AI output strips the marker line cleanly
 *   - the formatting + suggestions system-prompt blocks are present
 *
 *     php tests/chatbot_suggestions_test.php
 */

require_once __DIR__ . '/../includes/chatbot_helpers.php';

$pass = 0; $fail = 0;
function check(string $name, bool $ok): void {
    global $pass, $fail;
    if ($ok) { echo "[PASS] $name\n"; $pass++; }
    else     { echo "[FAIL] $name\n"; $fail++; }
}

// ---------------------------------------------------------------------------
// chatbot_parse_suggestions — valid JSON
// ---------------------------------------------------------------------------

$reply = "Here are your mice.\n\nSUGGESTIONS::[\"Show details of the first mouse\",\"Filter by alive status\"]";
$p = chatbot_parse_suggestions($reply);
check('valid: marker stripped from content', strpos($p['content'], 'SUGGESTIONS::') === false);
check('valid: content preserved', strpos($p['content'], 'Here are your mice.') === 0);
check('valid: two suggestions parsed', count($p['suggestions']) === 2);
check('valid: first suggestion text',
    $p['suggestions'][0] === 'Show details of the first mouse');

// Empty suggestion array.
$p = chatbot_parse_suggestions("All done.\nSUGGESTIONS::[]");
check('empty array: marker stripped', strpos($p['content'], 'SUGGESTIONS::') === false);
check('empty array: suggestions is []', $p['suggestions'] === []);
check('empty array: body preserved', trim($p['content']) === 'All done.');

// Invalid JSON in marker.
$p = chatbot_parse_suggestions("Body text.\nSUGGESTIONS::[not, valid json]");
check('invalid JSON: marker stripped', strpos($p['content'], 'SUGGESTIONS::') === false);
check('invalid JSON: suggestions empty', $p['suggestions'] === []);

// Too many items: cap at 2.
$p = chatbot_parse_suggestions('Body.' . "\n" . 'SUGGESTIONS::["a","b","c","d","e"]');
check('too many: capped at 2', count($p['suggestions']) === 2);
check('too many: first kept', $p['suggestions'][0] === 'a');
check('too many: second kept', $p['suggestions'][1] === 'b');

// Oversized items (>50 chars) dropped.
$big = str_repeat('x', 60);
$json = json_encode([$big, 'short one']);
$p = chatbot_parse_suggestions("Body.\nSUGGESTIONS::$json");
check('oversize item dropped',
    count($p['suggestions']) === 1 && $p['suggestions'][0] === 'short one');

// Non-string items skipped, single-string ok.
$p = chatbot_parse_suggestions("Body.\nSUGGESTIONS::[42, \"ok question?\"]");
check('non-string skipped',
    count($p['suggestions']) === 1 && $p['suggestions'][0] === 'ok question?');

// Missing marker entirely.
$p = chatbot_parse_suggestions("Here's the data. Nothing else.");
check('no marker: content unchanged', trim($p['content']) === "Here's the data. Nothing else.");
check('no marker: suggestions empty', $p['suggestions'] === []);

// Marker with surrounding blank lines is cleaned up.
$p = chatbot_parse_suggestions("Main body line.\n\n\nSUGGESTIONS::[\"q?\"]\n\n");
check('blank-line cleanup',
    strpos($p['content'], "SUGGESTIONS") === false
    && trim($p['content']) === 'Main body line.');

// Malformed marker (not on its own line) — must not match, but defensive
// sweep still removes the line. Note: regex requires \n boundaries so
// an inline "see SUGGESTIONS::[..]" would NOT be parsed.
$p = chatbot_parse_suggestions("Body before SUGGESTIONS::[\"inline\"] still text");
check('inline marker not parsed', $p['suggestions'] === []);

// Defensive sweep — extra stray marker line (malformed) should be removed.
$p = chatbot_parse_suggestions("ok\nSUGGESTIONS:: not valid at all here");
check('stray malformed line is stripped',
    strpos($p['content'], 'SUGGESTIONS::') === false);

// ---------------------------------------------------------------------------
// chatbot_fallback_suggestions
// ---------------------------------------------------------------------------

$cases = [
    'listMice'             => ['Show details of the first mouse', 'Filter by alive status'],
    'getMouse'             => ["Show this mouse's offspring", "Show this mouse's cage history"],
    'listHoldingCages'     => ['Show one of these cages in detail', 'Which cages have open tasks?'],
    'getHoldingCage'       => ['List the mice in this cage', 'Add a maintenance note here'],
    'listBreedingCages'    => ['Show lineage of the first one', 'Which have pups currently?'],
    'getBreedingCage'      => ["Show this cage's lineage", 'List recent litters'],
    'listTasks'            => ['Show only tasks assigned to me', "What's due today?"],
    'listReminders'        => ['Show this week only', "What's overdue?"],
    'listMyNotifications'  => ['Show only unread', 'Mark all as read'],
    'listMaintenanceNotes' => ['Show notes for one cage', 'Show only recent notes'],
    'getDashboardSummary'  => ['What needs my attention today?', 'Show recent activity'],
];
foreach ($cases as $tool => $expected) {
    $r = chatbot_fallback_suggestions([['name' => $tool, 'status' => 200]], '');
    check("fallback $tool", $r === $expected);
}

// Single-suggestion ops.
$r = chatbot_fallback_suggestions([['name' => 'listStrains', 'status' => 200]], '');
check('fallback listStrains single', $r === ['How many mice per strain?']);
$r = chatbot_fallback_suggestions([['name' => 'listIacuc', 'status' => 200]], '');
check('fallback listIacuc single', $r === ['Which cages use this protocol?']);

// No tool calls → default.
$r = chatbot_fallback_suggestions([], '');
check('fallback default', $r === ['What can you do?', 'Show me a dashboard summary']);

// Tool name not in map → default.
$r = chatbot_fallback_suggestions([['name' => 'wildcardOp', 'status' => 200]], '');
check('fallback unknown tool → default', $r === ['What can you do?', 'Show me a dashboard summary']);

// listCapabilities is ignored when picking the last tool.
$r = chatbot_fallback_suggestions([
    ['name' => 'listMice', 'status' => 200],
    ['name' => 'listCapabilities', 'status' => 200],
], '');
check('fallback skips listCapabilities for last-tool lookup',
    $r[0] === 'Show details of the first mouse');

// Last-tool wins when multiple are present.
$r = chatbot_fallback_suggestions([
    ['name' => 'listMice', 'status' => 200],
    ['name' => 'getMouse', 'status' => 200],
], '');
check('fallback uses LAST tool',
    $r[0] === "Show this mouse's offspring");

// ---------------------------------------------------------------------------
// Malformed AI output → fallback still works end-to-end
// ---------------------------------------------------------------------------

$reply = "Body line\nSUGGESTIONS::[broken";
$p = chatbot_parse_suggestions($reply);
check('malformed: no SUGGESTIONS in stripped content',
    strpos($p['content'], 'SUGGESTIONS::') === false);
$final = $p['suggestions'];
if (empty($final)) {
    $final = chatbot_fallback_suggestions([['name' => 'listMice', 'status' => 200]], $p['content']);
}
check('malformed: fallback kicks in', count($final) === 2);

// ---------------------------------------------------------------------------
// System prompt blocks
// ---------------------------------------------------------------------------

$fmt = chatbot_response_formatting_rules_block();
check('formatting block: header present',
    strpos($fmt, 'RESPONSE FORMATTING RULES:') === 0);
check('formatting block: lead with one-sentence summary',
    strpos($fmt, 'Lead with a one-sentence summary in plain text.') !== false);
check('formatting block: markdown table rule',
    strpos($fmt, 'Use a markdown table if the records have 2 or more comparable fields') !== false);
check('formatting block: max 10 rows rule',
    strpos($fmt, 'Show maximum 10 rows.') !== false);
check('formatting block: right-align numerics',
    strpos($fmt, 'Right-align numeric columns') !== false);
check('formatting block: no emojis rule',
    strpos($fmt, 'Emojis (the lab is a professional context)') !== false);
check('formatting block: YYYY-MM-DD dates',
    strpos($fmt, 'YYYY-MM-DD') !== false);
check('formatting block: lab terminology',
    strpos($fmt, 'IACUC protocol') !== false);

$sug = chatbot_follow_up_suggestions_block();
check('suggestions block: header present',
    strpos($sug, 'FOLLOW-UP SUGGESTIONS:') === 0);
check('suggestions block: marker format documented',
    strpos($sug, 'SUGGESTIONS::["question 1","question 2"]') !== false);
check('suggestions block: max 2 rule',
    strpos($sug, 'Maximum 2 suggestions') !== false);
check('suggestions block: under 50 chars rule',
    strpos($sug, 'Each suggestion under 50 characters') !== false);
check('suggestions block: empty fallback documented',
    strpos($sug, 'SUGGESTIONS::[]') !== false);
check('suggestions block: pending-confirm exception documented',
    strpos($sug, 'destructive operation pending confirmation') !== false);

// ai_chat.php wires the blocks into chatbot_build_messages
$aiChat = (string)file_get_contents(__DIR__ . '/../ai_chat.php');
check('ai_chat.php calls chatbot_response_formatting_rules_block()',
    strpos($aiChat, 'chatbot_response_formatting_rules_block()') !== false);
check('ai_chat.php calls chatbot_follow_up_suggestions_block()',
    strpos($aiChat, 'chatbot_follow_up_suggestions_block()') !== false);
check('ai_chat.php parses suggestions out of the final reply',
    strpos($aiChat, 'chatbot_parse_suggestions(') !== false);
check('ai_chat.php uses fallback suggestions',
    strpos($aiChat, 'chatbot_fallback_suggestions(') !== false);
check('ai_chat.php returns suggestions in JSON',
    strpos($aiChat, "'suggestions'") !== false);

// ---------------------------------------------------------------------------
// History JSON shape — suggestions must be returned as an array per message
// so the chatbot widget can restore chips on page reload (BUG A).
// ---------------------------------------------------------------------------

$histSrc = (string)file_get_contents(__DIR__ . '/../ai_chat_history.php');
check('history endpoint decodes suggestions_json',
    strpos($histSrc, "json_decode(\$m['suggestions_json']") !== false);
check('history endpoint emits messages[].suggestions as an array',
    strpos($histSrc, "\$m['suggestions'] = is_array(\$decoded)") !== false);
check('history endpoint defaults missing suggestions to []',
    strpos($histSrc, "\$m['suggestions'] = []") !== false);
check('history endpoint strips suggestions_json from the response',
    strpos($histSrc, "unset(\$m['suggestions_json'])") !== false);
check('history endpoint filters non-strings out of the array',
    strpos($histSrc, "array_filter(\$decoded, 'is_string')") !== false);

// Simulate the per-row transformation the history endpoint applies.
function simulate_history_row(array $row): array {
    if (isset($row['suggestions_json']) && $row['suggestions_json'] !== null && $row['suggestions_json'] !== '') {
        $decoded = json_decode($row['suggestions_json'], true);
        $row['suggestions'] = is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
    } else {
        $row['suggestions'] = [];
    }
    unset($row['suggestions_json']);
    return $row;
}

$r = simulate_history_row(['id'=>1,'role'=>'assistant','content'=>'hi','suggestions_json'=>'["Show alive","Filter by sex"]']);
check('history shape: assistant row has suggestions array',
    is_array($r['suggestions']) && count($r['suggestions']) === 2);
check('history shape: assistant row first suggestion string',
    $r['suggestions'][0] === 'Show alive');
check('history shape: assistant row suggestions_json is stripped',
    !array_key_exists('suggestions_json', $r));

$r = simulate_history_row(['id'=>2,'role'=>'assistant','content'=>'no chips','suggestions_json'=>null]);
check('history shape: NULL suggestions_json => empty array',
    $r['suggestions'] === []);

$r = simulate_history_row(['id'=>3,'role'=>'user','content'=>'show mice','suggestions_json'=>'']);
check('history shape: user row gets empty suggestions',
    $r['suggestions'] === []);

$r = simulate_history_row(['id'=>4,'role'=>'assistant','content'=>'mixed','suggestions_json'=>'["ok",42,"go"]']);
check('history shape: non-strings filtered out',
    $r['suggestions'] === ['ok','go']);

// Widget restore path
$widget = (string)file_get_contents(__DIR__ . '/../includes/chatbot_widget.php');
check('widget loadConversation reads m.suggestions on restore',
    strpos($widget, 'Array.isArray(m.suggestions)') !== false);
check('widget loadConversation calls addSuggestions on restore',
    preg_match('/loadConversation[\s\S]*?addSuggestions\(/m', $widget) === 1);
check('widget addSuggestions function exists',
    strpos($widget, 'function addSuggestions(') !== false);

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
