<?php
/**
 * Mice service.
 *
 * Wraps the same operations that mouse_addn / mouse_edit / mouse_move /
 * mouse_sacrifice perform via forms. Functions accept arrays and throw
 * ApiException on validation/permission failures.
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/permissions.php';

const MICE_ALLOWED_STATUSES   = ['alive', 'sacrificed', 'transferred_out', 'archived'];
const MICE_ALLOWED_SEX        = ['male', 'female', 'unknown'];
const MICE_PATCH_EDITABLE     = [
    'sex', 'dob', 'strain', 'genotype', 'ear_code',
    'sire_id', 'dam_id', 'sire_external_ref', 'dam_external_ref',
    'source_cage_label', 'notes',
];

function mice_serialize(array $row): array
{
    return [
        'mouse_id'           => $row['mouse_id'],
        'sex'                => $row['sex'],
        'dob'                => $row['dob'],
        'current_cage_id'    => $row['current_cage_id'],
        'strain'             => $row['strain'],
        'genotype'           => $row['genotype'],
        'ear_code'           => $row['ear_code'],
        'sire_id'            => $row['sire_id'],
        'dam_id'             => $row['dam_id'],
        'sire_external_ref'  => $row['sire_external_ref'],
        'dam_external_ref'   => $row['dam_external_ref'],
        'source_cage_label'  => $row['source_cage_label'],
        'status'             => $row['status'],
        'sacrificed_at'      => $row['sacrificed_at'],
        'sacrifice_reason'   => $row['sacrifice_reason'],
        'notes'              => $row['notes'],
        'created_by'         => isset($row['created_by']) ? (int)$row['created_by'] : null,
        'created_at'         => $row['created_at'] ?? null,
        'updated_at'         => $row['updated_at'] ?? null,
    ];
}

function mice_list(mysqli $con, int $user_id, array $filters): array
{
    [$limit, $offset] = svc_paginate($filters, 50, 200);

    $where = []; $args = []; $types = '';
    $status = svc_str($filters, 'status');
    if ($status !== null) {
        if (!in_array($status, MICE_ALLOWED_STATUSES, true)) {
            throw new ApiException('invalid_argument', 'status must be one of ' . implode(',', MICE_ALLOWED_STATUSES), 400);
        }
        $where[] = 'm.status = ?'; $args[] = $status; $types .= 's';
    } else {
        // Soft-deleted (archived) mice are hidden by default. Admins can opt in
        // with ?include_deleted=true; non-admins have the flag silently ignored.
        $includeDeleted = svc_str($filters, 'include_deleted');
        $wantDeleted = ($includeDeleted === '1' || strtolower((string)$includeDeleted) === 'true');
        if (!($wantDeleted && perm_is_admin($con, $user_id))) {
            $where[] = "m.status <> 'archived'";
        }
    }
    $sex = svc_str($filters, 'sex');
    if ($sex !== null) {
        if (!in_array($sex, MICE_ALLOWED_SEX, true)) {
            throw new ApiException('invalid_argument', 'sex must be one of ' . implode(',', MICE_ALLOWED_SEX), 400);
        }
        $where[] = 'm.sex = ?'; $args[] = $sex; $types .= 's';
    }
    $strain = svc_str($filters, 'strain');
    if ($strain !== null) { $where[] = 'm.strain = ?'; $args[] = $strain; $types .= 's'; }
    $cage_id = svc_str($filters, 'cage_id');
    if ($cage_id !== null) { $where[] = 'm.current_cage_id = ?'; $args[] = $cage_id; $types .= 's'; }

    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $countSql = "SELECT COUNT(*) AS n FROM mice m $whereSql";
    $cs = $con->prepare($countSql);
    if ($args) $cs->bind_param($types, ...$args);
    $cs->execute();
    $total = (int)$cs->get_result()->fetch_assoc()['n'];
    $cs->close();

    $sql = "SELECT m.* FROM mice m $whereSql ORDER BY m.mouse_id ASC LIMIT ? OFFSET ?";
    $stmt = $con->prepare($sql);
    $a = $args; $a[] = $limit; $a[] = $offset; $t = $types . 'ii';
    $stmt->bind_param($t, ...$a);
    $stmt->execute();
    $res = $stmt->get_result();
    $out = [];
    while ($r = $res->fetch_assoc()) $out[] = mice_serialize($r);
    $stmt->close();

    return ['items' => $out, 'total' => $total, 'limit' => $limit, 'offset' => $offset];
}

function mice_get(mysqli $con, int $user_id, string $mouse_id, array $filters = []): array
{
    $stmt = $con->prepare("SELECT m.*, sire.dob AS sire_dob, dam.dob AS dam_dob
                             FROM mice m
                             LEFT JOIN mice sire ON sire.mouse_id = m.sire_id
                             LEFT JOIN mice dam  ON dam.mouse_id  = m.dam_id
                            WHERE m.mouse_id = ?");
    $stmt->bind_param('s', $mouse_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res->num_rows === 0) {
        $stmt->close();
        throw new ApiException('not_found', "Mouse $mouse_id not found", 404);
    }
    $row = $res->fetch_assoc();
    $stmt->close();

    // Soft-deleted rows are hidden by default. Admins can opt in with
    // ?include_deleted=true; non-admins have the flag silently ignored.
    // Internal callers (mice_update, mice_move, mice_sacrifice, mice_soft_delete)
    // bypass via $filters['_internal'] so they can return the post-mutation row.
    if (($row['status'] ?? null) === 'archived' && empty($filters['_internal'])) {
        $includeDeleted = svc_str($filters, 'include_deleted');
        $wantDeleted = ($includeDeleted === '1' || strtolower((string)$includeDeleted) === 'true');
        if (!($wantDeleted && perm_is_admin($con, $user_id))) {
            throw new ApiException('not_found', "Mouse $mouse_id not found", 404);
        }
    }

    $out = mice_serialize($row);

    if ($row['current_cage_id']) {
        $cs = $con->prepare("SELECT cage_id, pi_name, room, rack, status FROM cages WHERE cage_id = ?");
        $cs->bind_param('s', $row['current_cage_id']);
        $cs->execute();
        $out['current_cage'] = $cs->get_result()->fetch_assoc() ?: null;
        $cs->close();
    } else {
        $out['current_cage'] = null;
    }

    $out['parents'] = [
        'sire' => $row['sire_id'] ? ['mouse_id' => $row['sire_id'], 'dob' => $row['sire_dob']] : null,
        'sire_external_ref' => $row['sire_external_ref'],
        'dam'  => $row['dam_id']  ? ['mouse_id' => $row['dam_id'],  'dob' => $row['dam_dob']]  : null,
        'dam_external_ref'  => $row['dam_external_ref'],
    ];

    return $out;
}

function mice_create(mysqli $con, int $user_id, array $body): array
{
    $mouse_id = svc_required_str($body, 'mouse_id');
    $sex      = strtolower(svc_str($body, 'sex', 'unknown'));
    if (!in_array($sex, MICE_ALLOWED_SEX, true)) $sex = 'unknown';
    $dob      = svc_str($body, 'dob');
    $cage_id  = svc_str($body, 'cage_id');
    $strain   = svc_str($body, 'strain');
    $genotype = svc_str($body, 'genotype');
    $ear_code = svc_str($body, 'ear_code');
    $notes    = svc_str($body, 'notes');

    if ($cage_id) {
        $cs = $con->prepare("SELECT 1 FROM cages WHERE cage_id = ? AND status = 'active'");
        $cs->bind_param('s', $cage_id);
        $cs->execute();
        if ($cs->get_result()->num_rows === 0) {
            $cs->close();
            throw new ApiException('invalid_argument', "cage_id $cage_id does not exist or is archived", 400);
        }
        $cs->close();
        perm_require_write_cage($con, $user_id, $cage_id);
    } else {
        if (!perm_is_admin($con, $user_id)) {
            throw new ApiException('forbidden', 'Only admins can create a mouse with no cage', 403);
        }
    }

    $dup = $con->prepare("SELECT 1 FROM mice WHERE mouse_id = ?");
    $dup->bind_param('s', $mouse_id);
    $dup->execute();
    if ($dup->get_result()->num_rows > 0) {
        $dup->close();
        throw new ApiException('conflict', "Mouse $mouse_id already exists", 409);
    }
    $dup->close();

    mysqli_begin_transaction($con);
    try {
        $ins = $con->prepare("INSERT INTO mice
            (mouse_id, sex, dob, current_cage_id, strain, genotype, ear_code, notes, status, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'alive', ?)");
        $ins->bind_param('ssssssssi', $mouse_id, $sex, $dob, $cage_id, $strain, $genotype, $ear_code, $notes, $user_id);
        $ins->execute();
        $ins->close();

        if ($cage_id) {
            $h = $con->prepare("INSERT INTO mouse_cage_history (mouse_id, cage_id, reason, moved_by)
                                VALUES (?, ?, 'initial registration (api)', ?)");
            $h->bind_param('ssi', $mouse_id, $cage_id, $user_id);
            $h->execute();
            $h->close();
        }
        mysqli_commit($con);
    } catch (Throwable $e) {
        mysqli_rollback($con);
        throw new ApiException('server_error', 'Failed to create mouse: ' . $e->getMessage(), 500);
    }

    return mice_get($con, $user_id, $mouse_id, ['_internal' => true]);
}

/**
 * Compute the proposed-vs-current diff for a PATCH, without writing. Used by
 * the confirm-before-execute flow. Returns ['before' => [...], 'after' => [...]].
 */
function mice_patch_diff(mysqli $con, string $mouse_id, array $body): array
{
    $stmt = $con->prepare("SELECT * FROM mice WHERE mouse_id = ?");
    $stmt->bind_param('s', $mouse_id);
    $stmt->execute();
    $cur = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$cur) throw new ApiException('not_found', "Mouse $mouse_id not found", 404);

    $before = []; $after = [];
    foreach (MICE_PATCH_EDITABLE as $field) {
        if (!array_key_exists($field, $body)) continue;
        $newVal = $body[$field];
        if ($newVal === '') $newVal = null;
        if (($cur[$field] ?? null) === $newVal) continue;
        $before[$field] = $cur[$field] ?? null;
        $after[$field]  = $newVal;
    }
    return ['before' => $before, 'after' => $after];
}

function mice_update(mysqli $con, int $user_id, string $mouse_id, array $body): array
{
    perm_require_write_mouse($con, $user_id, $mouse_id);

    $sets = []; $args = []; $types = '';
    foreach (MICE_PATCH_EDITABLE as $field) {
        if (!array_key_exists($field, $body)) continue;
        $v = $body[$field];
        if ($v === '') $v = null;
        if ($field === 'sex' && $v !== null && !in_array($v, MICE_ALLOWED_SEX, true)) {
            throw new ApiException('invalid_argument', 'sex must be one of ' . implode(',', MICE_ALLOWED_SEX), 400);
        }
        $sets[] = "`$field` = ?";
        $args[] = $v;
        $types .= 's';
    }
    if (!$sets) throw new ApiException('invalid_argument', 'No editable fields provided', 400);

    $sql = "UPDATE mice SET " . implode(', ', $sets) . " WHERE mouse_id = ?";
    $args[] = $mouse_id; $types .= 's';
    $stmt = $con->prepare($sql);
    $stmt->bind_param($types, ...$args);
    $stmt->execute();
    $stmt->close();

    return mice_get($con, $user_id, $mouse_id, ['_internal' => true]);
}

function mice_move(mysqli $con, int $user_id, string $mouse_id, array $body): array
{
    $to_cage = svc_str($body, 'to_cage_id');
    $reason  = svc_str($body, 'reason');

    $stmt = $con->prepare("SELECT current_cage_id, status FROM mice WHERE mouse_id = ?");
    $stmt->bind_param('s', $mouse_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) throw new ApiException('not_found', "Mouse $mouse_id not found", 404);
    if (in_array($row['status'], ['sacrificed', 'archived'], true)) {
        throw new ApiException('conflict', 'Cannot move a sacrificed or archived mouse', 409);
    }
    if ($row['current_cage_id'] === $to_cage) {
        throw new ApiException('conflict', 'Mouse is already in that cage', 409);
    }

    // The user must have write access to both the source cage (where the
    // mouse is leaving) and the destination cage (where it is arriving).
    if ($row['current_cage_id']) perm_require_write_cage($con, $user_id, $row['current_cage_id']);
    elseif (!perm_is_admin($con, $user_id)) {
        throw new ApiException('forbidden', "You don't have write access to this mouse", 403);
    }

    if ($to_cage !== null) {
        $chk = $con->prepare("SELECT 1 FROM cages WHERE cage_id = ? AND status = 'active'");
        $chk->bind_param('s', $to_cage);
        $chk->execute();
        if ($chk->get_result()->num_rows === 0) {
            $chk->close();
            throw new ApiException('invalid_argument', "Target cage $to_cage doesn't exist or is archived", 400);
        }
        $chk->close();
        perm_require_write_cage($con, $user_id, $to_cage);
    }

    mysqli_begin_transaction($con);
    try {
        $close = $con->prepare("UPDATE mouse_cage_history SET moved_out_at = CURRENT_TIMESTAMP WHERE mouse_id = ? AND moved_out_at IS NULL");
        $close->bind_param('s', $mouse_id);
        $close->execute();
        $close->close();

        $open = $con->prepare("INSERT INTO mouse_cage_history (mouse_id, cage_id, reason, moved_by) VALUES (?, ?, ?, ?)");
        $open->bind_param('sssi', $mouse_id, $to_cage, $reason, $user_id);
        $open->execute();
        $open->close();

        $newStatus = $to_cage === null ? 'transferred_out' : 'alive';
        $upd = $con->prepare("UPDATE mice SET current_cage_id = ?, status = ? WHERE mouse_id = ?");
        $upd->bind_param('sss', $to_cage, $newStatus, $mouse_id);
        $upd->execute();
        $upd->close();

        mysqli_commit($con);
    } catch (Throwable $e) {
        mysqli_rollback($con);
        throw new ApiException('server_error', 'Failed to move mouse: ' . $e->getMessage(), 500);
    }

    return mice_get($con, $user_id, $mouse_id, ['_internal' => true]);
}

function mice_move_diff(mysqli $con, string $mouse_id, array $body): array
{
    $stmt = $con->prepare("SELECT current_cage_id, status FROM mice WHERE mouse_id = ?");
    $stmt->bind_param('s', $mouse_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) throw new ApiException('not_found', "Mouse $mouse_id not found", 404);
    $to_cage = svc_str($body, 'to_cage_id');
    return [
        'before' => ['current_cage_id' => $row['current_cage_id']],
        'after'  => ['current_cage_id' => $to_cage],
    ];
}

function mice_sacrifice(mysqli $con, int $user_id, string $mouse_id, array $body): array
{
    perm_require_write_mouse($con, $user_id, $mouse_id);

    $date   = svc_required_str($body, 'date');
    $reason = svc_str($body, 'reason');

    $stmt = $con->prepare("SELECT status FROM mice WHERE mouse_id = ?");
    $stmt->bind_param('s', $mouse_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) throw new ApiException('not_found', "Mouse $mouse_id not found", 404);
    if ($row['status'] === 'sacrificed') {
        throw new ApiException('conflict', 'Mouse is already sacrificed', 409);
    }

    mysqli_begin_transaction($con);
    try {
        $close = $con->prepare("UPDATE mouse_cage_history SET moved_out_at = CURRENT_TIMESTAMP, reason = COALESCE(reason, 'sacrifice') WHERE mouse_id = ? AND moved_out_at IS NULL");
        $close->bind_param('s', $mouse_id);
        $close->execute();
        $close->close();

        $upd = $con->prepare("UPDATE mice SET status = 'sacrificed', sacrificed_at = ?, sacrifice_reason = ?, current_cage_id = NULL WHERE mouse_id = ?");
        $upd->bind_param('sss', $date, $reason, $mouse_id);
        $upd->execute();
        $upd->close();

        mysqli_commit($con);
    } catch (Throwable $e) {
        mysqli_rollback($con);
        throw new ApiException('server_error', 'Failed to sacrifice mouse: ' . $e->getMessage(), 500);
    }

    return mice_get($con, $user_id, $mouse_id, ['_internal' => true]);
}

function mice_sacrifice_diff(mysqli $con, string $mouse_id, array $body): array
{
    $stmt = $con->prepare("SELECT status, current_cage_id, sacrificed_at FROM mice WHERE mouse_id = ?");
    $stmt->bind_param('s', $mouse_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) throw new ApiException('not_found', "Mouse $mouse_id not found", 404);
    return [
        'before' => ['status' => $row['status'], 'current_cage_id' => $row['current_cage_id'], 'sacrificed_at' => $row['sacrificed_at']],
        'after'  => ['status' => 'sacrificed', 'current_cage_id' => null, 'sacrificed_at' => svc_str($body, 'date')],
    ];
}

/**
 * Soft delete = set status='archived'. Hard delete is the admin-only
 * mouse_drop.php page and is not exposed via the API.
 */
function mice_soft_delete(mysqli $con, int $user_id, string $mouse_id): array
{
    perm_require_write_mouse($con, $user_id, $mouse_id);

    $stmt = $con->prepare("SELECT status FROM mice WHERE mouse_id = ?");
    $stmt->bind_param('s', $mouse_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) throw new ApiException('not_found', "Mouse $mouse_id not found", 404);
    if ($row['status'] === 'archived') {
        throw new ApiException('conflict', 'Mouse is already archived', 409);
    }

    mysqli_begin_transaction($con);
    try {
        $close = $con->prepare("UPDATE mouse_cage_history SET moved_out_at = CURRENT_TIMESTAMP, reason = COALESCE(reason, 'archived via api') WHERE mouse_id = ? AND moved_out_at IS NULL");
        $close->bind_param('s', $mouse_id);
        $close->execute();
        $close->close();

        $upd = $con->prepare("UPDATE mice SET status = 'archived', current_cage_id = NULL WHERE mouse_id = ?");
        $upd->bind_param('s', $mouse_id);
        $upd->execute();
        $upd->close();

        mysqli_commit($con);
    } catch (Throwable $e) {
        mysqli_rollback($con);
        throw new ApiException('server_error', 'Failed to archive mouse: ' . $e->getMessage(), 500);
    }

    return mice_get($con, $user_id, $mouse_id, ['_internal' => true]);
}

function mice_soft_delete_diff(mysqli $con, string $mouse_id): array
{
    $stmt = $con->prepare("SELECT status, current_cage_id FROM mice WHERE mouse_id = ?");
    $stmt->bind_param('s', $mouse_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) throw new ApiException('not_found', "Mouse $mouse_id not found", 404);
    return [
        'before' => ['status' => $row['status'], 'current_cage_id' => $row['current_cage_id']],
        'after'  => ['status' => 'archived', 'current_cage_id' => null],
    ];
}
