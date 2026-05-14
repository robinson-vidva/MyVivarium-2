<?php
/**
 * Tasks service — read-only. Writes ship in batch 2.
 *
 * `tasks.assigned_to` is a CSV of user IDs (legacy). Visibility for
 * non-admins: author OR appears in the CSV. Admins see all tasks.
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/permissions.php';

const TASK_STATUSES = ['Pending', 'In Progress', 'Completed'];

function task_serialize(array $row): array
{
    return [
        'id'              => (int)$row['id'],
        'title'           => $row['title'],
        'description'     => $row['description'],
        'assigned_by'     => $row['assigned_by'] !== null ? (int)$row['assigned_by'] : null,
        'assigned_to'     => $row['assigned_to'],
        'status'          => $row['status'],
        'completion_date' => $row['completion_date'],
        'cage_id'         => $row['cage_id'],
        'creation_date'   => $row['creation_date'] ?? null,
        'updated_at'      => $row['updated_at'] ?? null,
    ];
}

function tasks_list(mysqli $con, int $user_id, array $filters): array
{
    [$limit, $offset] = svc_paginate($filters, 50, 200);

    $where = []; $args = []; $types = '';

    $statusFilter = strtolower((string)svc_str($filters, 'status', 'open'));
    if ($statusFilter === 'open') {
        $where[] = "t.status <> 'Completed'";
    } elseif ($statusFilter === 'done') {
        $where[] = "t.status = 'Completed'";
    } elseif ($statusFilter !== 'all') {
        throw new ApiException('invalid_argument', "status must be open|done|all", 400);
    }

    $assignedRaw = svc_str($filters, 'assigned_to_me');
    $assignedToMe = ($assignedRaw === '1' || strtolower((string)$assignedRaw) === 'true');

    $cage_id = svc_str($filters, 'cage_id');
    if ($cage_id !== null) { $where[] = 't.cage_id = ?'; $args[] = $cage_id; $types .= 's'; }

    $isAdmin = perm_is_admin($con, $user_id);
    if (!$isAdmin) {
        // Visibility: author OR appears in CSV of assignees.
        $where[] = '(t.assigned_by = ? OR FIND_IN_SET(?, t.assigned_to))';
        $args[] = $user_id; $types .= 'i';
        $args[] = (string)$user_id; $types .= 's';
    } elseif ($assignedToMe) {
        $where[] = '(t.assigned_by = ? OR FIND_IN_SET(?, t.assigned_to))';
        $args[] = $user_id; $types .= 'i';
        $args[] = (string)$user_id; $types .= 's';
    }

    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $cs = $con->prepare("SELECT COUNT(*) AS n FROM tasks t $whereSql");
    if ($args) $cs->bind_param($types, ...$args);
    $cs->execute();
    $total = (int)$cs->get_result()->fetch_assoc()['n'];
    $cs->close();

    $sql = "SELECT t.* FROM tasks t $whereSql ORDER BY t.creation_date DESC LIMIT ? OFFSET ?";
    $stmt = $con->prepare($sql);
    $a = $args; $a[] = $limit; $a[] = $offset; $t = $types . 'ii';
    $stmt->bind_param($t, ...$a);
    $stmt->execute();
    $res = $stmt->get_result();
    $out = [];
    while ($r = $res->fetch_assoc()) $out[] = task_serialize($r);
    $stmt->close();
    return ['items' => $out, 'total' => $total, 'limit' => $limit, 'offset' => $offset];
}

function tasks_get(mysqli $con, int $user_id, int $id): array
{
    $stmt = $con->prepare("SELECT * FROM tasks WHERE id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) throw new ApiException('not_found', "Task $id not found", 404);

    if (!perm_is_admin($con, $user_id)) {
        $isAuthor   = ((int)($row['assigned_by'] ?? 0) === $user_id);
        $isAssignee = in_array((string)$user_id, array_map('trim', explode(',', (string)$row['assigned_to'])), true);
        if (!$isAuthor && !$isAssignee) {
            throw new ApiException('not_found', "Task $id not found", 404);
        }
    }

    return task_serialize($row);
}
