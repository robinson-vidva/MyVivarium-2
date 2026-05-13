<?php
/**
 * Tiny zero-dependency .env parser + getter.
 *
 * Replaces vlucas/phpdotenv so MyVivarium-2 can boot on hosts that haven't
 * run `composer install`. This file MUST have no other includes — it's
 * loaded by dbcon.php at startup, before any autoloader.
 *
 * The function set here is the same that lives in includes/ai_settings.php;
 * we keep both copies in lock-step using function_exists guards so existing
 * call sites do not break.
 */

if (!function_exists('env_get')) {

    /**
     * Read an environment variable with a fallback for shared hosts that
     * disable getenv(). Order of lookup:
     *   1. Per-request override set by env_set_runtime()
     *   2. $_ENV / $_SERVER
     *   3. getenv() if available
     *   4. A parsed copy of .env at the project root
     *
     * Returns null if the variable is not set or is empty.
     */
    function env_get(string $name): ?string
    {
        static $envValues = null;

        if (isset($GLOBALS['__env_runtime_overrides'][$name])
            && $GLOBALS['__env_runtime_overrides'][$name] !== '') {
            return (string)$GLOBALS['__env_runtime_overrides'][$name];
        }
        if (isset($_ENV[$name]) && $_ENV[$name] !== '') {
            return (string)$_ENV[$name];
        }
        if (isset($_SERVER[$name]) && $_SERVER[$name] !== '') {
            return (string)$_SERVER[$name];
        }
        if (function_exists('getenv')) {
            $v = @getenv($name);
            if ($v !== false && $v !== '') {
                return (string)$v;
            }
        }
        if ($envValues === null) {
            $envValues = env_parse_dotenv();
        }
        if (isset($envValues[$name]) && $envValues[$name] !== '') {
            return $envValues[$name];
        }
        return null;
    }
}

if (!function_exists('env_set_runtime')) {

    function env_set_runtime(string $name, string $value): void
    {
        $GLOBALS['__env_runtime_overrides'][$name] = $value;
        $_ENV[$name] = $value;
        if (function_exists('putenv')) {
            @putenv($name . '=' . $value);
        }
    }
}

if (!function_exists('env_parse_dotenv')) {

    /**
     * Parse the project-root .env file and return a name => value map.
     * Empty array if the file is missing or unreadable. Trims surrounding
     * single or double quotes from values.
     */
    function env_parse_dotenv(): array
    {
        $envPath = realpath(__DIR__ . '/..');
        if ($envPath === false) return [];
        $envPath .= '/.env';
        if (!is_file($envPath) || !is_readable($envPath)) return [];

        $out   = [];
        $lines = @file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) return [];
        foreach ($lines as $line) {
            $line = ltrim($line);
            if ($line === '' || $line[0] === '#') continue;
            $eq = strpos($line, '=');
            if ($eq === false) continue;
            $k = trim(substr($line, 0, $eq));
            $v = trim(substr($line, $eq + 1));
            if (strlen($v) >= 2
                && (($v[0] === '"' && substr($v, -1) === '"')
                 || ($v[0] === "'" && substr($v, -1) === "'"))) {
                $v = substr($v, 1, -1);
            }
            $out[$k] = $v;
        }
        return $out;
    }
}

if (!function_exists('env_load_into_superglobals')) {

    /**
     * Populate $_ENV with the contents of .env. Some legacy entry points
     * (config.php) read $_ENV directly — this keeps them working without
     * needing vlucas/phpdotenv. Idempotent and never overwrites existing
     * values.
     */
    function env_load_into_superglobals(): void
    {
        foreach (env_parse_dotenv() as $k => $v) {
            if (!isset($_ENV[$k]) || $_ENV[$k] === '') {
                $_ENV[$k] = $v;
            }
            if (!isset($_SERVER[$k]) || $_SERVER[$k] === '') {
                $_SERVER[$k] = $v;
            }
        }
    }
}
