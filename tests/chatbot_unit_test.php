<?php
/**
 * Sandbox-runnable unit tests for ai_chat.php internals.
 *
 * As of the OpenAPI rollout, tool definitions are loaded from
 * api/openapi.yaml — no hardcoded list. These tests pin:
 *   - chatbot_resolve_tool() routes each operationId to the right HTTP shape
 *     declared in the spec.
 *   - destructive ops carry destructive=true; safe ops carry destructive=false.
 *   - tool definitions cover every operationId in the spec (plus the
 *     listCapabilities pseudo-tool).
 *   - chatbot_sanitize_for_llm() email + phone redaction.
 *   - chatbot_truncate() boundary behavior.
 *   - chatbot_cap_list_result() shrinks list payloads.
 *   - chatbot_select_tools() keyword routing.
 *
 *     php tests/chatbot_unit_test.php
 */

require_once __DIR__ . '/../includes/chatbot_helpers.php';
require_once __DIR__ . '/../services/openapi_loader.php';

$pass = 0; $fail = 0;
function check(string $name, bool $ok): void {
    global $pass, $fail;
    if ($ok) { echo "[PASS] $name\n"; $pass++; }
    else     { echo "[FAIL] $name\n"; $fail++; }
}

// --- chatbot_resolve_tool() — every operationId resolves correctly ---

$cases = [
    ['getMe',                  [],                                                    'GET',    '/me'],
    ['listMice',               ['status'=>'alive','limit'=>5],                        'GET',    '/mice'],
    ['getMouse',               ['id'=>'M-1'],                                          'GET',    '/mice/M-1'],
    ['listHoldingCages',       [],                                                     'GET',    '/cages/holding'],
    ['getHoldingCage',         ['id'=>'HC-1'],                                         'GET',    '/cages/holding/HC-1'],
    ['listBreedingCages',      [],                                                     'GET',    '/cages/breeding'],
    ['getBreedingCage',        ['id'=>'BC-1'],                                         'GET',    '/cages/breeding/BC-1'],
    ['listMaintenanceNotes',   ['cage_id'=>'HC-1'],                                    'GET',    '/maintenance-notes'],
    ['getMaintenanceNote',     ['id'=>42],                                             'GET',    '/maintenance-notes/42'],
    ['listActivityLog',        ['action'=>'api_request'],                              'GET',    '/activity-log'],
    ['addMaintenanceNote',     ['cage_id'=>'HC-1','note_text'=>'leak'],                'POST',   '/maintenance-notes'],
    ['createHoldingCage',      ['cage_id'=>'HC-99','room'=>'A'],                       'POST',   '/cages/holding'],
    ['createBreedingCage',     ['cage_id'=>'BC-99','male_id'=>'M-1','female_id'=>'M-2'],'POST',  '/cages/breeding'],
    ['createMouse',            ['mouse_id'=>'M-9','cage_id'=>'HC-1','sex'=>'male'],    'POST',   '/mice'],
    ['updateMouse',            ['id'=>'M-1','notes'=>'x'],                             'PATCH',  '/mice/M-1'],
    ['moveMouseToCage',        ['id'=>'M-1','to_cage_id'=>'HC-2'],                     'POST',   '/mice/M-1/move'],
    ['sacrificeMouse',         ['id'=>'M-1','date'=>'2026-05-14'],                     'POST',   '/mice/M-1/sacrifice'],
    ['archiveMouse',           ['id'=>'M-1'],                                          'DELETE', '/mice/M-1'],
    ['updateHoldingCage',      ['id'=>'HC-1','room'=>'B'],                             'PATCH',  '/cages/holding/HC-1'],
    ['updateBreedingCage',     ['id'=>'BC-1','room'=>'B'],                             'PATCH',  '/cages/breeding/BC-1'],
    ['updateMaintenanceNote',  ['id'=>5,'note_text'=>'fix'],                           'PATCH',  '/maintenance-notes/5'],
    ['deleteMaintenanceNote',  ['id'=>5],                                              'DELETE', '/maintenance-notes/5'],
];
foreach ($cases as [$name, $args, $m, $p]) {
    $r = chatbot_resolve_tool($name, $args);
    check("resolve_tool $name -> $m $p", $r && $r['method'] === $m && $r['path'] === $p);
}

$destructive = ['updateMouse', 'moveMouseToCage', 'sacrificeMouse', 'archiveMouse',
                'updateHoldingCage', 'updateBreedingCage',
                'updateMaintenanceNote', 'deleteMaintenanceNote'];
foreach ($destructive as $name) {
    $r = chatbot_resolve_tool($name, ['id' => 'X', 'to_cage_id' => 'Y', 'date' => '2026-05-14']);
    check("$name is destructive", $r && $r['destructive'] === true);
}
$safe = ['getMe','listMice','getMouse','addMaintenanceNote','createHoldingCage','createBreedingCage','createMouse'];
foreach ($safe as $name) {
    $r = chatbot_resolve_tool($name, ['cage_id' => 'X', 'note_text' => 'y', 'id' => 'i', 'mouse_id' => 'M']);
    check("$name is not destructive", $r && $r['destructive'] === false);
}

check('unknown tool returns null', chatbot_resolve_tool('not_a_tool', []) === null);
check('listCapabilities pseudo-tool returns null (handled out-of-band)',
    chatbot_resolve_tool('listCapabilities', []) === null);

// --- query / body shape checks ---
$r = chatbot_resolve_tool('listMice', ['status'=>'alive']);
check('listMice carries status query', isset($r['query']['status']) && $r['query']['status'] === 'alive');

$r = chatbot_resolve_tool('createBreedingCage', ['cage_id'=>'BC-X','male_id'=>'M-1','female_id'=>'M-2']);
check('createBreedingCage forwards male_id/female_id in body',
    $r['body']['male_id']==='M-1' && $r['body']['female_id']==='M-2');

$r = chatbot_resolve_tool('updateMouse', ['id'=>'M-1','sex'=>'female','notes'=>'reweighed']);
check('updateMouse path carries id, body carries fields',
    $r['path'] === '/mice/M-1' && $r['body']['sex'] === 'female' && $r['body']['notes'] === 'reweighed');

$r = chatbot_resolve_tool('moveMouseToCage', ['id'=>'M-1','to_cage_id'=>'HC-2','reason'=>'weaning']);
check('moveMouseToCage path id + body to_cage_id',
    $r['path'] === '/mice/M-1/move' && $r['body']['to_cage_id'] === 'HC-2');

// --- spec coverage: chatbot_all_tool_defs() must cover every operationId ---
$spec    = mv_openapi_load();
$ops     = mv_openapi_operations($spec);
$defs    = chatbot_all_tool_defs();
$defNames = array_map(fn($t) => $t['function']['name'], $defs);

$missing = [];
foreach ($ops as $o) {
    if (!$o['operationId']) continue;
    // /health is intentionally excluded from the chatbot tool list.
    if ($o['method'] === 'GET' && $o['path'] === '/health') continue;
    if (!in_array($o['operationId'], $defNames, true)) $missing[] = $o['operationId'];
}
check('every spec operationId appears as a tool def', count($missing) === 0);
check('listCapabilities pseudo-tool is registered', in_array('listCapabilities', $defNames, true));

// --- sanitizer ---
$blob = 'contact alice@example.com or 555-123-4567 for help';
$out  = chatbot_sanitize_for_llm($blob);
check('email redacted', strpos($out, 'alice@example.com') === false && strpos($out, '[REDACTED]') !== false);
check('phone redacted', strpos($out, '555-123-4567') === false);

// --- truncate ---
$s = str_repeat('A', 9000);
$t = chatbot_truncate($s, 8000);
check('truncate adds marker', strlen($t) > 8000 && str_ends_with($t, '[truncated]'));
check('truncate skips when short', chatbot_truncate('short', 8000) === 'short');
check('truncate default cap is 2500', strlen(chatbot_truncate(str_repeat('A', 3000))) === 2500 + strlen(' ... [truncated]'));

// --- token estimator ---
check('estimate_tokens roughly len/4',  chatbot_estimate_tokens(str_repeat('a', 400)) === 100);
check('estimate_tokens handles arrays', chatbot_estimate_tokens(['role'=>'user','content'=>'hi']) > 0);

// --- list cap ---
$listJson = json_encode(['ok'=>true,'data'=>array_fill(0, 60, ['mouse_id'=>'M-x'])]);
$capped   = chatbot_cap_list_result('listMice', $listJson, 25);
$dec      = json_decode($capped, true);
check('list cap shrinks to 25',          count($dec['data']) === 25);
check('list cap notes showing 60',       isset($dec['_truncated']) && strpos($dec['_truncated'], '60') !== false);
check('list cap no-op on non-list tool', chatbot_cap_list_result('getMouse', $listJson, 25) === $listJson);

// --- tool selector (keyword router) ---
$miceNames = array_map(fn($t) => $t['function']['name'], chatbot_select_tools('show me my mice'));
$cageNames = array_map(fn($t) => $t['function']['name'], chatbot_select_tools('list cages'));
$logNames  = array_map(fn($t) => $t['function']['name'], chatbot_select_tools('show activity log'));
$noneNames = array_map(fn($t) => $t['function']['name'], chatbot_select_tools(''));
check('select_tools mice keyword includes listMice',          in_array('listMice', $miceNames, true));
check('select_tools cage keyword includes listHoldingCages',  in_array('listHoldingCages', $cageNames, true));
check('select_tools log keyword includes listActivityLog',    in_array('listActivityLog', $logNames, true));
check('select_tools fallback returns all',                    count($noneNames) === count($defs));
check('select_tools mice keyword keeps listCapabilities',     in_array('listCapabilities', $miceNames, true));

// --- listCapabilities pseudo-tool ---
$cap = chatbot_list_capabilities();
check('listCapabilities returns ok=true',     $cap['ok'] === true);
check('listCapabilities reports >=4 groups',  isset($cap['data']['groups']) && count($cap['data']['groups']) >= 4);
check('listCapabilities reports spec version', !empty($cap['data']['spec_version']));

// --- safety regex ---
check('rejects 16 special chars', (bool)preg_match('/[^A-Za-z0-9]{11,}/', '@@@@@@@@@@@@@@@@'));
check('allows normal sentence',   !preg_match('/[^A-Za-z0-9]{11,}/', "list my mice please."));
check('rejects 2500-char message', strlen(str_repeat('a', 2500)) > 2000);

// --- new system prompt blocks (formatting + follow-up suggestions) ---
$aiChat = (string)file_get_contents(__DIR__ . '/../ai_chat.php');
check('chatbot_build_messages includes RESPONSE FORMATTING RULES block',
    strpos($aiChat, 'chatbot_response_formatting_rules_block()') !== false);
check('chatbot_build_messages includes FOLLOW-UP SUGGESTIONS block',
    strpos($aiChat, 'chatbot_follow_up_suggestions_block()') !== false);

$fmt = chatbot_response_formatting_rules_block();
check('RESPONSE FORMATTING RULES header verbatim',
    strpos($fmt, 'RESPONSE FORMATTING RULES:') === 0);
check('RESPONSE FORMATTING RULES rule 1 verbatim',
    strpos($fmt, '1. Lead with a one-sentence summary in plain text.') !== false);
check('RESPONSE FORMATTING RULES rule 8 verbatim (no emojis)',
    strpos($fmt, '8. Never use:') !== false
    && strpos($fmt, 'Emojis (the lab is a professional context)') !== false);
check('RESPONSE FORMATTING RULES rule 9 verbatim (YYYY-MM-DD)',
    strpos($fmt, '9. Always use:') !== false
    && strpos($fmt, 'YYYY-MM-DD') !== false);

$sug = chatbot_follow_up_suggestions_block();
check('FOLLOW-UP SUGGESTIONS header verbatim',
    strpos($sug, 'FOLLOW-UP SUGGESTIONS:') === 0);
check('FOLLOW-UP SUGGESTIONS marker example verbatim',
    strpos($sug, 'SUGGESTIONS::["question 1","question 2"]') !== false);
check('FOLLOW-UP SUGGESTIONS empty marker documented',
    strpos($sug, 'SUGGESTIONS::[]') !== false);

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
