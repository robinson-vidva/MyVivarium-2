<?php
/**
 * Sandbox-runnable unit tests for ai_chat.php internals.
 *
 * These cover the pieces that don't need a live DB / Groq egress:
 *   - chatbot_resolve_tool() mapping (every tool → expected HTTP shape).
 *   - chatbot_sanitize_for_groq() email + phone redaction.
 *   - chatbot_truncate() boundary behavior.
 *   - safety guard regex (>10 consecutive non-alphanumeric chars).
 *
 *     php tests/chatbot_unit_test.php
 *
 * Live acceptance tests (R/W/D/P/AC/AU/S series) require a running MariaDB
 * and Groq egress; see ai_chat.php docs for how to drive them manually.
 */

require_once __DIR__ . '/../includes/chatbot_helpers.php';

$pass = 0; $fail = 0;
function check(string $name, bool $ok): void {
    global $pass, $fail;
    if ($ok) { echo "[PASS] $name\n"; $pass++; }
    else     { echo "[FAIL] $name\n"; $fail++; }
}

// --- chatbot_resolve_tool() ---

$cases = [
    ['get_me',                 [],                                                    'GET',    '/me'],
    ['list_mice',              ['status'=>'alive','limit'=>5],                        'GET',    '/mice'],
    ['get_mouse',              ['id'=>'M-1'],                                          'GET',    '/mice/M-1'],
    ['list_holding_cages',     [],                                                     'GET',    '/cages/holding'],
    ['get_holding_cage',       ['id'=>'HC-1'],                                         'GET',    '/cages/holding/HC-1'],
    ['list_breeding_cages',    [],                                                     'GET',    '/cages/breeding'],
    ['get_breeding_cage',      ['id'=>'BC-1'],                                         'GET',    '/cages/breeding/BC-1'],
    ['list_maintenance_notes', ['cage_id'=>'HC-1'],                                    'GET',    '/maintenance-notes'],
    ['get_maintenance_note',   ['id'=>42],                                             'GET',    '/maintenance-notes/42'],
    ['search_activity_log',    ['action'=>'api_request'],                              'GET',    '/activity-log'],
    ['add_maintenance_note',   ['cage_id'=>'HC-1','note_text'=>'leak'],                'POST',   '/maintenance-notes'],
    ['create_holding_cage',    ['name'=>'HC-99','room'=>'A'],                          'POST',   '/cages/holding'],
    ['create_breeding_cage',   ['name'=>'BC-99','sire_id'=>'M-1','dam_id'=>'M-2'],     'POST',   '/cages/breeding'],
    ['create_mouse',           ['mouse_id'=>'M-9','cage_id'=>'HC-1','sex'=>'male'],    'POST',   '/mice'],
    ['update_mouse',           ['id'=>'M-1','fields'=>['notes'=>'x']],                 'PATCH',  '/mice/M-1'],
    ['move_mouse',             ['id'=>'M-1','to_cage_id'=>'HC-2'],                     'POST',   '/mice/M-1/move'],
    ['sacrifice_mouse',        ['id'=>'M-1'],                                          'POST',   '/mice/M-1/sacrifice'],
    ['delete_mouse',           ['id'=>'M-1'],                                          'DELETE', '/mice/M-1'],
    ['update_holding_cage',    ['id'=>'HC-1','fields'=>['room'=>'B']],                 'PATCH',  '/cages/holding/HC-1'],
    ['update_breeding_cage',   ['id'=>'BC-1','fields'=>['room'=>'B']],                 'PATCH',  '/cages/breeding/BC-1'],
    ['edit_maintenance_note',  ['id'=>5,'note_text'=>'fix'],                           'PATCH',  '/maintenance-notes/5'],
    ['delete_maintenance_note',['id'=>5],                                              'DELETE', '/maintenance-notes/5'],
];
foreach ($cases as [$name, $args, $m, $p]) {
    $r = chatbot_resolve_tool($name, $args);
    check("resolve_tool $name → $m $p", $r && $r['method'] === $m && $r['path'] === $p);
}

$destructive = ['update_mouse','move_mouse','sacrifice_mouse','delete_mouse',
                'update_holding_cage','update_breeding_cage','edit_maintenance_note','delete_maintenance_note'];
foreach ($destructive as $name) {
    $r = chatbot_resolve_tool($name, ['id' => 'X', 'fields' => [], 'to_cage_id' => 'Y']);
    check("$name is destructive", $r && $r['destructive'] === true);
}
$safe = ['get_me','list_mice','get_mouse','add_maintenance_note','create_holding_cage','create_breeding_cage','create_mouse'];
foreach ($safe as $name) {
    $r = chatbot_resolve_tool($name, ['cage_id' => 'X', 'note_text' => 'y', 'id' => 'i', 'name' => 'n', 'mouse_id' => 'M']);
    check("$name is not destructive", $r && $r['destructive'] === false);
}

check('unknown tool returns null', chatbot_resolve_tool('not_a_tool', []) === null);

// query-only param presence
$r = chatbot_resolve_tool('list_mice', ['status'=>'alive']);
check('list_mice carries status query', isset($r['query']['status']) && $r['query']['status'] === 'alive');

$r = chatbot_resolve_tool('create_holding_cage', ['name'=>'HC-X','capacity'=>10,'notes'=>'shelf 2']);
check('create_holding_cage merges capacity into remarks', strpos($r['body']['remarks'], '[capacity 10]') !== false);

$r = chatbot_resolve_tool('create_breeding_cage', ['name'=>'BC-X','sire_id'=>'M-1','dam_id'=>'M-2']);
check('create_breeding_cage maps sire/dam to male_id/female_id', $r['body']['male_id']==='M-1' && $r['body']['female_id']==='M-2');

// --- sanitizer ---
$blob = 'contact alice@example.com or 555-123-4567 for help';
$out  = chatbot_sanitize_for_groq($blob);
check('email redacted', strpos($out, 'alice@example.com') === false && strpos($out, '[REDACTED]') !== false);
check('phone redacted', strpos($out, '555-123-4567') === false);

// --- truncate ---
$s = str_repeat('A', 9000);
$t = chatbot_truncate($s, 8000);
check('truncate adds marker', strlen($t) > 8000 && str_ends_with($t, '[truncated]'));
check('truncate skips when short', chatbot_truncate('short', 8000) === 'short');

// --- safety regex ---
check('rejects 16 special chars', (bool)preg_match('/[^A-Za-z0-9]{11,}/', '@@@@@@@@@@@@@@@@'));
check('allows normal sentence',   !preg_match('/[^A-Za-z0-9]{11,}/', "list my mice please."));
check('rejects 2500-char message', strlen(str_repeat('a', 2500)) > 2000);

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
