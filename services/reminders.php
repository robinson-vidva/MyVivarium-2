<?php
/**
 * Reminders service — read-only. Writes ship in batch 2.
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/permissions.php';

function reminder_serialize(array $row): array
{
    return [
        'id'                => (int)$row['id'],
        'title'             => $row['title'],
        'description'       => $row['description'],
        'assigned_by'       => (int)$row['assigned_by'],
        'assigned_to'       => $row['assigned_to'],
        'recurrence_type'   => $row['recurrence_type'],
        'day_of_week'       => $row['day_of_week'],
        'day_of_month'      => $row['day_of_month'] !== null ? (int)$row['day_of_month'] : null,
        'time_of_day'       => $row['time_of_day'],
        'status'            => $row['status'],
        'cage_id'           => $row['cage_id'],
        'creation_date'     => $row['creation_date'] ?? null,
        'updated_at'        => $row['updated_at'] ?? null,
        'last_task_created' => $row['last_task_created'] ?? null,
    ];
}

/**
 * Estimate the next fire time for a reminder, based on its recurrence rules.
 * Returns an ISO datetime string, or null when the reminder is inactive.
 * Approximate; matches the cron logic in process_reminders.php for the common
 * cases (daily / weekly / monthly).
 */
function reminder_next_due(array $row, ?DateTimeImmutable $now = null): ?string
{
    if (($row['status'] ?? 'active') !== 'active') return null;
    $now = $now ?: new DateTimeImmutable('now');
    $time = $row['time_of_day'] ?: '09:00:00';

    switch ($row['recurrence_type']) {
        case 'daily':
            $today = new DateTimeImmutable($now->format('Y-m-d') . ' ' . $time);
            return ($today > $now ? $today : $today->modify('+1 day'))->format('c');
        case 'weekly':
            $target = $row['day_of_week'] ?: 'Monday';
            $next   = new DateTimeImmutable("next $target $time", $now->getTimezone());
            return $next->format('c');
        case 'monthly':
            $dom = (int)($row['day_of_month'] ?: 1);
            $candidate = $now->setDate((int)$now->format('Y'), (int)$now->format('m'), max(1, min(28, $dom)));
            $candidate = new DateTimeImmutable($candidate->format('Y-m-d') . ' ' . $time);
            if ($candidate <= $now) {
                $candidate = $candidate->modify('+1 month');
            }
            return $candidate->format('c');
    }
    return null;
}

function reminders_list(mysqli $con, int $user_id, array $filters): array
{
    [$limit, $offset] = svc_paginate($filters, 50, 200);

    $upcomingRaw = svc_str($filters, 'upcoming', 'true');
    $upcoming = ($upcomingRaw === '1' || strtolower((string)$upcomingRaw) === 'true' || $upcomingRaw === null);

    $where = []; $args = []; $types = '';
    if ($upcoming) {
        $where[] = "r.status = 'active'";
    }
    $cage_id = svc_str($filters, 'cage_id');
    if ($cage_id !== null) { $where[] = 'r.cage_id = ?'; $args[] = $cage_id; $types .= 's'; }

    if (!perm_is_admin($con, $user_id)) {
        $where[] = '(r.assigned_by = ? OR FIND_IN_SET(?, r.assigned_to))';
        $args[] = $user_id; $types .= 'i';
        $args[] = (string)$user_id; $types .= 's';
    }

    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $cs = $con->prepare("SELECT COUNT(*) AS n FROM reminders r $whereSql");
    if ($args) $cs->bind_param($types, ...$args);
    $cs->execute();
    $total = (int)$cs->get_result()->fetch_assoc()['n'];
    $cs->close();

    $sql = "SELECT r.* FROM reminders r $whereSql ORDER BY r.creation_date DESC LIMIT ? OFFSET ?";
    $stmt = $con->prepare($sql);
    $a = $args; $a[] = $limit; $a[] = $offset; $t = $types . 'ii';
    $stmt->bind_param($t, ...$a);
    $stmt->execute();
    $res = $stmt->get_result();
    $out = [];
    while ($r = $res->fetch_assoc()) {
        $item = reminder_serialize($r);
        $item['next_due'] = reminder_next_due($r);
        $out[] = $item;
    }
    $stmt->close();

    return ['items' => $out, 'total' => $total, 'limit' => $limit, 'offset' => $offset];
}

function reminders_get(mysqli $con, int $user_id, int $id): array
{
    $stmt = $con->prepare("SELECT * FROM reminders WHERE id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) throw new ApiException('not_found', "Reminder $id not found", 404);

    if (!perm_is_admin($con, $user_id)) {
        $isAuthor   = ((int)$row['assigned_by'] === $user_id);
        $isAssignee = in_array((string)$user_id, array_map('trim', explode(',', (string)$row['assigned_to'])), true);
        if (!$isAuthor && !$isAssignee) {
            throw new ApiException('not_found', "Reminder $id not found", 404);
        }
    }
    $out = reminder_serialize($row);
    $out['next_due'] = reminder_next_due($row);
    return $out;
}
