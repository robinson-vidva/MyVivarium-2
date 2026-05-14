<?php
/**
 * Dashboard summary service — read-only.
 *
 * Returns a single counts object for the current user, mirroring home.php.
 * Counts are permission-aware: a non-admin sees totals scoped to their own
 * visible state (tasks, notifications) and the global colony counts that
 * the dashboards already show every user.
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/permissions.php';

function dashboard_summary(mysqli $con, int $user_id): array
{
    $out = [];

    // Mouse count: alive only (matches home.php "active colony").
    $r = $con->query("SELECT COUNT(*) AS n FROM mice WHERE status = 'alive'");
    $out['mouse_count'] = (int)($r->fetch_assoc()['n'] ?? 0);

    // Holding vs breeding cage counts. A row in `breeding` flips a cage to
    // breeding; everything else with status='active' is holding.
    $r = $con->query("SELECT COUNT(*) AS n FROM cages c
                       WHERE c.status = 'active'
                         AND EXISTS (SELECT 1 FROM breeding b WHERE b.cage_id = c.cage_id)");
    $out['breeding_cage_count'] = (int)($r->fetch_assoc()['n'] ?? 0);
    $r = $con->query("SELECT COUNT(*) AS n FROM cages c
                       WHERE c.status = 'active'
                         AND NOT EXISTS (SELECT 1 FROM breeding b WHERE b.cage_id = c.cage_id)");
    $out['holding_cage_count'] = (int)($r->fetch_assoc()['n'] ?? 0);

    // My open tasks (assigned to me or authored, status != Completed).
    $stmt = $con->prepare("SELECT COUNT(*) AS n FROM tasks
                            WHERE status <> 'Completed'
                              AND (assigned_by = ? OR FIND_IN_SET(?, assigned_to))");
    $u = (string)$user_id;
    $stmt->bind_param('is', $user_id, $u);
    $stmt->execute();
    $out['my_open_tasks'] = (int)$stmt->get_result()->fetch_assoc()['n'];
    $stmt->close();

    // My unread notifications.
    $stmt = $con->prepare("SELECT COUNT(*) AS n FROM notifications WHERE user_id = ? AND is_read = 0");
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $out['my_unread_notifications'] = (int)$stmt->get_result()->fetch_assoc()['n'];
    $stmt->close();

    // Recent activity (last 7 days). Non-admins only count their own rows;
    // admins see the global total.
    if (perm_is_admin($con, $user_id)) {
        $r = $con->query("SELECT COUNT(*) AS n FROM activity_log WHERE created_at >= (NOW() - INTERVAL 7 DAY)");
        $out['recent_activity_count_7d'] = (int)($r->fetch_assoc()['n'] ?? 0);
    } else {
        $stmt = $con->prepare("SELECT COUNT(*) AS n FROM activity_log WHERE user_id = ? AND created_at >= (NOW() - INTERVAL 7 DAY)");
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $out['recent_activity_count_7d'] = (int)$stmt->get_result()->fetch_assoc()['n'];
        $stmt->close();
    }

    // Upcoming reminders in the next 7 days the user can see.
    $isAdmin = perm_is_admin($con, $user_id);
    if ($isAdmin) {
        $r = $con->query("SELECT COUNT(*) AS n FROM reminders WHERE status = 'active'");
        $out['upcoming_reminders_7d'] = (int)($r->fetch_assoc()['n'] ?? 0);
    } else {
        $stmt = $con->prepare("SELECT COUNT(*) AS n FROM reminders
                                WHERE status = 'active'
                                  AND (assigned_by = ? OR FIND_IN_SET(?, assigned_to))");
        $stmt->bind_param('is', $user_id, $u);
        $stmt->execute();
        $out['upcoming_reminders_7d'] = (int)$stmt->get_result()->fetch_assoc()['n'];
        $stmt->close();
    }

    return $out;
}
