<?php
/**
 * Dev-only router for `php -S` testing.
 *
 * Rewrites /api/v1/* → /api/index.php with __api_path query. Mirrors the
 * production .htaccess so curl tests against the built-in server hit the
 * same handler the live API does. Do NOT use in production.
 */
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (preg_match('#^/api/v1(/.*)?$#', $uri, $m)) {
    $apiPath = $m[1] ?? '/';
    $_SERVER['SCRIPT_NAME']     = '/api/index.php';
    $_SERVER['SCRIPT_FILENAME'] = __DIR__ . '/index.php';
    $_GET['__api_path'] = $apiPath;
    require __DIR__ . '/index.php';
    return true;
}
// Serve other files as-is.
return false;
