<?php
// Email settings storage. Values live in the email_settings table; password
// and Brevo API key are encrypted with the shared AES-256-CBC helper from
// includes/ai_settings.php so we don't have two encryption schemes.

require_once __DIR__ . '/../dbcon.php';
require_once __DIR__ . '/../log_activity.php';
require_once __DIR__ . '/ai_settings.php';

class EmailSettingsException extends RuntimeException {}

const EMAIL_SETTING_KEYS = [
    'transport',
    'smtp_host',
    'smtp_port',
    'smtp_username',
    'smtp_password',
    'smtp_encryption',
    'brevo_api_key',
    'sender_email',
    'sender_name',
];

// Keys whose stored value is encrypted at rest.
const EMAIL_SECRET_KEYS = ['smtp_password', 'brevo_api_key'];

function email_settings_is_secret(string $key): bool
{
    return in_array($key, EMAIL_SECRET_KEYS, true);
}

// Ensure the email_settings table exists. Mirrors
// database/migrations/2026_05_20_email_settings.sql so the feature works on
// databases provisioned before that migration shipped, without a manual
// migration step — the same auto-provision posture as ai_settings_ensure_key().
// Idempotent (CREATE TABLE IF NOT EXISTS). Throws EmailSettingsException with
// an admin-facing message if the table is absent and cannot be created.
function email_settings_ensure_table(mysqli $con): void
{
    $sql = "CREATE TABLE IF NOT EXISTS `email_settings` (
              `id` int NOT NULL AUTO_INCREMENT,
              `setting_key` varchar(64) NOT NULL,
              `setting_value` mediumtext NOT NULL,
              `is_encrypted` tinyint(1) NOT NULL DEFAULT 0,
              `updated_by` int DEFAULT NULL,
              `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uniq_email_settings_key` (`setting_key`),
              KEY `idx_email_settings_updated_by` (`updated_by`),
              CONSTRAINT `fk_email_settings_user` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
            )";
    try {
        $con->query($sql);
    } catch (mysqli_sql_exception $e) {
        throw new EmailSettingsException(
            'The email_settings table is missing and could not be created automatically (' .
            $e->getMessage() . '). Run database/migrations/2026_05_20_email_settings.sql against the database.'
        );
    }
}

function email_settings_get_raw_row(mysqli $con, string $key): ?array
{
    $stmt = $con->prepare("SELECT setting_key, setting_value, is_encrypted, updated_by, updated_at
                             FROM email_settings WHERE setting_key = ? LIMIT 1");
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function email_settings_get(mysqli $con, string $key): ?string
{
    $row = email_settings_get_raw_row($con, $key);
    if (!$row) return null;
    $val = (string)$row['setting_value'];
    if ((int)$row['is_encrypted'] === 1) {
        if ($val === '') return '';
        return ai_settings_decrypt($val);
    }
    return $val;
}

function email_settings_set(mysqli $con, string $key, string $value, int $user_id): void
{
    if (!in_array($key, EMAIL_SETTING_KEYS, true)) {
        throw new EmailSettingsException('Unknown email setting key: ' . $key);
    }
    $isSecret = email_settings_is_secret($key) ? 1 : 0;
    if ($isSecret === 1) {
        ai_settings_ensure_key();
        $stored = $value === '' ? '' : ai_settings_encrypt($value);
    } else {
        $stored = $value;
    }
    $stmt = $con->prepare("INSERT INTO email_settings (setting_key, setting_value, is_encrypted, updated_by)
                           VALUES (?, ?, ?, ?)
                           ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value),
                                                   is_encrypted  = VALUES(is_encrypted),
                                                   updated_by    = VALUES(updated_by)");
    $stmt->bind_param('ssii', $key, $stored, $isSecret, $user_id);
    $stmt->execute();
    $stmt->close();
}

// Return all stored values, decrypted. Missing keys are returned as ''.
function email_settings_get_all(mysqli $con): array
{
    $out = array_fill_keys(EMAIL_SETTING_KEYS, '');
    $res = $con->query("SELECT setting_key, setting_value, is_encrypted FROM email_settings");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $k = $row['setting_key'];
            if (!in_array($k, EMAIL_SETTING_KEYS, true)) continue;
            $v = (string)$row['setting_value'];
            if ((int)$row['is_encrypted'] === 1 && $v !== '') {
                try {
                    $v = ai_settings_decrypt($v);
                } catch (Throwable $e) {
                    $v = '';
                }
            }
            $out[$k] = $v;
        }
        $res->free();
    }
    return $out;
}

// Same as above but secret values are replaced with '' and a parallel
// map of key => bool indicates whether each secret is set. Use this on
// the admin page so we never echo a saved secret back to the browser.
function email_settings_get_view(mysqli $con): array
{
    $out = array_fill_keys(EMAIL_SETTING_KEYS, '');
    $set = array_fill_keys(EMAIL_SECRET_KEYS, false);
    $res = $con->query("SELECT setting_key, setting_value, is_encrypted FROM email_settings");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $k = $row['setting_key'];
            if (!in_array($k, EMAIL_SETTING_KEYS, true)) continue;
            if (email_settings_is_secret($k)) {
                $set[$k] = ((string)$row['setting_value']) !== '';
            } else {
                $out[$k] = (string)$row['setting_value'];
            }
        }
        $res->free();
    }
    return ['values' => $out, 'secret_set' => $set];
}

// True when any email setting row exists. Used to decide whether to
// pre-fill the form from .env on first load.
function email_settings_any_configured(mysqli $con): bool
{
    // Tolerate a missing email_settings table (migration not yet run). Under
    // mysqli's default exception mode (PHP 8.1+) a missing table throws
    // rather than returning false, so catch it and report "nothing
    // configured" — callers then render from / fall back to .env instead of
    // surfacing an uncaught fatal on the admin page.
    try {
        $res = $con->query("SELECT 1 FROM email_settings LIMIT 1");
    } catch (mysqli_sql_exception $e) {
        return false;
    }
    if (!$res) return false;
    $has = (bool)$res->fetch_row();
    $res->free();
    return $has;
}

// Resolve the active transport config for outbound mail. Falls back to
// .env SMTP constants when nothing is saved in the database, so email keeps
// working during migration and a broken DB config does not silently break
// all email. Returns:
//   [
//     'transport' => 'smtp' | 'brevo',
//     'sender_email' => string,
//     'sender_name'  => string,
//     // smtp:
//     'smtp_host' => ..., 'smtp_port' => int, 'smtp_username' => ...,
//     'smtp_password' => ..., 'smtp_encryption' => 'tls'|'ssl'|'',
//     // brevo:
//     'brevo_api_key' => ...,
//   ]
function email_settings_resolve_active(?mysqli $con = null): array
{
    if ($con === null) {
        $con = $GLOBALS['con'] ?? null;
    }
    $cfg = [
        'transport'        => 'smtp',
        'sender_email'     => defined('SENDER_EMAIL')    ? SENDER_EMAIL    : '',
        'sender_name'      => defined('SENDER_NAME')     ? SENDER_NAME     : '',
        'smtp_host'        => defined('SMTP_HOST')       ? SMTP_HOST       : '',
        'smtp_port'        => defined('SMTP_PORT')       ? (int)SMTP_PORT  : 587,
        'smtp_username'    => defined('SMTP_USERNAME')   ? SMTP_USERNAME   : '',
        'smtp_password'    => defined('SMTP_PASSWORD')   ? SMTP_PASSWORD   : '',
        'smtp_encryption'  => defined('SMTP_ENCRYPTION') ? SMTP_ENCRYPTION : '',
        'brevo_api_key'    => '',
        'source'           => 'env',
    ];

    if (!$con instanceof mysqli) return $cfg;

    try {
        if (!email_settings_any_configured($con)) {
            return $cfg;
        }
    } catch (Throwable $e) {
        // Table missing or transient DB error -> fall back to .env values.
        return $cfg;
    }

    try {
        $all = email_settings_get_all($con);
    } catch (Throwable $e) {
        return $cfg;
    }

    $cfg['source'] = 'db';
    $transport = strtolower(trim((string)$all['transport']));
    if ($transport !== 'brevo') $transport = 'smtp';
    $cfg['transport'] = $transport;

    if ($all['sender_email'] !== '')    $cfg['sender_email']    = $all['sender_email'];
    if ($all['sender_name']  !== '')    $cfg['sender_name']     = $all['sender_name'];
    if ($all['smtp_host'] !== '')       $cfg['smtp_host']       = $all['smtp_host'];
    if ($all['smtp_port'] !== '')       $cfg['smtp_port']       = (int)$all['smtp_port'];
    if ($all['smtp_username'] !== '')   $cfg['smtp_username']   = $all['smtp_username'];
    if ($all['smtp_password'] !== '')   $cfg['smtp_password']   = $all['smtp_password'];
    if ($all['smtp_encryption'] !== '') $cfg['smtp_encryption'] = $all['smtp_encryption'];
    if ($all['brevo_api_key'] !== '')   $cfg['brevo_api_key']   = $all['brevo_api_key'];

    return $cfg;
}

// Snapshot of the current .env SMTP values, used on first load of the
// admin page so the admin doesn't have to retype everything.
function email_settings_env_defaults(): array
{
    return [
        'transport'        => 'smtp',
        'smtp_host'        => defined('SMTP_HOST')       ? SMTP_HOST       : '',
        'smtp_port'        => defined('SMTP_PORT')       ? (string)SMTP_PORT : '',
        'smtp_username'    => defined('SMTP_USERNAME')   ? SMTP_USERNAME   : '',
        'smtp_encryption'  => defined('SMTP_ENCRYPTION') ? SMTP_ENCRYPTION : 'tls',
        'sender_email'     => defined('SENDER_EMAIL')    ? SENDER_EMAIL    : '',
        'sender_name'      => defined('SENDER_NAME')     ? SENDER_NAME     : '',
        // .env password is intentionally not exposed to the browser. The
        // admin must re-enter it on first save (same posture as the AI
        // configs page: secrets never round-trip back to the client).
        'smtp_password'    => '',
        'brevo_api_key'    => '',
    ];
}
