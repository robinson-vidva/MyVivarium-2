<?php
/**
 * Permission helpers.
 *
 * Mirrors the rules baked into the existing pages:
 *   - Any approved user can SEE every cage and mouse (the dashboards list
 *     them all; only action buttons are gated).
 *   - WRITE operations against a cage require either role=admin or the user
 *     being listed in cage_users for that cage.
 *   - Mice inherit their cage's write permission via current_cage_id. A
 *     mouse with no current cage is admin-only for writes.
 *   - Maintenance notes are write-permitted to anyone who can write to the
 *     note's cage (matches the existing maintenance.php behavior).
 */

require_once __DIR__ . '/helpers.php';

function perm_user_role(mysqli $con, int $user_id): ?string
{
    static $cache = [];
    if (array_key_exists($user_id, $cache)) return $cache[$user_id];
    $stmt = $con->prepare("SELECT role FROM users WHERE id = ?");
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $cache[$user_id] = $row['role'] ?? null;
    return $cache[$user_id];
}

function perm_is_admin(mysqli $con, int $user_id): bool
{
    return perm_user_role($con, $user_id) === 'admin';
}

/**
 * Can the user modify this cage? (admin OR assigned in cage_users)
 */
function perm_can_write_cage(mysqli $con, int $user_id, string $cage_id): bool
{
    if (perm_is_admin($con, $user_id)) return true;
    $stmt = $con->prepare("SELECT 1 FROM cage_users WHERE cage_id = ? AND user_id = ? LIMIT 1");
    $stmt->bind_param('si', $cage_id, $user_id);
    $stmt->execute();
    $ok = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $ok;
}

/**
 * Can the user see this cage? Today: every authenticated user can. Kept as
 * a function so we can tighten visibility later without changing callers.
 */
function perm_can_read_cage(mysqli $con, int $user_id, string $cage_id): bool
{
    $stmt = $con->prepare("SELECT 1 FROM cages WHERE cage_id = ? LIMIT 1");
    $stmt->bind_param('s', $cage_id);
    $stmt->execute();
    $exists = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $exists;
}

/**
 * Can the user modify this mouse? Same rule as the mouse's cage. A mouse
 * with no cage falls back to admin-only writes.
 */
function perm_can_write_mouse(mysqli $con, int $user_id, string $mouse_id): bool
{
    if (perm_is_admin($con, $user_id)) return true;
    $stmt = $con->prepare("SELECT current_cage_id FROM mice WHERE mouse_id = ?");
    $stmt->bind_param('s', $mouse_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res->num_rows === 0) {
        $stmt->close();
        throw new ApiException('not_found', "Mouse $mouse_id not found", 404);
    }
    $row = $res->fetch_assoc();
    $stmt->close();
    if ($row['current_cage_id'] === null) return false;
    return perm_can_write_cage($con, $user_id, $row['current_cage_id']);
}

function perm_require_write_cage(mysqli $con, int $user_id, string $cage_id): void
{
    if (!perm_can_write_cage($con, $user_id, $cage_id)) {
        throw new ApiException('forbidden', "You don't have write access to cage $cage_id", 403);
    }
}

function perm_require_write_mouse(mysqli $con, int $user_id, string $mouse_id): void
{
    if (!perm_can_write_mouse($con, $user_id, $mouse_id)) {
        throw new ApiException('forbidden', "You don't have write access to mouse $mouse_id", 403);
    }
}
