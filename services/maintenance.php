<?php
/**
 * Maintenance notes service.
 *
 * Stored in the existing `maintenance` table. The API exposes them as
 * "maintenance notes" because the table also tracks free-text comments
 * about cage housekeeping. The note_type / deleted_at / updated_at columns
 * were added by the API migration (see database/api_setup.php).
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/permissions.php';

const MAINT_EDIT_WINDOW_SECONDS = 24 * 3600;

function maint_serialize(array $row): array
{
    return [
        'id'         => (int)$row['id'],
        'cage_id'    => $row['cage_id'],
        'user_id'    => (int)$row['user_id'],
        'note_text'  => $row['comments'],
        'note_type'  => $row['note_type'] ?? null,
        'created_at' => $row['timestamp'],
        'updated_at' => $row['updated_at'] ?? null,
        'deleted_at' => $row['deleted_at'] ?? null,
    ];
}

function maint_list(mysqli $con, int $user_id, array $filters): array
{
    [$limit, $offset] = svc_paginate($filters, 50, 200);

    $where = ['m.deleted_at IS NULL']; $args = []; $types = '';
    $cage_id = svc_str($filters, 'cage_id');
    if ($cage_id !== null) { $where[] = 'm.cage_id = ?'; $args[] = $cage_id; $types .= 's'; }
    $from = svc_str($filters, 'from');
    if ($from !== null) { $where[] = 'm.timestamp >= ?'; $args[] = svc_parse_date($from, 'from'); $types .= 's'; }
    $to = svc_str($filters, 'to');
    if ($to !== null) { $where[] = 'm.timestamp <= ?'; $args[] = svc_parse_date($to, 'to'); $types .= 's'; }

    $whereSql = 'WHERE ' . implode(' AND ', $where);

    $cs = $con->prepare("SELECT COUNT(*) AS n FROM maintenance m $whereSql");
    if ($args) $cs->bind_param($types, ...$args);
    $cs->execute();
    $total = (int)$cs->get_result()->fetch_assoc()['n'];
    $cs->close();

    $sql = "SELECT m.* FROM maintenance m $whereSql ORDER BY m.timestamp DESC LIMIT ? OFFSET ?";
    $stmt = $con->prepare($sql);
    $a = $args; $a[] = $limit; $a[] = $offset; $t = $types . 'ii';
    $stmt->bind_param($t, ...$a);
    $stmt->execute();
    $res = $stmt->get_result();
    $out = [];
    while ($r = $res->fetch_assoc()) $out[] = maint_serialize($r);
    $stmt->close();
    return ['items' => $out, 'total' => $total, 'limit' => $limit, 'offset' => $offset];
}

function maint_load(mysqli $con, int $id): ?array
{
    $stmt = $con->prepare("SELECT * FROM maintenance WHERE id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $row;
}

function maint_create(mysqli $con, int $user_id, array $body): array
{
    $cage_id   = svc_required_str($body, 'cage_id');
    $note_text = svc_required_str($body, 'note_text');
    $note_type = svc_str($body, 'type');

    $chk = $con->prepare("SELECT 1 FROM cages WHERE cage_id = ?");
    $chk->bind_param('s', $cage_id);
    $chk->execute();
    if ($chk->get_result()->num_rows === 0) {
        $chk->close();
        throw new ApiException('not_found', "Cage $cage_id not found", 404);
    }
    $chk->close();

    perm_require_write_cage($con, $user_id, $cage_id);

    $ins = $con->prepare("INSERT INTO maintenance (cage_id, user_id, comments, note_type) VALUES (?, ?, ?, ?)");
    $ins->bind_param('siss', $cage_id, $user_id, $note_text, $note_type);
    $ins->execute();
    $new_id = $ins->insert_id;
    $ins->close();

    $row = maint_load($con, $new_id);
    return maint_serialize($row);
}

function maint_update(mysqli $con, int $user_id, int $note_id, array $body): array
{
    $row = maint_load($con, $note_id);
    if (!$row || $row['deleted_at'] !== null) {
        throw new ApiException('not_found', "Note $note_id not found", 404);
    }
    if ((int)$row['user_id'] !== $user_id) {
        throw new ApiException('forbidden', 'Only the note author can edit it', 403);
    }
    if (strtotime($row['timestamp']) < (time() - MAINT_EDIT_WINDOW_SECONDS)) {
        throw new ApiException('forbidden', 'Note is older than 24 hours and can no longer be edited', 403);
    }

    $sets = []; $args = []; $types = '';
    if (array_key_exists('note_text', $body)) {
        $v = svc_str($body, 'note_text');
        if ($v === null) throw new ApiException('invalid_argument', 'note_text cannot be empty', 400);
        $sets[] = '`comments` = ?'; $args[] = $v; $types .= 's';
    }
    if (array_key_exists('type', $body)) {
        $v = svc_str($body, 'type');
        $sets[] = '`note_type` = ?'; $args[] = $v; $types .= 's';
    }
    if (!$sets) throw new ApiException('invalid_argument', 'Provide note_text or type', 400);

    $sql = "UPDATE maintenance SET " . implode(', ', $sets) . " WHERE id = ?";
    $args[] = $note_id; $types .= 'i';
    $stmt = $con->prepare($sql);
    $stmt->bind_param($types, ...$args);
    $stmt->execute();
    $stmt->close();

    return maint_serialize(maint_load($con, $note_id));
}

function maint_soft_delete(mysqli $con, int $user_id, int $note_id): array
{
    $row = maint_load($con, $note_id);
    if (!$row || $row['deleted_at'] !== null) {
        throw new ApiException('not_found', "Note $note_id not found", 404);
    }
    if ((int)$row['user_id'] !== $user_id && !perm_is_admin($con, $user_id)) {
        throw new ApiException('forbidden', 'Only the note author (or an admin) can delete it', 403);
    }

    $stmt = $con->prepare("UPDATE maintenance SET deleted_at = CURRENT_TIMESTAMP WHERE id = ?");
    $stmt->bind_param('i', $note_id);
    $stmt->execute();
    $stmt->close();

    return ['id' => $note_id, 'deleted_at' => date('Y-m-d H:i:s')];
}

function maint_soft_delete_diff(mysqli $con, int $note_id): array
{
    $row = maint_load($con, $note_id);
    if (!$row) throw new ApiException('not_found', "Note $note_id not found", 404);
    return [
        'before' => ['deleted_at' => $row['deleted_at']],
        'after'  => ['deleted_at' => date('Y-m-d H:i:s')],
    ];
}
