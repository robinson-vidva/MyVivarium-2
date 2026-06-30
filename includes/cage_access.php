<?php
/**
 * Web-layer cage access helpers.
 *
 * Mirrors the per-cage write rule from services/permissions.php for the page
 * handlers (mouse_edit / mouse_move / mouse_sacrifice). That service layer
 * throws ApiException; the web pages want a plain boolean they can turn into a
 * redirect + flash message, so the rule is duplicated here intentionally:
 *
 *   - admin            → always allowed
 *   - view-only roles  → never (role_can_write() is false)
 *   - write-capable    → only when listed in cage_users for the mouse's cage
 *   - a mouse with no current cage → admin-only
 */

require_once __DIR__ . '/../services/roles.php';

function cage_user_is_assigned(mysqli $con, int $userId, string $cageId): bool
{
    $stmt = $con->prepare("SELECT 1 FROM cage_users WHERE cage_id = ? AND user_id = ? LIMIT 1");
    $stmt->bind_param("si", $cageId, $userId);
    $stmt->execute();
    $ok = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $ok;
}

/**
 * Can this session user write to a mouse whose current cage is $cageId?
 * Matches services/permissions.php::perm_can_write_mouse.
 */
function cage_user_can_write_mouse(mysqli $con, ?int $userId, ?string $role, ?string $cageId): bool
{
    if ($role === 'admin') return true;
    if (!role_can_write($role)) return false;
    if ($userId === null || $cageId === null || $cageId === '') return false;
    return cage_user_is_assigned($con, (int)$userId, $cageId);
}
