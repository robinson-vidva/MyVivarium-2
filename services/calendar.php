<?php
/**
 * Calendar feed service — read-only.
 *
 * Merges tasks (with completion_date) and active reminders (next_due
 * estimated from their recurrence) into a single chronological feed.
 *
 * Each event has the shape:
 *   {
 *     source_type: "task" | "reminder",
 *     source_id: <int>,
 *     title: <string>,
 *     when: <ISO date or datetime>,
 *     status: <string>,
 *     cage_id: <string|null>
 *   }
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/permissions.php';
require_once __DIR__ . '/reminders.php';

function calendar_list(mysqli $con, int $user_id, array $filters): array
{
    $limit = svc_int($filters, 'limit', 200);
    if ($limit === null || $limit < 1) $limit = 200;
    if ($limit > 500) $limit = 500;

    $from = svc_str($filters, 'from');
    $to   = svc_str($filters, 'to');

    $fromDt = $from ? svc_parse_date($from, 'from') : null;
    $toDt   = $to   ? svc_parse_date($to,   'to')   : null;

    $isAdmin = perm_is_admin($con, $user_id);
    $events = [];

    // ---- Tasks (use completion_date when available) ----
    $tWhere = ["t.completion_date IS NOT NULL"];
    $tArgs = []; $tTypes = '';
    if ($fromDt) { $tWhere[] = 't.completion_date >= ?'; $tArgs[] = $fromDt; $tTypes .= 's'; }
    if ($toDt)   { $tWhere[] = 't.completion_date <= ?'; $tArgs[] = $toDt;   $tTypes .= 's'; }
    if (!$isAdmin) {
        $tWhere[] = '(t.assigned_by = ? OR FIND_IN_SET(?, t.assigned_to))';
        $tArgs[] = $user_id; $tTypes .= 'i';
        $tArgs[] = (string)$user_id; $tTypes .= 's';
    }
    $sql = "SELECT t.id, t.title, t.completion_date, t.status, t.cage_id FROM tasks t
            WHERE " . implode(' AND ', $tWhere) . " ORDER BY t.completion_date ASC LIMIT ?";
    $tArgs[] = $limit; $tTypes .= 'i';
    $stmt = $con->prepare($sql);
    $stmt->bind_param($tTypes, ...$tArgs);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) {
        $events[] = [
            'source_type' => 'task',
            'source_id'   => (int)$r['id'],
            'title'       => $r['title'],
            'when'        => $r['completion_date'],
            'status'      => $r['status'],
            'cage_id'     => $r['cage_id'],
        ];
    }
    $stmt->close();

    // ---- Active reminders → next_due ----
    $rWhere = ["r.status = 'active'"];
    $rArgs = []; $rTypes = '';
    if (!$isAdmin) {
        $rWhere[] = '(r.assigned_by = ? OR FIND_IN_SET(?, r.assigned_to))';
        $rArgs[] = $user_id; $rTypes .= 'i';
        $rArgs[] = (string)$user_id; $rTypes .= 's';
    }
    $sql = "SELECT r.* FROM reminders r WHERE " . implode(' AND ', $rWhere) . " LIMIT ?";
    $rArgs[] = $limit; $rTypes .= 'i';
    $stmt = $con->prepare($sql);
    if ($rTypes) $stmt->bind_param($rTypes, ...$rArgs);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) {
        $next = reminder_next_due($r);
        if (!$next) continue;
        if ($fromDt && $next < $fromDt) continue;
        if ($toDt   && $next > $toDt . ' 23:59:59') continue;
        $events[] = [
            'source_type' => 'reminder',
            'source_id'   => (int)$r['id'],
            'title'       => $r['title'],
            'when'        => $next,
            'status'      => $r['status'],
            'cage_id'     => $r['cage_id'],
        ];
    }
    $stmt->close();

    usort($events, fn($a, $b) => strcmp((string)$a['when'], (string)$b['when']));
    if (count($events) > $limit) $events = array_slice($events, 0, $limit);

    return ['items' => $events, 'total' => count($events), 'limit' => $limit, 'offset' => 0];
}
