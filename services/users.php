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
