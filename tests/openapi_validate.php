<?php
/**
 * OpenAPI consistency check: confirm api/openapi.yaml is in sync with the
 * router in api/index.php. Run after any endpoint change.
 *
 *     php tests/openapi_validate.php
 *
 * Checks:
 *   1. Spec parses cleanly.
 *   2. Every operationId is unique.
 *   3. Every endpoint declared in api/index.php's dispatch() router is
 *      present in the spec (path + method).
 *   4. Every endpoint in the spec has summary, description, operationId,
 *      and a non-empty responses block.
 *
 * Exits 0 on PASS, 1 on any FAIL with a per-check report.
 */

require_once __DIR__ . '/../services/openapi_loader.php';

$pass = 0; $fail = 0;
$failures = [];
function note(string $name, bool $ok, string $detail = ''): void {
    global $pass, $fail, $failures;
    if ($ok) { echo "[PASS] $name\n"; $pass++; }
    else     { echo "[FAIL] $name" . ($detail ? " — $detail" : '') . "\n"; $fail++; $failures[] = $name; }
}

// 1. Parse.
try {
    $spec = mv_openapi_load();
    note('spec parses cleanly', true);
} catch (Throwable $e) {
    note('spec parses cleanly', false, $e->getMessage());
    echo "Cannot continue without parsing.\n";
    exit(1);
}

// 2. Every operationId is unique.
$ops = mv_openapi_operations($spec);
$ids = array_filter(array_map(fn($o) => $o['operationId'], $ops));
$dups = array_keys(array_filter(array_count_values($ids), fn($n) => $n > 1));
note('every operationId is unique', count($dups) === 0, $dups ? 'duplicates: ' . implode(', ', $dups) : '');

// 3. Endpoint coverage: spec must declare every (method, path) the router
//    actually serves. Source of truth = static parse of api/index.php.
$routerSrc = (string)file_get_contents(__DIR__ . '/../api/index.php');
$routerEndpoints = mv_extract_router_endpoints($routerSrc);
$specEndpoints = [];
foreach ($ops as $o) {
    $specEndpoints[$o['method'] . ' ' . $o['path']] = true;
}
$missing = [];
foreach ($routerEndpoints as $ep) {
    if (!isset($specEndpoints[$ep])) $missing[] = $ep;
}
note('every router endpoint is in the spec', count($missing) === 0,
    $missing ? "missing: " . implode(' | ', $missing) : '');

// And the inverse: spec endpoints we couldn't find in the router (catches
// stale spec entries).
$extra = [];
$routerSet = array_flip($routerEndpoints);
foreach (array_keys($specEndpoints) as $ep) {
    if (!isset($routerSet[$ep])) $extra[] = $ep;
}
note('no spec endpoint missing from the router', count($extra) === 0,
    $extra ? "extra: " . implode(' | ', $extra) : '');

// 4. Each operation has summary, description, operationId, responses.
$incomplete = [];
foreach ($ops as $o) {
    $missingFields = [];
    if (empty($o['operationId']))                        $missingFields[] = 'operationId';
    if (empty($o['summary']))                            $missingFields[] = 'summary';
    if (empty($o['description']))                        $missingFields[] = 'description';
    $opNode = $spec['paths'][$o['path']][strtolower($o['method'])] ?? [];
    if (empty($opNode['responses']))                     $missingFields[] = 'responses';
    if ($missingFields) {
        $incomplete[] = $o['method'] . ' ' . $o['path'] . ' (' . implode(',', $missingFields) . ')';
    }
}
note('every endpoint has summary, description, operationId, responses',
    count($incomplete) === 0,
    $incomplete ? implode(' | ', $incomplete) : '');

// 5. Sanity: tag every destructive op so the chatbot's confirm flow triggers.
$destructiveMissingTag = [];
foreach ($ops as $o) {
    if (!in_array($o['method'], ['PATCH', 'DELETE'], true)) continue;
    if (!$o['destructive']) {
        $destructiveMissingTag[] = $o['method'] . ' ' . $o['path'];
    }
}
note('every PATCH/DELETE is marked x-mv-destructive',
    count($destructiveMissingTag) === 0,
    $destructiveMissingTag ? implode(' | ', $destructiveMissingTag) : '');

echo "\n";
echo "Endpoints in spec   : " . count($ops) . "\n";
echo "Endpoints in router : " . count($routerEndpoints) . "\n";
echo "$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);

/**
 * Static-parse api/index.php's dispatch() function for every endpoint it
 * actually serves. Returns ["GET /me", "POST /mice", ...].
 *
 * Strategy: regex over the file. Three forms exist:
 *   - if ($path === '/literal' && $method === 'GET')
 *   - if ($path === '/literal') { ... if ($method === 'GET') ...; if ($method === 'POST') ...; }
 *   - if (preg_match('#^/regex$#', $path, $m)) { ... if ($method === 'GET') ...; }
 * We extract every `$path === '...'` literal and every preg_match anchored
 * regex, pair them with the method conditions inside the same `if (preg…)`
 * or `if ($path === …)` block, and emit one entry per (method, path).
 */
function mv_extract_router_endpoints(string $src): array
{
    $endpoints = [];

    // Form A: combined `$path === '/x' && $method === 'METHOD'`.
    if (preg_match_all("#\\\$path === '([^']+)'\\s*&&\\s*\\\$method === '([A-Z]+)'#", $src, $am, PREG_SET_ORDER)) {
        foreach ($am as $m) {
            $endpoints[] = strtoupper($m[2]) . ' ' . $m[1];
        }
    }

    // Form B: `if ($path === '/x') { ... if ($method === 'M') ... }`. Walk
    // the source, find each `$path === '...'` (without `&& method`), then
    // grab every `$method === 'X'` until matching closing brace at the same
    // depth.
    $offset = 0;
    while (preg_match("#if\\s*\\(\\s*\\\$path === '([^']+)'\\s*\\)#", $src, $m, PREG_OFFSET_CAPTURE, $offset)) {
        $path = $m[1][0];
        $start = $m[0][1] + strlen($m[0][0]);
        // Find the matching `{` and walk to its `}`.
        $brace = strpos($src, '{', $start);
        if ($brace === false) { $offset = $start; continue; }
        $depth = 1; $i = $brace + 1; $len = strlen($src);
        while ($i < $len && $depth > 0) {
            if ($src[$i] === '{') $depth++;
            elseif ($src[$i] === '}') $depth--;
            $i++;
        }
        $block = substr($src, $brace, $i - $brace);
        if (preg_match_all("#\\\$method === '([A-Z]+)'#", $block, $mm)) {
            foreach ($mm[1] as $meth) $endpoints[] = strtoupper($meth) . ' ' . $path;
        }
        $offset = $i;
    }

    // Form C: `if (preg_match('#^/regex$#', $path, $m))` then methods inside,
    // OR `if (preg_match('#^/regex$#', $path, $m) && $method === 'METHOD')`
    // single-line form. Use ~ as the outer delimiter so the # delimiter in
    // the router's regexes doesn't clash.
    $offset = 0;
    while (preg_match("~if\\s*\\(\\s*preg_match\\(\\s*'#\\^([^']+?)\\\$#'\\s*,\\s*\\\$path[^)]*\\)~", $src, $m, PREG_OFFSET_CAPTURE, $offset)) {
        $regex = $m[1][0];
        $template = mv_regex_to_template($regex);
        $matchStart = $m[0][1];
        $matchEnd   = $matchStart + strlen($m[0][0]);

        // Walk to the matching close-paren of the outer `if (`.
        $depth = 1; $j = $matchEnd; $len = strlen($src);
        while ($j < $len && $depth > 0) {
            if ($src[$j] === '(') $depth++;
            elseif ($src[$j] === ')') $depth--;
            $j++;
        }
        $ifCondition = substr($src, $matchStart, $j - $matchStart);

        // Single-line `&& $method === 'X'` form: extract directly from the if cond.
        if (preg_match_all("#\\\$method === '([A-Z]+)'#", $ifCondition, $cm)) {
            foreach ($cm[1] as $meth) $endpoints[] = strtoupper($meth) . ' ' . $template;
            // If the if-condition itself names a method we still scan the block
            // below so multi-method blocks are handled.
        }

        // Walk the block for additional `if ($method === 'X')` lines.
        $brace = strpos($src, '{', $j);
        if ($brace !== false) {
            $depth = 1; $i = $brace + 1;
            while ($i < $len && $depth > 0) {
                if ($src[$i] === '{') $depth++;
                elseif ($src[$i] === '}') $depth--;
                $i++;
            }
            $block = substr($src, $brace, $i - $brace);
            if (preg_match_all("#\\\$method === '([A-Z]+)'#", $block, $mm)) {
                foreach ($mm[1] as $meth) $endpoints[] = strtoupper($meth) . ' ' . $template;
            }
            $offset = $i;
        } else {
            $offset = $j;
        }
    }

    // Dedup & sort.
    $endpoints = array_values(array_unique($endpoints));
    sort($endpoints);
    return $endpoints;
}

/**
 * Turn a regex like /mice/([^/]+)/move or /maintenance-notes/(\d+) into a
 * template path like /mice/{id}/move or /maintenance-notes/{id}.
 */
function mv_regex_to_template(string $regex): string
{
    $n = 0;
    $tpl = preg_replace_callback('/\([^)]+\)/', function () use (&$n) {
        $n++;
        return $n === 1 ? '{id}' : "{id$n}";
    }, $regex);
    return $tpl;
}
