<?php
/**
 * Resolve the absolute base URL of this installation for links sent in
 * email (password reset, email confirmation).
 *
 * Order: APP_URL from .env, then the admin-configured `url` lab setting,
 * then (only if neither is set) the request host. The request host is a
 * last resort because it is attacker-controlled on a misconfigured server.
 */
function mv_app_base_url(?mysqli $con = null): string
{
    $env = function_exists('env_get') ? env_get('APP_URL') : getenv('APP_URL');
    $env = is_string($env) ? trim($env) : '';
    if ($env !== '' && preg_match('#^https?://[^/\s]+#i', $env)) {
        return rtrim($env, '/');
    }

    if ($con instanceof mysqli) {
        $stmt = $con->prepare("SELECT value FROM settings WHERE name = 'url' LIMIT 1");
        if ($stmt) {
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $setting = trim((string)($row['value'] ?? ''));
            if ($setting !== '') {
                if (!preg_match('#^https?://#i', $setting)) {
                    $setting = 'https://' . $setting;
                }
                return rtrim($setting, '/');
            }
        }
    }

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost'));
    return $scheme . '://' . $host;
}
