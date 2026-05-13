<?php
/**
 * API key management service.
 *
 * Keys are issued as 64-hex-char random tokens. We store only the
 * sha256(key) — the raw value is shown to the admin exactly once at
 * creation, then never recoverable.
 */

require_once __DIR__ . '/helpers.php';

const API_KEY_RAW_BYTES = 32;
const API_KEY_VALID_SCOPES = ['read', 'write'];

function api_key_hash(string $raw): string
{
    return hash('sha256', $raw);
}

function api_key_generate_raw(): string
{
    return bin2hex(random_bytes(API_KEY_RAW_BYTES));
}

/**
 * Look up an active (non-revoked) key by its raw value. Returns the key row
 * with the matching user, or null on no match.
 */
function api_key_lookup(mysqli $con, string $raw): ?array
{
    if ($raw === '') return null;
    $hash = api_key_hash($raw);
    $stmt = $con->prepare("SELECT k.id, k.user_id, k.label, k.scopes, k.revoked_at, k.expires_at,
                                  u.role, u.status
                             FROM api_keys k
                             JOIN users u ON u.id = k.user_id
                            WHERE k.key_hash = ? LIMIT 1");
    $stmt->bind_param('s', $hash);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $row;
}

/**
 * True if the key's expires_at is set and strictly in the past.
 */
function api_key_is_expired(array $keyRow): bool
{
    $exp = $keyRow['expires_at'] ?? null;
    if ($exp === null || $exp === '') return false;
    $ts = strtotime((string)$exp);
    return $ts !== false && $ts < time();
}

function api_key_mark_used(mysqli $con, int $key_id): void
{
    $stmt = $con->prepare("UPDATE api_keys SET last_used_at = CURRENT_TIMESTAMP WHERE id = ?");
    $stmt->bind_param('i', $key_id);
    $stmt->execute();
    $stmt->close();
}

function api_key_scopes(array $keyRow): array
{
    return array_filter(array_map('trim', explode(',', $keyRow['scopes'] ?? '')));
}

function api_key_has_scope(array $keyRow, string $scope): bool
{
    return in_array($scope, api_key_scopes($keyRow), true);
}

/**
 * Create a new API key. Returns ['raw' => 'shown once', 'row' => [...]].
 */
function api_key_create(mysqli $con, int $user_id, string $label, array $scopes): array
{
    foreach ($scopes as $s) {
        if (!in_array($s, API_KEY_VALID_SCOPES, true)) {
            throw new ApiException('invalid_argument', "Invalid scope: $s", 400);
        }
    }
    $scopes = array_values(array_unique($scopes));
    sort($scopes);
    $scopesStr = implode(',', $scopes);

    $raw  = api_key_generate_raw();
    $hash = api_key_hash($raw);
    $stmt = $con->prepare("INSERT INTO api_keys (user_id, key_hash, label, scopes) VALUES (?, ?, ?, ?)");
    $stmt->bind_param('isss', $user_id, $hash, $label, $scopesStr);
    $stmt->execute();
    $id = $stmt->insert_id;
    $stmt->close();

    return [
        'raw' => $raw,
        'row' => [
            'id'      => (int)$id,
            'user_id' => $user_id,
            'label'   => $label,
            'scopes'  => $scopes,
        ],
    ];
}

function api_key_revoke(mysqli $con, int $key_id): void
{
    $stmt = $con->prepare("UPDATE api_keys SET revoked_at = CURRENT_TIMESTAMP WHERE id = ? AND revoked_at IS NULL");
    $stmt->bind_param('i', $key_id);
    $stmt->execute();
    $stmt->close();
}

function api_key_list(mysqli $con): array
{
    $sql = "SELECT k.id, k.user_id, k.label, k.scopes, k.created_at, k.last_used_at, k.revoked_at,
                   u.name AS user_name, u.username AS user_email
              FROM api_keys k
              JOIN users u ON u.id = k.user_id
          ORDER BY k.revoked_at IS NULL DESC, k.created_at DESC";
    $res = $con->query($sql);
    $out = [];
    while ($r = $res->fetch_assoc()) $out[] = $r;
    return $out;
}
