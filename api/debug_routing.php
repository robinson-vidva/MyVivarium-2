<?php
/**
 * DELETE ME — temporary diagnostic for API routing on production.
 *
 * Hit this file on the live host to see exactly what Apache forwards to PHP
 * when the .htaccess rewrite (or lack of it) routes /api/v1/* requests.
 *
 * Try all of these:
 *   https://demo2.myvivarium.online/api/debug_routing.php
 *   https://demo2.myvivarium.online/api/v1/debug_routing.php
 *   https://demo2.myvivarium.online/api/debug_routing.php?path=/api/v1/health
 *
 * If the first works but the second 404s, mod_rewrite is not running this
 * .htaccess. If everything works, the rewrite is fine and the routing
 * problem is elsewhere.
 *
 * REMOVE THIS FILE AFTER DEBUGGING.
 */

header('Content-Type: application/json');

$uri  = $_SERVER['REQUEST_URI']  ?? null;
$path = $_SERVER['PATH_INFO']    ?? null;
$name = $_SERVER['SCRIPT_NAME']  ?? null;
$get  = $_GET['path']            ?? null;
$apiP = $_GET['__api_path']      ?? null;

// Replicate the resolution logic from index.php so the user can see what
// route the front controller would have detected.
$detected = $_SERVER['API_PATH'] ?? $apiP ?? $get ?? null;
if ($detected === null && !empty($path)) {
    $detected = $path;
}
if ($detected === null) {
    $u = parse_url($uri ?? '/', PHP_URL_PATH) ?? '/';
    if (preg_match('#/api/v1(/.*)?$#', $u, $m)) {
        $detected = $m[1] ?? '';
    } else {
        $detected = $u;
    }
}
if (preg_match('#^/?api/v1(/.*)?$#', (string)$detected, $m)) {
    $detected = $m[1] ?? '';
}
$detected = '/' . ltrim((string)$detected, '/');
$detected = rtrim($detected, '/');
if ($detected === '') $detected = '/';

echo json_encode([
    'WARNING'            => 'DELETE ME — temporary debug endpoint, remove from production.',
    'mod_rewrite_loaded' => function_exists('apache_get_modules')
        ? in_array('mod_rewrite', apache_get_modules(), true)
        : 'unknown (not running under mod_php)',
    'server_software'    => $_SERVER['SERVER_SOFTWARE'] ?? null,
    'REQUEST_URI'        => $uri,
    'PATH_INFO'          => $path,
    'SCRIPT_NAME'        => $name,
    'QUERY_STRING'       => $_SERVER['QUERY_STRING'] ?? null,
    'GET_path'           => $get,
    'GET_api_path'       => $apiP,
    'ENV_API_PATH'       => $_SERVER['API_PATH'] ?? null,
    'detected_route'     => $detected,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
