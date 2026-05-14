<?php
/**
 * IACUC service — read-only.
 *
 * Permission: non-admins only see protocols that are linked (via cage_iacuc)
 * to at least one cage they have write access to.
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/permissions.php';

function iacuc_serialize(array $row): array
{
    return [
        'iacuc_id'    => $row['iacuc_id'],
        'iacuc_title' => $row['iacuc_title'],
        'file_url'    => $row['file_url'],
    ];
}

function iacuc_visible_subquery(): string
{
    // Subquery: protocols linked to at least one cage that has the current
    // user in cage_users. Bound with `i` (user_id).
    return "EXISTS (SELECT 1 FROM cage_iacuc ci INNER JOIN cage_users cu ON cu.cage_id = ci.cage_id
                     WHERE ci.iacuc_id = i.iacuc_id AND cu.user_id = ?)";
}

function iacuc_list(mysqli $con, int $user_id, array $filters): array
{
    [$limit, $offset] = svc_paginate($filters, 50, 200);
    $isAdmin = perm_is_admin($con, $user_id);

    $where = []; $args = []; $types = '';
    if (!$isAdmin) {
        $where[] = iacuc_visible_subquery();
        $args[] = $user_id; $types .= 'i';
    }
    // The schema has no status column on iacuc; we accept the param but treat
    // it as a no-op for now so callers can pass status without 400ing.
    svc_str($filters, 'status');

    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $cs = $con->prepare("SELECT COUNT(*) AS n FROM iacuc i $whereSql");
    if ($args) $cs->bind_param($types, ...$args);
    $cs->execute();
    $total = (int)$cs->get_result()->fetch_assoc()['n'];
    $cs->close();

    $sql = "SELECT i.* FROM iacuc i $whereSql ORDER BY i.iacuc_id ASC LIMIT ? OFFSET ?";
    $stmt = $con->prepare($sql);
    $a = $args; $a[] = $limit; $a[] = $offset; $t = $types . 'ii';
    $stmt->bind_param($t, ...$a);
    $stmt->execute();
    $res = $stmt->get_result();
    $out = [];
    while ($r = $res->fetch_assoc()) $out[] = iacuc_serialize($r);
    $stmt->close();
    return ['items' => $out, 'total' => $total, 'limit' => $limit, 'offset' => $offset];
}

function iacuc_get(mysqli $con, int $user_id, string $iacuc_id): array
{
    $stmt = $con->prepare("SELECT * FROM iacuc WHERE iacuc_id = ?");
    $stmt->bind_param('s', $iacuc_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) throw new ApiException('not_found', "IACUC $iacuc_id not found", 404);

    if (!perm_is_admin($con, $user_id)) {
        // Visibility check: at least one cage the user has access to must
        // reference this protocol.
        $vs = $con->prepare("SELECT 1 FROM cage_iacuc ci
                              INNER JOIN cage_users cu ON cu.cage_id = ci.cage_id
                              WHERE ci.iacuc_id = ? AND cu.user_id = ? LIMIT 1");
        $vs->bind_param('si', $iacuc_id, $user_id);
        $vs->execute();
        $ok = $vs->get_result()->num_rows > 0;
        $vs->close();
        if (!$ok) throw new ApiException('not_found', "IACUC $iacuc_id not found", 404);
    }

    return iacuc_serialize($row);
}
