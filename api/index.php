<?php
/**
 * MyVivarium REST API — v1 router.
 *
 * Single front controller for everything under /api/v1/*. Steps:
 *   1. Resolve the API path, method, and JSON body.
 *   2. Authenticate via X-API-Key (api_keys table, sha256-compared).
 *   3. Rate-limit per key (120 req/min, sliding minute window).
 *   4. Dispatch to a route handler. Handlers are thin wrappers around the
 *      services in /services that do the real DB work.
 *   5. Format the result inside the standard JSON envelope and log the
 *      request to api_request_log for audit / future analytics.
 *
 * Errors thrown as ApiException are turned into structured JSON errors with
 * the right HTTP status. Everything else becomes a generic 500 with the
 * message logged but not leaked verbatim.
 */

// We're outside the normal session-based auth path; turn off session entirely.
// Errors go to the server log, not the response body.
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

require_once __DIR__ . '/../dbcon.php';
require_once __DIR__ . '/../log_activity.php';

require_once __DIR__ . '/../services/helpers.php';
require_once __DIR__ . '/../services/permissions.php';
require_once __DIR__ . '/../services/users.php';
require_once __DIR__ . '/../services/mice.php';
require_once __DIR__ . '/../services/cages.php';
require_once __DIR__ . '/../services/maintenance.php';
require_once __DIR__ . '/../services/activity.php';
require_once __DIR__ . '/../services/api_keys.php';
require_once __DIR__ . '/../services/rate_limit.php';
require_once __DIR__ . '/../services/pending_operations.php';

// -----------------------------------------------------------------------------
// Bootstrap: parse method, path, headers, body
// -----------------------------------------------------------------------------

$startedAt = microtime(true);

header('Content-Type: application/json');
header('Cache-Control: no-store');

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

// Path resolution. Try every known source in order:
//   1. API_PATH         — explicit env from a future RewriteRule with [E=...]
//   2. __api_path query — set by the PHP built-in server dev router
//   3. ?path=...        — manual debug calls to /api/index.php?path=/api/v1/...
//   4. PATH_INFO        — works on hosts without mod_rewrite when the client
//                         calls /api/index.php/v1/health directly
//   5. REQUEST_URI      — the normal path on Apache + .htaccess rewrites
$rawPath = $_SERVER['API_PATH']
        ?? $_GET['__api_path']
        ?? $_GET['path']
        ?? null;
if ($rawPath === null && !empty($_SERVER['PATH_INFO'])) {
    $rawPath = $_SERVER['PATH_INFO'];
}
if ($rawPath === null) {
    $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
    // Strip everything up to and including /api/v1
    if (preg_match('#/api/v1(/.*)?$#', $uri, $m)) {
        $rawPath = $m[1] ?? '';
    } else {
        $rawPath = $uri;
    }
}
// If a full URI slipped through (e.g. /api/v1/health from REQUEST_URI), strip
// the /api/v1 prefix once more so route matching always sees just /health.
if (preg_match('#^/?api/v1(/.*)?$#', (string)$rawPath, $m)) {
    $rawPath = $m[1] ?? '';
}
$path = '/' . ltrim((string)$rawPath, '/');
$path = rtrim($path, '/');
if ($path === '') $path = '/';

// Headers — getallheaders is Apache-only; fall back to $_SERVER scan.
function api_headers(): array {
    if (function_exists('getallheaders')) {
        $h = getallheaders();
        return array_change_key_case($h ?: [], CASE_LOWER);
    }
    $out = [];
    foreach ($_SERVER as $k => $v) {
        if (strpos($k, 'HTTP_') === 0) {
            $name = strtolower(str_replace('_', '-', substr($k, 5)));
            $out[$name] = $v;
        }
    }
    return $out;
}
$headers = api_headers();

// JSON body for non-GET methods.
$body = [];
if ($method !== 'GET' && $method !== 'DELETE') {
    $raw = file_get_contents('php://input') ?: '';
    if ($raw !== '') {
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            api_respond(['error' => ['code' => 'invalid_argument', 'message' => 'Body must be JSON object']], 400);
        }
        $body = $decoded;
    }
}

$authKeyRow = null; // populated after auth
$authUserId = null;

// API version banner — bumped when the surface changes in incompatible ways.
const API_VERSION = '1.0';

// -----------------------------------------------------------------------------
// Response helpers
// -----------------------------------------------------------------------------

function api_respond(array $payload, int $status = 200): void {
    global $startedAt, $authUserId, $method, $path;
    http_response_code($status);
    if (isset($payload['error'])) {
        echo json_encode(['ok' => false] + $payload, JSON_UNESCAPED_SLASHES);
    } else {
        echo json_encode(['ok' => true] + $payload, JSON_UNESCAPED_SLASHES);
    }

    // Best-effort: log the request after the response is sent. Caller may
    // bail before reaching the regular log path (e.g. 401), so do it here.
    try {
        global $con;
        $elapsed = (int)round((microtime(true) - $startedAt) * 1000);
        $endpoint = "$method $path";
        $stmt = $con->prepare("INSERT INTO api_request_log (user_id, endpoint, method, status_code, response_time_ms) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param('issii', $authUserId, $endpoint, $method, $status, $elapsed);
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $e) {
        error_log('api log error: ' . $e->getMessage());
    }
    exit;
}

function api_error(string $code, string $message, int $status): void {
    api_respond(['error' => ['code' => $code, 'message' => $message]], $status);
}

function api_data($data, ?array $meta = null, int $status = 200): void {
    $payload = ['data' => $data];
    if ($meta !== null) $payload['meta'] = $meta;
    api_respond($payload, $status);
}

function api_list(array $serviceResult, int $status = 200): void {
    api_data($serviceResult['items'], [
        'count'  => count($serviceResult['items']),
        'total'  => $serviceResult['total'],
        'limit'  => $serviceResult['limit'],
        'offset' => $serviceResult['offset'],
    ], $status);
}

// -----------------------------------------------------------------------------
// Health check — no auth, no session, no permissions, no rate limit.
//
// The chatbot calls this once at the start of every conversation to verify
// the API surface is reachable and returning JSON. If THIS endpoint comes
// back as HTML, the deployment is misrouting requests (vhost rewrite,
// MultiViews, ErrorDocument, etc.) and no amount of credential juggling
// will help. We answer here, before touching the DB.
// -----------------------------------------------------------------------------

if ($path === '/health' && $method === 'GET') {
    api_data([
        'version' => API_VERSION,
        'time'    => date('c'),
    ]);
}

// -----------------------------------------------------------------------------
// Authentication
// -----------------------------------------------------------------------------

$rawKey = $headers['x-api-key'] ?? '';
if ($rawKey === '') {
    api_error('unauthorized', 'Missing X-API-Key header', 401);
}
$authKeyRow = api_key_lookup($con, $rawKey);
if (!$authKeyRow) {
    api_error('unauthorized', 'Invalid API key', 401);
}
if ($authKeyRow['revoked_at'] !== null) {
    api_error('unauthorized', 'API key has been revoked', 401);
}
if (api_key_is_expired($authKeyRow)) {
    api_error('unauthorized', 'API key has expired', 401);
}
if (($authKeyRow['status'] ?? '') !== 'approved') {
    api_error('forbidden', 'Key owner is not an approved user', 403);
}

$authUserId = (int)$authKeyRow['user_id'];
api_key_mark_used($con, (int)$authKeyRow['id']);

// -----------------------------------------------------------------------------
// Rate limiting
// -----------------------------------------------------------------------------

[$rlCount, $rlRetryAfter] = rate_limit_check($con, (int)$authKeyRow['id']);
if ($rlCount > RATE_LIMIT_MAX_PER_MINUTE) {
    header('Retry-After: ' . max(1, $rlRetryAfter));
    api_error('rate_limited', "Rate limit exceeded (max " . RATE_LIMIT_MAX_PER_MINUTE . "/min)", 429);
}

// -----------------------------------------------------------------------------
// Confirm-before-execute helper
// -----------------------------------------------------------------------------

/**
 * Wraps a destructive op with the two-step confirm flow.
 *
 *   $diffFn:    callable that returns ['before' => [...], 'after' => [...]]
 *   $executeFn: callable that performs the op and returns the result data
 *   $summary:   human-readable one-liner to embed in the pending-op response
 *
 * Behavior:
 *   - Header X-Confirm-Token: pending → validate, compute diff, store pending,
 *     return HTTP 202.
 *   - Header X-Confirm-Token: <uuid>  → consume pending row, replay body via
 *     $executeFn with the stored body, mark executed, return 200.
 *   - No header                       → execute immediately, return 200.
 */
function confirm_or_execute(
    callable $diffFn,
    callable $executeFn,
    string $summary,
    array $body
): void {
    global $headers, $con, $authUserId, $method, $path;
    $token = $headers['x-confirm-token'] ?? '';

    if ($token === 'pending') {
        $diff = $diffFn($body);
        $diff['summary'] = $summary;
        $stored = pending_op_create($con, $authUserId, $method, $path, $body, $diff);
        api_data([
            'pending_operation_id' => $stored['id'],
            'diff'                 => $diff,
            'expires_at'           => $stored['expires_at'],
        ], null, 202);
    } elseif ($token !== '') {
        $consumed = pending_op_consume($con, $token, $authUserId, $method, $path);
        $result = $executeFn($consumed['body']);
        pending_op_mark_executed($con, $consumed['id']);
        api_data($result);
    } else {
        $result = $executeFn($body);
        api_data($result);
    }
}

// -----------------------------------------------------------------------------
// Write-scope guard for non-GET methods
// -----------------------------------------------------------------------------

if ($method !== 'GET') {
    if (!api_key_has_scope($authKeyRow, 'write')) {
        api_error('forbidden', 'API key lacks write scope', 403);
    }
}

// -----------------------------------------------------------------------------
// Activity-log helper for write operations
// -----------------------------------------------------------------------------

function api_log_write(string $entity_type, ?string $entity_id, string $details = ''): void {
    global $con, $authUserId, $method, $path;
    // log_activity reads $_SESSION['user_id']; spoof it so the helper attributes
    // the action to the API key's owner without changing log_activity itself.
    $_SESSION['user_id'] = $authUserId;
    log_activity($con, 'api_request', $entity_type, $entity_id, "$method $path" . ($details ? " — $details" : ''));
}

// -----------------------------------------------------------------------------
// Route dispatch
// -----------------------------------------------------------------------------

try {
    dispatch($method, $path, $body);
    // dispatch() should always call api_data/api_respond/api_error and exit.
    api_error('not_found', "No route for $method $path", 404);
} catch (ApiException $e) {
    api_error($e->errorCode, $e->getMessage(), $e->statusCode);
} catch (Throwable $e) {
    error_log('api fatal: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
    api_error('server_error', 'Internal server error', 500);
}

// -----------------------------------------------------------------------------
// Route handlers
// -----------------------------------------------------------------------------

function dispatch(string $method, string $path, array $body): void {
    global $con, $authUserId, $authKeyRow;

    // /me
    if ($path === '/me' && $method === 'GET') {
        api_data(user_get_basic($con, $authUserId));
    }

    // /mice
    if ($path === '/mice') {
        if ($method === 'GET') {
            api_list(mice_list($con, $authUserId, $_GET));
        }
        if ($method === 'POST') {
            $mouse = mice_create($con, $authUserId, $body);
            api_log_write('mouse', $mouse['mouse_id'], 'create');
            api_data($mouse, null, 201);
        }
    }
    if (preg_match('#^/mice/([^/]+)$#', $path, $m)) {
        $id = urldecode($m[1]);
        if ($method === 'GET') {
            api_data(mice_get($con, $authUserId, $id, $_GET));
        }
        if ($method === 'PATCH') {
            confirm_or_execute(
                fn($b) => mice_patch_diff($con, $id, $b),
                function ($b) use ($con, $authUserId, $id) {
                    $r = mice_update($con, $authUserId, $id, $b);
                    api_log_write('mouse', $id, 'patch');
                    return $r;
                },
                "Update mouse $id",
                $body
            );
        }
        if ($method === 'DELETE') {
            confirm_or_execute(
                fn($b) => mice_soft_delete_diff($con, $id),
                function ($b) use ($con, $authUserId, $id) {
                    $r = mice_soft_delete($con, $authUserId, $id);
                    api_log_write('mouse', $id, 'soft_delete');
                    return $r;
                },
                "Soft-delete (archive) mouse $id",
                $body
            );
        }
    }
    if (preg_match('#^/mice/([^/]+)/move$#', $path, $m) && $method === 'POST') {
        $id = urldecode($m[1]);
        confirm_or_execute(
            fn($b) => mice_move_diff($con, $id, $b),
            function ($b) use ($con, $authUserId, $id) {
                $r = mice_move($con, $authUserId, $id, $b);
                $to = $b['to_cage_id'] ?? '(no cage)';
                api_log_write('mouse', $id, "move to $to");
                return $r;
            },
            "Move mouse $id" . (isset($body['to_cage_id']) ? " to {$body['to_cage_id']}" : ''),
            $body
        );
    }
    if (preg_match('#^/mice/([^/]+)/sacrifice$#', $path, $m) && $method === 'POST') {
        $id = urldecode($m[1]);
        confirm_or_execute(
            fn($b) => mice_sacrifice_diff($con, $id, $b),
            function ($b) use ($con, $authUserId, $id) {
                $r = mice_sacrifice($con, $authUserId, $id, $b);
                api_log_write('mouse', $id, 'sacrifice');
                return $r;
            },
            "Sacrifice mouse $id",
            $body
        );
    }

    // /cages/holding
    if ($path === '/cages/holding') {
        if ($method === 'GET') {
            api_list(cages_list($con, $authUserId, 'holding', $_GET));
        }
        if ($method === 'POST') {
            $r = holding_create($con, $authUserId, $body);
            api_log_write('cage', $r['cage_id'], 'create_holding');
            api_data($r, null, 201);
        }
    }
    if (preg_match('#^/cages/holding/([^/]+)$#', $path, $m)) {
        $cid = urldecode($m[1]);
        if ($method === 'GET')   api_data(holding_get($con, $authUserId, $cid));
        if ($method === 'PATCH') {
            $r = holding_update($con, $authUserId, $cid, $body);
            api_log_write('cage', $cid, 'patch_holding');
            api_data($r);
        }
    }

    // /cages/breeding
    if ($path === '/cages/breeding') {
        if ($method === 'GET') {
            api_list(cages_list($con, $authUserId, 'breeding', $_GET));
        }
        if ($method === 'POST') {
            $r = breeding_create($con, $authUserId, $body);
            api_log_write('cage', $r['cage_id'], 'create_breeding');
            api_data($r, null, 201);
        }
    }
    if (preg_match('#^/cages/breeding/([^/]+)$#', $path, $m)) {
        $cid = urldecode($m[1]);
        if ($method === 'GET')   api_data(breeding_get($con, $authUserId, $cid));
        if ($method === 'PATCH') {
            $r = breeding_update($con, $authUserId, $cid, $body);
            api_log_write('cage', $cid, 'patch_breeding');
            api_data($r);
        }
    }

    // /maintenance-notes
    if ($path === '/maintenance-notes') {
        if ($method === 'GET') {
            api_list(maint_list($con, $authUserId, $_GET));
        }
        if ($method === 'POST') {
            $r = maint_create($con, $authUserId, $body);
            api_log_write('maintenance', (string)$r['id'], 'create_note');
            api_data($r, null, 201);
        }
    }
    if (preg_match('#^/maintenance-notes/(\d+)$#', $path, $m)) {
        $id = (int)$m[1];
        if ($method === 'GET') {
            api_data(maint_get($con, $authUserId, $id, $_GET));
        }
        if ($method === 'PATCH') {
            $r = maint_update($con, $authUserId, $id, $body);
            api_log_write('maintenance', (string)$id, 'patch_note');
            api_data($r);
        }
        if ($method === 'DELETE') {
            confirm_or_execute(
                fn($b) => maint_soft_delete_diff($con, $id),
                function ($b) use ($con, $authUserId, $id) {
                    $r = maint_soft_delete($con, $authUserId, $id);
                    api_log_write('maintenance', (string)$id, 'soft_delete_note');
                    return $r;
                },
                "Soft-delete maintenance note $id",
                $body
            );
        }
    }

    // /activity-log
    if ($path === '/activity-log' && $method === 'GET') {
        api_list(activity_list($con, $authUserId, $_GET));
    }
}
