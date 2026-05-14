<?php
/**
 * Notifications service — read-only.
 *
 * Strictly per-user: a caller can only ever see rows where notifications.user_id
 * matches the caller. Admins do not get to see other users' notifications via
 * this endpoint.
 */

require_once __DIR__ . '/helpers.php';

function notification_serialize(array $row): array
{
    return [
        'id'         => (int)$row['id'],
        'user_id'    => (int)$row['user_id'],
        'title'      => $row['title'],
        'message'    => $row['message'],
        'link'       => $row['link'],
        'type'       => $row['type'],
        'is_read'    => (bool)((int)$row['is_read']),
        'created_at' => $row['created_at'],
    ];
}

function notifications_list(mysqli $con, int $user_id, array $filters): array
{
    [$limit, $offset] = svc_paginate($filters, 50, 200);
    $readFilter = strtolower((string)svc_str($filters, 'read', 'unread'));

    $where = ['n.user_id = ?'];
    $args = [$user_id]; $types = 'i';
    if ($readFilter === 'unread') {
        $where[] = 'n.is_read = 0';
    } elseif ($readFilter === 'read') {
        $where[] = 'n.is_read = 1';
    } elseif ($readFilter !== 'all') {
        throw new ApiException('invalid_argument', "read must be all|unread|read", 400);
    }

    $whereSql = 'WHERE ' . implode(' AND ', $where);

    $cs = $con->prepare("SELECT COUNT(*) AS n FROM notifications n $whereSql");
    $cs->bind_param($types, ...$args);
    $cs->execute();
    $total = (int)$cs->get_result()->fetch_assoc()['n'];
    $cs->close();

    $sql = "SELECT n.* FROM notifications n $whereSql ORDER BY n.created_at DESC LIMIT ? OFFSET ?";
    $stmt = $con->prepare($sql);
    $a = $args; $a[] = $limit; $a[] = $offset; $t = $types . 'ii';
    $stmt->bind_param($t, ...$a);
    $stmt->execute();
    $res = $stmt->get_result();
    $out = [];
    while ($r = $res->fetch_assoc()) $out[] = notification_serialize($r);
    $stmt->close();
    return ['items' => $out, 'total' => $total, 'limit' => $limit, 'offset' => $offset];
}

function notifications_unread_count(mysqli $con, int $user_id): array
{
    $stmt = $con->prepare("SELECT COUNT(*) AS n FROM notifications WHERE user_id = ? AND is_read = 0");
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $count = (int)$stmt->get_result()->fetch_assoc()['n'];
    $stmt->close();
    return ['unread_count' => $count];
}
