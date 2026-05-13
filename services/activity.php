<?php
/**
 * Activity log service. Read-only — entries are written by log_activity().
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/permissions.php';

function activity_list(mysqli $con, int $user_id, array $filters): array
{
    [$limit, $offset] = svc_paginate($filters, 50, 200);

    $where = []; $args = []; $types = '';

    // Non-admins can only see their own activity entries. Admins see everything.
    if (!perm_is_admin($con, $user_id)) {
        $where[] = 'a.user_id = ?'; $args[] = $user_id; $types .= 'i';
    } else {
        $fuser = svc_int($filters, 'user_id');
        if ($fuser !== null) { $where[] = 'a.user_id = ?'; $args[] = $fuser; $types .= 'i'; }
    }

    $action = svc_str($filters, 'action');
    if ($action !== null) { $where[] = 'a.action = ?'; $args[] = $action; $types .= 's'; }
    $from = svc_str($filters, 'from');
    if ($from !== null) { $where[] = 'a.created_at >= ?'; $args[] = svc_parse_date($from, 'from'); $types .= 's'; }
    $to = svc_str($filters, 'to');
    if ($to !== null) { $where[] = 'a.created_at <= ?'; $args[] = svc_parse_date($to, 'to'); $types .= 's'; }

    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $cs = $con->prepare("SELECT COUNT(*) AS n FROM activity_log a $whereSql");
    if ($args) $cs->bind_param($types, ...$args);
    $cs->execute();
    $total = (int)$cs->get_result()->fetch_assoc()['n'];
    $cs->close();

    $sql = "SELECT a.* FROM activity_log a $whereSql ORDER BY a.created_at DESC LIMIT ? OFFSET ?";
    $stmt = $con->prepare($sql);
    $a = $args; $a[] = $limit; $a[] = $offset; $t = $types . 'ii';
    $stmt->bind_param($t, ...$a);
    $stmt->execute();
    $res = $stmt->get_result();
    $out = [];
    while ($r = $res->fetch_assoc()) {
        $out[] = [
            'id'          => (int)$r['id'],
            'user_id'     => $r['user_id'] !== null ? (int)$r['user_id'] : null,
            'action'      => $r['action'],
            'entity_type' => $r['entity_type'],
            'entity_id'   => $r['entity_id'],
            'details'     => $r['details'],
            'ip_address'  => $r['ip_address'],
            'created_at'  => $r['created_at'],
        ];
    }
    $stmt->close();
    return ['items' => $out, 'total' => $total, 'limit' => $limit, 'offset' => $offset];
}
