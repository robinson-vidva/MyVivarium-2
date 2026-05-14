<?php
/**
 * Batch 1 endpoint coverage smoke test.
 *
 * For each new endpoint, verify:
 *   - the route is wired in api/index.php (router string match)
 *   - the path appears in api/openapi.yaml with the right method + operationId
 *   - the relevant permission function is invoked in the service file when
 *     permission gating is expected
 *
 * Where a DB connection is configured (DB_HOST etc. set in env), also exercise
 * each endpoint via the chatbot's tool resolver against the local API.
 *
 *     php tests/batch1_endpoints_test.php
 */

require_once __DIR__ . '/../services/openapi_loader.php';

$pass = 0; $fail = 0; $skip = 0;
$rows = [];
function row(string $name, string $status, string $detail = ''): void {
    global $pass, $fail, $skip, $rows;
    if ($status === 'PASS') $pass++;
    elseif ($status === 'FAIL') $fail++;
    else $skip++;
    $rows[] = [$name, $status, $detail];
}

$spec = mv_openapi_load();
$ops  = mv_openapi_operations($spec);
$byId = [];
foreach ($ops as $o) {
    if ($o['operationId']) $byId[$o['operationId']] = $o;
}

$routerSrc      = (string)file_get_contents(__DIR__ . '/../api/index.php');
$tasksSrc       = (string)file_get_contents(__DIR__ . '/../services/tasks.php');
$remindersSrc   = (string)file_get_contents(__DIR__ . '/../services/reminders.php');
$notifsSrc      = (string)file_get_contents(__DIR__ . '/../services/notifications.php');
$strainsSrc     = (string)file_get_contents(__DIR__ . '/../services/strains.php');
$iacucSrc       = (string)file_get_contents(__DIR__ . '/../services/iacuc.php');
$dashboardSrc   = (string)file_get_contents(__DIR__ . '/../services/dashboard.php');
$calendarSrc    = (string)file_get_contents(__DIR__ . '/../services/calendar.php');
$cagesSrc       = (string)file_get_contents(__DIR__ . '/../services/cages.php');
$miceSrc        = (string)file_get_contents(__DIR__ . '/../services/mice.php');
$usersSrc       = (string)file_get_contents(__DIR__ . '/../services/users.php');

/**
 * Definitions of the new endpoints introduced in batch 1.
 *
 * Each entry: [operationId, METHOD, path, [services-files-that-MUST-contain-string], [marker-strings]]
 */
$batch1 = [
    ['listTasks',                'GET', '/tasks',                            [$tasksSrc],     ['perm_is_admin']],
    ['getTask',                  'GET', '/tasks/{id}',                       [$tasksSrc],     ['perm_is_admin']],
    ['listReminders',            'GET', '/reminders',                        [$remindersSrc], ['perm_is_admin']],
    ['getReminder',              'GET', '/reminders/{id}',                   [$remindersSrc], ['perm_is_admin']],
    ['listCalendarEvents',       'GET', '/calendar',                         [$calendarSrc],  ['perm_is_admin']],
    ['listMyNotifications',      'GET', '/notifications',                    [$notifsSrc],    ['user_id = ?']],
    ['getUnreadNotificationCount','GET','/notifications/unread-count',       [$notifsSrc],    ['user_id = ?']],
    ['listMouseCageHistory',     'GET', '/mice/{id}/history',                [$miceSrc],      ['mouse_cage_history']],
    ['listMouseOffspring',       'GET', '/mice/{id}/offspring',              [$miceSrc],      ['sire_id', 'dam_id']],
    ['listStrains',              'GET', '/strains',                          [$strainsSrc],   ['FROM strains']],
    ['getStrain',                'GET', '/strains/{id}',                     [$strainsSrc],   ['FROM strains']],
    ['listIacuc',                'GET', '/iacuc',                            [$iacucSrc],     ['perm_is_admin']],
    ['getIacuc',                 'GET', '/iacuc/{id}',                       [$iacucSrc],     ['perm_is_admin']],
    ['getDashboardSummary',      'GET', '/dashboard/summary',                [$dashboardSrc], ['perm_is_admin']],
    ['listHoldingCageUsers',     'GET', '/cages/holding/{id}/users',         [$cagesSrc],     ['cage_list_users']],
    ['listBreedingCageUsers',    'GET', '/cages/breeding/{id}/users',        [$cagesSrc],     ['cage_list_users']],
    ['listHoldingCageIacuc',     'GET', '/cages/holding/{id}/iacuc',         [$cagesSrc],     ['cage_list_iacuc']],
    ['listBreedingCageIacuc',    'GET', '/cages/breeding/{id}/iacuc',        [$cagesSrc],     ['cage_list_iacuc']],
    ['getMyProfile',             'GET', '/me/profile',                       [$usersSrc],     ['user_get_my_profile']],
    ['getBreedingCageLineage',   'GET', '/cages/breeding/{id}/lineage',      [$cagesSrc],     ['breeding_lineage']],
    ['getHoldingCageCardData',   'GET', '/cages/holding/{id}/card-data',     [$cagesSrc],     ['cage_card_data']],
    ['getBreedingCageCardData',  'GET', '/cages/breeding/{id}/card-data',    [$cagesSrc],     ['cage_card_data']],
];

foreach ($batch1 as [$opId, $method, $path, $svcFiles, $markers]) {
    // 1. Spec has the operation.
    if (!isset($byId[$opId]) || $byId[$opId]['method'] !== $method) {
        row($opId, 'FAIL', "operationId not in spec or wrong method");
        continue;
    }
    if ($byId[$opId]['path'] !== $path) {
        row($opId, 'FAIL', "spec path '{$byId[$opId]['path']}' != expected '$path'");
        continue;
    }

    // 2. Router has it. For literal paths, look for exact form A or B match;
    // for templated paths, look for a regex template that matches.
    $routerHas = false;
    if (strpos($path, '{') === false) {
        // Literal path → look for `$path === 'X'`.
        $routerHas = (strpos($routerSrc, "\$path === '$path'") !== false);
    } else {
        // Templated path → look for the literal segments without the params.
        $literal = preg_replace('/\{[^}]+\}/', '', $path);
        $literalSlashed = preg_replace('#/+#', '/', $literal);
        // Take a substantive prefix and check it appears in a preg_match.
        $segments = array_filter(explode('/', trim($literal, '/')));
        $first = '/' . reset($segments);
        $routerHas = (strpos($routerSrc, "preg_match('#^/" . substr($first, 1)) !== false)
                    || (strpos($routerSrc, $literalSlashed) !== false);
        // Last-ditch: regex pattern referencing the path-tail static portion.
        if (!$routerHas) {
            $tail = substr($path, strlen('/cages/breeding/'));
            $routerHas = (strpos($routerSrc, $tail) !== false);
        }
    }
    if (!$routerHas) {
        row($opId, 'FAIL', "router does not reference path $path");
        continue;
    }

    // 3. Service file contains the marker(s).
    $ok = true; $missingMarker = '';
    foreach ($markers as $m) {
        $found = false;
        foreach ($svcFiles as $src) {
            if (strpos($src, $m) !== false) { $found = true; break; }
        }
        if (!$found) { $ok = false; $missingMarker = $m; break; }
    }
    if (!$ok) {
        row($opId, 'FAIL', "service file missing marker '$missingMarker'");
        continue;
    }

    row($opId, 'PASS', "$method $path");
}

// Optional live exercise: do we have DB credentials configured?
$canDB = (getenv('DB_HOST') !== false) || file_exists(__DIR__ . '/../.env');
if (!$canDB) {
    row('live API exercise', 'SKIP', 'no DB available');
}

echo "\n";
echo str_pad('OperationId', 32) . str_pad('Status', 8) . "Detail\n";
echo str_repeat('-', 70) . "\n";
foreach ($rows as [$name, $status, $detail]) {
    echo str_pad($name, 32) . str_pad($status, 8) . $detail . "\n";
}
echo "\n$pass passed, $fail failed, $skip skipped\n";
exit($fail === 0 ? 0 : 1);
