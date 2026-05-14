<?php
/**
 * Strains service — read-only. Strains are global and visible to every
 * authenticated user.
 */

require_once __DIR__ . '/helpers.php';

function strain_serialize(array $row): array
{
    return [
        'id'    => (int)$row['id'],
        'str_id'  => $row['str_id'],
        'name'    => $row['str_name'],
        'aka'     => $row['str_aka'],
        'url'     => $row['str_url'],
        'rrid'    => $row['str_rrid'],
        'notes'   => $row['str_notes'],
    ];
}

function strains_list(mysqli $con, int $user_id, array $filters): array
{
    [$limit, $offset] = svc_paginate($filters, 50, 200);
    $where = []; $args = []; $types = '';

    $search = svc_str($filters, 'search');
    if ($search !== null && $search !== '') {
        $like = '%' . $search . '%';
        $where[] = '(s.str_id LIKE ? OR s.str_name LIKE ? OR s.str_aka LIKE ? OR s.str_rrid LIKE ?)';
        $args[] = $like; $args[] = $like; $args[] = $like; $args[] = $like;
        $types .= 'ssss';
    }
    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $cs = $con->prepare("SELECT COUNT(*) AS n FROM strains s $whereSql");
    if ($args) $cs->bind_param($types, ...$args);
    $cs->execute();
    $total = (int)$cs->get_result()->fetch_assoc()['n'];
    $cs->close();

    $sql = "SELECT s.* FROM strains s $whereSql ORDER BY s.str_id ASC LIMIT ? OFFSET ?";
    $stmt = $con->prepare($sql);
    $a = $args; $a[] = $limit; $a[] = $offset; $t = $types . 'ii';
    $stmt->bind_param($t, ...$a);
    $stmt->execute();
    $res = $stmt->get_result();
    $out = [];
    while ($r = $res->fetch_assoc()) $out[] = strain_serialize($r);
    $stmt->close();
    return ['items' => $out, 'total' => $total, 'limit' => $limit, 'offset' => $offset];
}

function strains_get(mysqli $con, int $user_id, string $str_id): array
{
    $stmt = $con->prepare("SELECT * FROM strains WHERE str_id = ?");
    $stmt->bind_param('s', $str_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) throw new ApiException('not_found', "Strain $str_id not found", 404);
    return strain_serialize($row);
}
