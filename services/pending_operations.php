<?php
/**
 * Pending-operation storage for the confirm-before-execute flow.
 *
 * When a destructive request comes in with `X-Confirm-Token: pending`, the
 * router:
 *   1. Validates the request fully (permissions, body shape, target exists)
 *   2. Computes a diff via the service's *_diff() function
 *   3. Stores a row here with the validated body_json + diff_json
 *   4. Returns the new pending_operation_id to the caller
 *
 * On the follow-up call with `X-Confirm-Token: <id>`, the router looks up
 * the row, replays the stored body, and stamps executed_at.
 */

require_once __DIR__ . '/helpers.php';

const PENDING_OP_TTL_SECONDS = 300; // 5 minutes

function pending_op_create(mysqli $con, int $user_id, string $method, string $path, array $body, array $diff): array
{
    $id = svc_uuid_v4();
    $body_json = json_encode($body, JSON_UNESCAPED_SLASHES);
    $diff_json = json_encode($diff, JSON_UNESCAPED_SLASHES);
    $expires = date('Y-m-d H:i:s', time() + PENDING_OP_TTL_SECONDS);

    $stmt = $con->prepare("INSERT INTO pending_operations (id, user_id, method, path, body_json, diff_json, expires_at) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param('sisssss', $id, $user_id, $method, $path, $body_json, $diff_json, $expires);
    $stmt->execute();
    $stmt->close();

    return [
        'id'         => $id,
        'expires_at' => date('c', time() + PENDING_OP_TTL_SECONDS),
        'diff'       => $diff,
    ];
}

function pending_op_consume(mysqli $con, string $token, int $user_id, string $method, string $path): array
{
    $stmt = $con->prepare("SELECT id, user_id, method, path, body_json, expires_at, executed_at
                             FROM pending_operations WHERE id = ?");
    $stmt->bind_param('s', $token);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) throw new ApiException('not_found', 'Pending operation not found', 404);
    if ((int)$row['user_id'] !== $user_id) {
        throw new ApiException('forbidden', 'Pending operation belongs to a different user', 403);
    }
    if ($row['executed_at'] !== null) {
        throw new ApiException('conflict', 'Pending operation has already been executed', 409);
    }
    if (strtotime($row['expires_at']) < time()) {
        throw new ApiException('expired', 'Pending operation has expired', 410);
    }
    if ($row['method'] !== $method || $row['path'] !== $path) {
        throw new ApiException('invalid_argument', 'Pending operation method/path mismatch', 400);
    }

    return [
        'id'   => $row['id'],
        'body' => json_decode($row['body_json'], true) ?? [],
    ];
}

function pending_op_mark_executed(mysqli $con, string $token): void
{
    $stmt = $con->prepare("UPDATE pending_operations SET executed_at = CURRENT_TIMESTAMP WHERE id = ? AND executed_at IS NULL");
    $stmt->bind_param('s', $token);
    $stmt->execute();
    $stmt->close();
}
