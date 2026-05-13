<?php

/**
 * SMTP Configuration Script
 *
 * Defines SMTP_* / SENDER_* constants from .env. Uses the built-in env
 * parser in includes/env.php so it works whether or not Composer has
 * been installed.
 */

if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

require_once __DIR__ . '/includes/env.php';
env_load_into_superglobals();

if (!defined('SMTP_HOST'))       define('SMTP_HOST',       env_get('SMTP_HOST')       ?? '');
if (!defined('SMTP_PORT'))       define('SMTP_PORT',       env_get('SMTP_PORT')       ?? '');
if (!defined('SMTP_USERNAME'))   define('SMTP_USERNAME',   env_get('SMTP_USERNAME')   ?? '');
if (!defined('SMTP_PASSWORD'))   define('SMTP_PASSWORD',   env_get('SMTP_PASSWORD')   ?? '');
if (!defined('SMTP_ENCRYPTION')) define('SMTP_ENCRYPTION', env_get('SMTP_ENCRYPTION') ?? '');
if (!defined('SENDER_EMAIL'))    define('SENDER_EMAIL',    env_get('SENDER_EMAIL')    ?? '');
if (!defined('SENDER_NAME'))     define('SENDER_NAME',     env_get('SENDER_NAME')     ?? '');
