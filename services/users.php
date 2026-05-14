<?php
/**
 * Users service — currently just the "me" lookup used by /api/v1/me.
 */

require_once __DIR__ . '/helpers.php';

function user_get_basic(mysqli $con, int $user_id): array
{
    $stmt = $con->prepare("SELECT id, name, username, role, position FROM users WHERE id = ?");
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) throw new ApiException('not_found', 'User not found', 404);
    return [
        'id'       => (int)$row['id'],
        'name'     => $row['name'],
        'email'    => $row['username'],
        'role'     => $row['role'],
        'position' => $row['position'],
    ];
}

/**
 * Self-profile read for /me/profile.
 *
 * EXPLICIT WHITELIST of editable / displayable user fields. Excludes
 * everything sensitive: password hash, reset tokens, login_attempts,
 * account_locked, email_token. The whitelist approach is intentional —
 * a blacklist would silently expose new sensitive columns added later.
 */
function user_get_my_profile(mysqli $con, int $user_id): array
{
    $stmt = $con->prepare("SELECT id, name, username, position, role, status,
                                  email_verified, initials
                             FROM users WHERE id = ?");
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) throw new ApiException('not_found', 'User not found', 404);

    return [
        'id'             => (int)$row['id'],
        'name'           => $row['name'],
        'email'          => $row['username'],
        'position'       => $row['position'],
        'role'           => $row['role'],
        'status'         => $row['status'],
        'email_verified' => (bool)((int)$row['email_verified']),
        'initials'       => $row['initials'],
    ];
}
