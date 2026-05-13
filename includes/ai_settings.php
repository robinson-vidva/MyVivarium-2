<?php
/**
 * AI settings helper — encrypted storage for chatbot configuration.
 *
 * Values are kept in the ai_settings table as AES-256-CBC ciphertext
 * (base64(iv) ":" base64(ciphertext)). The encryption key lives in the
 * AI_SETTINGS_ENCRYPTION_KEY entry in the .env file; if it's missing the
 * first time an admin opens the AI Configuration page we auto-generate a
 * 32-byte hex key and persist it atomically.
 *
 * Public functions:
 *   ai_settings_get(string $key): ?string  — decrypted value or null
 *   ai_settings_set(string $key, string $value, int $user_id): void
 *   ai_settings_delete(string $key, int $user_id): void
 *
 * Every set/delete is recorded in activity_log with action
 * 'ai_settings_change' and target = $key. **The setting value itself is
 * never logged.**
 */

require_once __DIR__ . '/../dbcon.php';
require_once __DIR__ . '/../log_activity.php';

class AiSettingsException extends RuntimeException {}

const AI_SETTINGS_ENV_VAR = 'AI_SETTINGS_ENCRYPTION_KEY';

/**
 * Returns the raw hex-encoded encryption key from .env. Throws if the key
 * is absent — callers must ensure the key is provisioned (e.g. via
 * ai_settings_ensure_key()) before encrypting.
 */
function ai_settings_get_key(): string
{
    $k = $_ENV[AI_SETTINGS_ENV_VAR] ?? getenv(AI_SETTINGS_ENV_VAR);
    if ($k === false || $k === null || $k === '') {
        throw new AiSettingsException(
            'AI_SETTINGS_ENCRYPTION_KEY is missing from .env. ' .
            'Visit AI Configuration as an admin to auto-generate one, or add it manually.'
        );
    }
    if (!ctype_xdigit((string)$k) || strlen((string)$k) !== 64) {
        throw new AiSettingsException(
            'AI_SETTINGS_ENCRYPTION_KEY must be 64 hex chars (32 bytes). Found ' .
            strlen((string)$k) . ' chars.'
        );
    }
    return hex2bin((string)$k);
}

/**
 * Auto-generate and persist a 32-byte hex key in .env if it does not yet
 * exist or is empty. Idempotent and atomic. Returns true if a new key was
 * written, false if one already existed.
 *
 * Throws AiSettingsException on filesystem errors with a clear,
 * admin-facing message. Does NOT fall back to a weak key.
 */
function ai_settings_ensure_key(): bool
{
    $existing = $_ENV[AI_SETTINGS_ENV_VAR] ?? getenv(AI_SETTINGS_ENV_VAR);
    if (is_string($existing) && $existing !== '') {
        return false;
    }

    $envPath = realpath(__DIR__ . '/..') . '/.env';
    if (!file_exists($envPath)) {
        throw new AiSettingsException(
            '.env not found at ' . $envPath . '. Create it (copy .env.example) before configuring AI.'
        );
    }
    if (!is_writable($envPath)) {
        throw new AiSettingsException(
            '.env is not writable. Check filesystem permissions on ' . $envPath . '.'
        );
    }

    $newKey = bin2hex(random_bytes(32));
    $contents = file_get_contents($envPath);
    if ($contents === false) {
        throw new AiSettingsException('Failed to read .env at ' . $envPath . '.');
    }

    // If a line with the var exists (even empty), replace it. Otherwise append.
    $line = AI_SETTINGS_ENV_VAR . '=' . $newKey;
    $pattern = '/^' . preg_quote(AI_SETTINGS_ENV_VAR, '/') . '\s*=.*$/m';
    if (preg_match($pattern, $contents, $m)) {
        // Refuse to overwrite an existing non-empty value (race condition guard).
        $existingLine = $m[0];
        if (preg_match('/^' . preg_quote(AI_SETTINGS_ENV_VAR, '/') . '\s*=\s*(.+)$/', $existingLine, $vm)
            && trim($vm[1]) !== '') {
            return false;
        }
        $newContents = preg_replace($pattern, $line, $contents, 1);
    } else {
        $newContents = rtrim($contents, "\n") . "\n" . $line . "\n";
    }

    // Preserve ownership and permissions.
    $stat = @stat($envPath);
    $dir  = dirname($envPath);
    $tmp  = tempnam($dir, '.envtmp');
    if ($tmp === false) {
        throw new AiSettingsException('Failed to create temp file in ' . $dir . '.');
    }
    if (file_put_contents($tmp, $newContents) === false) {
        @unlink($tmp);
        throw new AiSettingsException('Failed to write temp file ' . $tmp . '.');
    }
    if ($stat !== false) {
        @chmod($tmp, $stat['mode'] & 0777);
        // chown/chgrp may fail (only root can chown to a different owner). We
        // attempt them but don't treat failure as fatal — the file already had
        // the correct owner because we wrote it as the current process.
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            @chown($tmp, $stat['uid']);
            @chgrp($tmp, $stat['gid']);
        }
    }
    if (!@rename($tmp, $envPath)) {
        @unlink($tmp);
        throw new AiSettingsException('Failed to rename temp file over ' . $envPath . '.');
    }

    // Make the new value visible to the running process so the very same
    // request can encrypt with it.
    $_ENV[AI_SETTINGS_ENV_VAR] = $newKey;
    putenv(AI_SETTINGS_ENV_VAR . '=' . $newKey);

    return true;
}

function ai_settings_encrypt(string $plain): string
{
    $key = ai_settings_get_key();
    $iv  = random_bytes(16);
    $ct  = openssl_encrypt($plain, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
    if ($ct === false) {
        throw new AiSettingsException('Encryption failed.');
    }
    return base64_encode($iv) . ':' . base64_encode($ct);
}

function ai_settings_decrypt(string $blob): string
{
    $key = ai_settings_get_key();
    $parts = explode(':', $blob, 2);
    if (count($parts) !== 2) {
        throw new AiSettingsException('Stored ciphertext is malformed.');
    }
    $iv = base64_decode($parts[0], true);
    $ct = base64_decode($parts[1], true);
    if ($iv === false || $ct === false || strlen($iv) !== 16) {
        throw new AiSettingsException('Stored ciphertext is malformed.');
    }
    $plain = openssl_decrypt($ct, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
    if ($plain === false) {
        throw new AiSettingsException('Decryption failed (wrong key or corrupted value).');
    }
    return $plain;
}

function ai_settings_get(string $key): ?string
{
    global $con;
    $stmt = $con->prepare("SELECT setting_value FROM ai_settings WHERE setting_key = ? LIMIT 1");
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) return null;
    return ai_settings_decrypt($row['setting_value']);
}

function ai_settings_get_meta(string $key): ?array
{
    global $con;
    $stmt = $con->prepare("SELECT s.setting_key, s.updated_at, s.updated_by, u.name AS updated_by_name
                             FROM ai_settings s
                             LEFT JOIN users u ON u.id = s.updated_by
                            WHERE s.setting_key = ? LIMIT 1");
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function ai_settings_set(string $key, string $value, int $user_id): void
{
    global $con;
    $blob = ai_settings_encrypt($value);
    $stmt = $con->prepare("INSERT INTO ai_settings (setting_key, setting_value, updated_by)
                           VALUES (?, ?, ?)
                           ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value),
                                                   updated_by    = VALUES(updated_by)");
    $stmt->bind_param('ssi', $key, $blob, $user_id);
    $stmt->execute();
    $stmt->close();

    // Activity log: action 'ai_settings_change', target = setting_key, user = caller.
    // The plaintext value is intentionally NOT logged.
    $_SESSION['user_id'] = $user_id;
    log_activity($con, 'ai_settings_change', 'ai_setting', $key, 'set');
}

function ai_settings_delete(string $key, int $user_id): void
{
    global $con;
    $stmt = $con->prepare("DELETE FROM ai_settings WHERE setting_key = ?");
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $stmt->close();

    $_SESSION['user_id'] = $user_id;
    log_activity($con, 'ai_settings_change', 'ai_setting', $key, 'delete');
}
