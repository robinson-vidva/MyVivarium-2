<?php
/**
 * Sandbox-runnable tests for chatbot_select_tools() keyword routing.
 *
 * Each new keyword family in the router must pull in the right tag
 * group. Negative tests confirm that "list my open tasks" pulls in
 * Tasks (BUG C: previously "my" matched first and locked the route to
 * the Users group, which is why the AI refused to handle tasks).
 *
 *   php tests/chatbot_tool_routing_test.php
 */

require_once __DIR__ . '/../includes/chatbot_helpers.php';

$pass = 0; $fail = 0;
function check(string $name, bool $ok): void {
    global $pass, $fail;
    if ($ok) { echo "[PASS] $name\n"; $pass++; }
    else     { echo "[FAIL] $name\n"; $fail++; }
}

function names_for(string $msg): array {
    return array_map(fn($t) => $t['function']['name'], chatbot_select_tools($msg));
}

// Mice
$n = names_for('show me my mice');
check('mice: pulls listMice',       in_array('listMice', $n, true));
check('mice: pulls getMouse',       in_array('getMouse', $n, true));

$n = names_for('show me lineage of this mouse');
check('lineage: pulls breeding cages', in_array('getBreedingCageLineage', $n, true));

$n = names_for('list the offspring');
check('offspring: pulls Mice',      in_array('listMouseOffspring', $n, true));

$n = names_for('who is the dam of M-1?');
check('dam: pulls Mice',            in_array('getMouse', $n, true));

// Cages
$n = names_for('list cages');
check('cage: pulls listHoldingCages',  in_array('listHoldingCages', $n, true));
check('cage: pulls listBreedingCages', in_array('listBreedingCages', $n, true));

// Maintenance notes
$n = names_for('add a maintenance note');
check('maintenance: pulls addMaintenanceNote', in_array('addMaintenanceNote', $n, true));

// Activity log
$n = names_for('show me the activity log');
check('activity log: pulls listActivityLog', in_array('listActivityLog', $n, true));

$n = names_for('history of mouse M-1');
check('history keyword: pulls listMouseCageHistory', in_array('listMouseCageHistory', $n, true));

// --- Tasks (BUG C) ---
$n = names_for('list my open tasks');
check('"list my open tasks": pulls listTasks',     in_array('listTasks', $n, true));
check('"list my open tasks": pulls listReminders', in_array('listReminders', $n, true));
check('"list my open tasks": pulls listCalendarEvents', in_array('listCalendarEvents', $n, true));

$n = names_for('I want to add a task');
check('"add a task": pulls Tasks group', in_array('listTasks', $n, true));

$n = names_for('show my todo list');
check('"todo": pulls Tasks group', in_array('listTasks', $n, true));

$n = names_for("what's pending today?");
check('"pending": pulls Tasks group', in_array('listTasks', $n, true));

$n = names_for('any to-do items?');
check('"to-do": pulls Tasks group', in_array('listTasks', $n, true));

$n = names_for('to do today');
check('"to do": pulls Tasks group', in_array('listTasks', $n, true));

// Reminders
$n = names_for('show me my reminders');
check('reminders: pulls listReminders', in_array('listReminders', $n, true));
check('reminders: pulls listTasks',     in_array('listTasks', $n, true));

// Calendar
$n = names_for('what is on the calendar this week?');
check('calendar: pulls listCalendarEvents', in_array('listCalendarEvents', $n, true));
check('calendar: pulls Tasks',              in_array('listTasks', $n, true));

$n = names_for("what's upcoming?");
check('"upcoming": pulls Calendar', in_array('listCalendarEvents', $n, true));

$n = names_for('show items due tomorrow');
check('"due": pulls Calendar', in_array('listCalendarEvents', $n, true));

$n = names_for('what is the deadline for this?');
check('"deadline": pulls Calendar', in_array('listCalendarEvents', $n, true));

// Notifications
$n = names_for('show my notifications');
check('notifications: pulls listMyNotifications', in_array('listMyNotifications', $n, true));
check('notifications: pulls getUnreadNotificationCount', in_array('getUnreadNotificationCount', $n, true));

$n = names_for('any alerts?');
check('"alerts": pulls Notifications', in_array('listMyNotifications', $n, true));

// Strains
$n = names_for('list strains');
check('strains: pulls listStrains', in_array('listStrains', $n, true));

$n = names_for('which strain is M-1?');
check('strain: pulls listStrains',  in_array('listStrains', $n, true));

// IACUC
$n = names_for('which IACUC protocols apply?');
check('iacuc: pulls listIacuc',         in_array('listIacuc', $n, true));
check('iacuc: pulls cages',             in_array('listHoldingCages', $n, true));

$n = names_for('list protocols');
check('protocol: pulls listIacuc',      in_array('listIacuc', $n, true));

// Dashboard
$n = names_for('show me a dashboard summary');
check('dashboard: pulls getDashboardSummary', in_array('getDashboardSummary', $n, true));
check('dashboard: pulls Mice',                in_array('listMice', $n, true));

$n = names_for('give me an overview');
check('"overview": pulls Dashboard', in_array('getDashboardSummary', $n, true));

// Account / profile / who-am-I
$n = names_for('show my profile');
check('"my profile": pulls Account',         in_array('getMyProfile', $n, true));
check('"my profile": pulls Users',           in_array('getMe', $n, true));

$n = names_for('who am I?');
check('"who am I": pulls Account',           in_array('getMyProfile', $n, true));

$n = names_for('what is my account info?');
check('"my account": pulls Account',         in_array('getMyProfile', $n, true));

// Negative / regression cases

// Bare "my" with no domain word should fall through to "send all" so
// the AI never refuses a valid request because the keyword router
// guessed wrong. This was the BUG C class of failures.
$all = chatbot_select_tools('do my thing');
check('bare "my": falls through to full toolset', count($all) >= 45);

// Empty message → all tools.
$n = chatbot_select_tools('');
check('empty message: all tools',                 count($n) >= 45);

// Unknown words → all tools.
$n = chatbot_select_tools('blarghnoise foobar quux');
check('unknown words: all tools',                 count($n) >= 45);

// listCapabilities is always present when any group matched.
$n = names_for('show my mice');
check('any match: listCapabilities included',     in_array('listCapabilities', $n, true));

// Mice + tasks combined: both groups should be present.
$n = names_for('list mice with open tasks');
check('mice+tasks: pulls Mice',                   in_array('listMice', $n, true));
check('mice+tasks: pulls Tasks',                  in_array('listTasks', $n, true));

// --- Coverage: every spec endpoint reachable through SOME keyword path ---
$allDefs = chatbot_all_tool_defs();
$allNames = array_map(fn($t) => $t['function']['name'], $allDefs);

$probes = [
    'show my mice', 'show offspring of M-1', 'list cages', 'add a maintenance note',
    'show activity log', 'history of M-1', 'list my open tasks', 'show my reminders',
    'what is on the calendar?', 'show my notifications', 'list strains',
    'list iacuc protocols', 'show me a dashboard summary',
    'show my profile', 'help',
];
$reached = [];
foreach ($probes as $p) {
    foreach (names_for($p) as $n) $reached[$n] = true;
}
$missing = array_values(array_diff($allNames, array_keys($reached)));
check('all 45 spec endpoints reachable through some keyword (missing: '
    . implode(', ', $missing) . ')', count($missing) === 0);

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
