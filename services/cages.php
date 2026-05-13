<?php
/**
 * Cages service — holding cages and breeding cages.
 *
 * "Holding" vs "breeding" is determined by whether a row exists in the
 * `breeding` table for that cage_id (matches the convention used by
 * hc_view.php / bc_view.php).
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/permissions.php';

const CAGE_PATCH_EDITABLE = ['pi_name', 'remarks', 'room', 'rack'];

function cage_serialize(array $row): array
{
    return [
        'cage_id'    => $row['cage_id'],
        'pi_name'    => isset($row['pi_name']) ? (int)$row['pi_name'] : null,
        'quantity'   => isset($row['quantity']) ? (int)$row['quantity'] : null,
        'remarks'    => $row['remarks'] ?? null,
        'status'     => $row['status'] ?? null,
        'room'       => $row['room'] ?? null,
        'rack'       => $row['rack'] ?? null,
        'created_at' => $row['created_at'] ?? null,
    ];
}

/**
 * List cages partitioned by holding/breeding.
 * $kind = 'holding' | 'breeding'
 */
function cages_list(mysqli $con, int $user_id, string $kind, array $filters): array
{
    if (!in_array($kind, ['holding', 'breeding'], true)) {
        throw new ApiException('invalid_argument', "kind must be holding or breeding", 400);
    }
    [$limit, $offset] = svc_paginate($filters, 50, 200);

    $isBreeding = $kind === 'breeding';
    $joinCondition = $isBreeding
        ? "EXISTS (SELECT 1 FROM breeding b WHERE b.cage_id = c.cage_id)"
        : "NOT EXISTS (SELECT 1 FROM breeding b WHERE b.cage_id = c.cage_id)";

    $where = [$joinCondition, "c.status = 'active'"];
    $args = []; $types = '';

    $whereSql = 'WHERE ' . implode(' AND ', $where);

    $cs = $con->prepare("SELECT COUNT(*) AS n FROM cages c $whereSql");
    if ($types !== '') $cs->bind_param($types, ...$args);
    $cs->execute();
    $total = (int)$cs->get_result()->fetch_assoc()['n'];
    $cs->close();

    $sql = "SELECT c.* FROM cages c $whereSql ORDER BY c.cage_id ASC LIMIT ? OFFSET ?";
    $stmt = $con->prepare($sql);
    $a = $args; $a[] = $limit; $a[] = $offset; $t = $types . 'ii';
    $stmt->bind_param($t, ...$a);
    $stmt->execute();
    $res = $stmt->get_result();
    $out = [];
    while ($r = $res->fetch_assoc()) $out[] = cage_serialize($r);
    $stmt->close();
    return ['items' => $out, 'total' => $total, 'limit' => $limit, 'offset' => $offset];
}

function cage_load(mysqli $con, string $cage_id): ?array
{
    $stmt = $con->prepare("SELECT * FROM cages WHERE cage_id = ?");
    $stmt->bind_param('s', $cage_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res->fetch_assoc() ?: null;
    $stmt->close();
    return $row;
}

function cage_is_breeding(mysqli $con, string $cage_id): bool
{
    $stmt = $con->prepare("SELECT 1 FROM breeding WHERE cage_id = ? LIMIT 1");
    $stmt->bind_param('s', $cage_id);
    $stmt->execute();
    $is = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $is;
}

function cage_recent_notes(mysqli $con, string $cage_id, int $limit = 20): array
{
    $stmt = $con->prepare("SELECT id, cage_id, user_id, comments AS note_text, note_type, timestamp AS created_at, updated_at
                             FROM maintenance
                            WHERE cage_id = ? AND deleted_at IS NULL
                         ORDER BY timestamp DESC LIMIT ?");
    $stmt->bind_param('si', $cage_id, $limit);
    $stmt->execute();
    $res = $stmt->get_result();
    $out = [];
    while ($r = $res->fetch_assoc()) $out[] = $r;
    $stmt->close();
    return $out;
}

function holding_get(mysqli $con, int $user_id, string $cage_id): array
{
    $row = cage_load($con, $cage_id);
    if (!$row) throw new ApiException('not_found', "Cage $cage_id not found", 404);
    if (cage_is_breeding($con, $cage_id)) {
        throw new ApiException('not_found', "Cage $cage_id is a breeding cage; use /api/v1/cages/breeding/$cage_id", 404);
    }

    $out = cage_serialize($row);

    $stmt = $con->prepare("SELECT * FROM mice WHERE current_cage_id = ? AND status = 'alive' ORDER BY mouse_id ASC");
    $stmt->bind_param('s', $cage_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $mice = [];
    require_once __DIR__ . '/mice.php';
    while ($r = $res->fetch_assoc()) $mice[] = mice_serialize($r);
    $stmt->close();
    $out['occupants'] = $mice;

    $stmt = $con->prepare("SELECT user_id FROM cage_users WHERE cage_id = ?");
    $stmt->bind_param('s', $cage_id);
    $stmt->execute();
    $users = [];
    while ($r = $stmt->get_result()->fetch_assoc()) $users[] = (int)$r['user_id'];
    $stmt->close();
    $out['assigned_user_ids'] = $users;

    $out['recent_maintenance_notes'] = cage_recent_notes($con, $cage_id, 20);

    return $out;
}

function breeding_get(mysqli $con, int $user_id, string $cage_id): array
{
    $row = cage_load($con, $cage_id);
    if (!$row) throw new ApiException('not_found', "Cage $cage_id not found", 404);
    if (!cage_is_breeding($con, $cage_id)) {
        throw new ApiException('not_found', "Cage $cage_id is not a breeding cage", 404);
    }

    $out = cage_serialize($row);

    $stmt = $con->prepare("SELECT id, cage_id, `cross`, male_id, female_id FROM breeding WHERE cage_id = ?");
    $stmt->bind_param('s', $cage_id);
    $stmt->execute();
    $pairs = [];
    while ($r = $stmt->get_result()->fetch_assoc()) $pairs[] = $r;
    $stmt->close();
    $out['pairs'] = $pairs;

    $stmt = $con->prepare("SELECT id, cage_id, dom, litter_dob, pups_alive, pups_dead, pups_male, pups_female, remarks FROM litters WHERE cage_id = ? ORDER BY dom DESC");
    $stmt->bind_param('s', $cage_id);
    $stmt->execute();
    $litters = [];
    while ($r = $stmt->get_result()->fetch_assoc()) $litters[] = $r;
    $stmt->close();
    $out['litters'] = $litters;

    $out['recent_maintenance_notes'] = cage_recent_notes($con, $cage_id, 20);

    return $out;
}

function holding_create(mysqli $con, int $user_id, array $body): array
{
    $cage_id = svc_required_str($body, 'cage_id');
    $pi      = svc_int($body, 'pi_name');
    $room    = svc_str($body, 'room');
    $rack    = svc_str($body, 'rack');
    $remarks = svc_str($body, 'remarks');

    $chk = $con->prepare("SELECT 1 FROM cages WHERE cage_id = ?");
    $chk->bind_param('s', $cage_id);
    $chk->execute();
    if ($chk->get_result()->num_rows > 0) {
        $chk->close();
        throw new ApiException('conflict', "Cage $cage_id already exists", 409);
    }
    $chk->close();

    $stmt = $con->prepare("INSERT INTO cages (cage_id, pi_name, quantity, remarks, room, rack) VALUES (?, ?, 0, ?, ?, ?)");
    $stmt->bind_param('sisss', $cage_id, $pi, $remarks, $room, $rack);
    $stmt->execute();
    $stmt->close();

    // Auto-assign the creator so they have write access. Skip when the
    // creator is admin (admins already have universal write access).
    if (!perm_is_admin($con, $user_id)) {
        $ins = $con->prepare("INSERT INTO cage_users (cage_id, user_id) VALUES (?, ?)");
        $ins->bind_param('si', $cage_id, $user_id);
        $ins->execute();
        $ins->close();
    }

    return holding_get($con, $user_id, $cage_id);
}

function holding_update(mysqli $con, int $user_id, string $cage_id, array $body): array
{
    perm_require_write_cage($con, $user_id, $cage_id);
    if (cage_is_breeding($con, $cage_id)) {
        throw new ApiException('invalid_argument', "$cage_id is a breeding cage; use the breeding endpoint", 400);
    }

    $sets = []; $args = []; $types = '';
    foreach (CAGE_PATCH_EDITABLE as $field) {
        if (!array_key_exists($field, $body)) continue;
        $v = $body[$field];
        if ($v === '') $v = null;
        $sets[] = "`$field` = ?";
        $args[] = $v;
        $types .= $field === 'pi_name' ? 'i' : 's';
    }
    if (!$sets) throw new ApiException('invalid_argument', 'No editable fields provided', 400);

    $sql = "UPDATE cages SET " . implode(', ', $sets) . " WHERE cage_id = ?";
    $args[] = $cage_id; $types .= 's';
    $stmt = $con->prepare($sql);
    $stmt->bind_param($types, ...$args);
    $stmt->execute();
    $stmt->close();

    return holding_get($con, $user_id, $cage_id);
}

function breeding_create(mysqli $con, int $user_id, array $body): array
{
    $cage_id   = svc_required_str($body, 'cage_id');
    $cross     = svc_str($body, 'cross');
    $male_id   = svc_str($body, 'male_id');
    $female_id = svc_str($body, 'female_id');
    $pi        = svc_int($body, 'pi_name');
    $room      = svc_str($body, 'room');
    $rack      = svc_str($body, 'rack');
    $remarks   = svc_str($body, 'remarks');

    $chk = $con->prepare("SELECT 1 FROM cages WHERE cage_id = ?");
    $chk->bind_param('s', $cage_id);
    $chk->execute();
    if ($chk->get_result()->num_rows > 0) {
        $chk->close();
        throw new ApiException('conflict', "Cage $cage_id already exists", 409);
    }
    $chk->close();

    mysqli_begin_transaction($con);
    try {
        $ins = $con->prepare("INSERT INTO cages (cage_id, pi_name, remarks, room, rack) VALUES (?, ?, ?, ?, ?)");
        $ins->bind_param('sisss', $cage_id, $pi, $remarks, $room, $rack);
        $ins->execute();
        $ins->close();

        $br = $con->prepare("INSERT INTO breeding (cage_id, `cross`, male_id, female_id) VALUES (?, ?, ?, ?)");
        $br->bind_param('ssss', $cage_id, $cross, $male_id, $female_id);
        $br->execute();
        $br->close();

        if (!perm_is_admin($con, $user_id)) {
            $cu = $con->prepare("INSERT INTO cage_users (cage_id, user_id) VALUES (?, ?)");
            $cu->bind_param('si', $cage_id, $user_id);
            $cu->execute();
            $cu->close();
        }

        mysqli_commit($con);
    } catch (Throwable $e) {
        mysqli_rollback($con);
        throw new ApiException('server_error', 'Failed to create breeding cage: ' . $e->getMessage(), 500);
    }

    return breeding_get($con, $user_id, $cage_id);
}

function breeding_update(mysqli $con, int $user_id, string $cage_id, array $body): array
{
    perm_require_write_cage($con, $user_id, $cage_id);
    if (!cage_is_breeding($con, $cage_id)) {
        throw new ApiException('invalid_argument', "$cage_id is not a breeding cage", 400);
    }

    mysqli_begin_transaction($con);
    try {
        $sets = []; $args = []; $types = '';
        foreach (CAGE_PATCH_EDITABLE as $field) {
            if (!array_key_exists($field, $body)) continue;
            $v = $body[$field];
            if ($v === '') $v = null;
            $sets[] = "`$field` = ?";
            $args[] = $v;
            $types .= $field === 'pi_name' ? 'i' : 's';
        }
        if ($sets) {
            $sql = "UPDATE cages SET " . implode(', ', $sets) . " WHERE cage_id = ?";
            $args[] = $cage_id; $types .= 's';
            $stmt = $con->prepare($sql);
            $stmt->bind_param($types, ...$args);
            $stmt->execute();
            $stmt->close();
        }

        $brSets = []; $brArgs = []; $brTypes = '';
        foreach (['cross', 'male_id', 'female_id'] as $field) {
            if (!array_key_exists($field, $body)) continue;
            $v = $body[$field];
            if ($v === '') $v = null;
            $brSets[] = "`$field` = ?";
            $brArgs[] = $v;
            $brTypes .= 's';
        }
        if ($brSets) {
            $sql = "UPDATE breeding SET " . implode(', ', $brSets) . " WHERE cage_id = ?";
            $brArgs[] = $cage_id; $brTypes .= 's';
            $stmt = $con->prepare($sql);
            $stmt->bind_param($brTypes, ...$brArgs);
            $stmt->execute();
            $stmt->close();
        }
        if (!$sets && !$brSets) {
            throw new ApiException('invalid_argument', 'No editable fields provided', 400);
        }
        mysqli_commit($con);
    } catch (ApiException $e) {
        mysqli_rollback($con);
        throw $e;
    } catch (Throwable $e) {
        mysqli_rollback($con);
        throw new ApiException('server_error', 'Failed to update breeding cage: ' . $e->getMessage(), 500);
    }

    return breeding_get($con, $user_id, $cage_id);
}
