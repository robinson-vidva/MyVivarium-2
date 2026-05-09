<?php

/**
 * Move Mouse to Cage
 *
 * Two-step write that maintains the cage history invariant:
 *   1. Close the currently-open history interval (the row with moved_out_at IS NULL).
 *   2. Open a new interval pointing at the target cage (or NULL for "remove from cage").
 *   3. Update mice.current_cage_id to match.
 *
 * Wrapped in a transaction so we never end up with two open intervals or with
 * current_cage_id out of sync with the open history row. CSRF-protected.
 */

require 'session_config.php';
require 'dbcon.php';
require_once 'log_activity.php';

if (!isset($_SESSION['username'])) { header('Location: index.php'); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: mouse_dash.php'); exit; }
if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) { die('CSRF token validation failed'); }

$mouse_id        = trim($_POST['mouse_id'] ?? '');
$target_cage_raw = trim($_POST['target_cage_id'] ?? '');
$reason          = trim($_POST['reason'] ?? '') ?: null;
$user_id         = $_SESSION['user_id'] ?? null;

// Optional. Pages that initiate a transfer from their own context (cage
// view, mouse_edit) pass this so we return there instead of bouncing
// the user to mouse_view. Restricted to a small allow-list to prevent
// open-redirect via a forged form input.
$redirectTo      = $_POST['redirect_to'] ?? '';
$allowedRedirect = ['mouse_view.php', 'hc_view.php', 'bc_view.php', 'mouse_edit.php', 'mouse_dash.php'];
$redirectBase    = $allowedRedirect[0];
foreach ($allowedRedirect as $cand) {
    if (strpos($redirectTo, $cand) === 0) { $redirectBase = $redirectTo; break; }
}

// "__none__" is the explicit "remove from cage" sentinel from the form
$target_cage = ($target_cage_raw === '__none__' || $target_cage_raw === '') ? null : $target_cage_raw;

if ($mouse_id === '') {
    $_SESSION['message'] = 'Missing mouse_id.';
    header('Location: mouse_dash.php'); exit;
}

// Load mouse
$stmt = $con->prepare("SELECT current_cage_id, status FROM mice WHERE mouse_id = ?");
$stmt->bind_param("s", $mouse_id);
$stmt->execute();
$res = $stmt->get_result();
if ($res->num_rows !== 1) {
    $_SESSION['message'] = 'Mouse not found.';
    header('Location: mouse_dash.php'); exit;
}
$row = $res->fetch_assoc();
$stmt->close();

if (in_array($row['status'], ['sacrificed','archived'], true)) {
    $_SESSION['message'] = 'Cannot move a sacrificed or archived mouse.';
    header("Location: mouse_view.php?id=" . urlencode($mouse_id)); exit;
}
if ($row['current_cage_id'] === $target_cage) {
    $_SESSION['message'] = 'Mouse is already in that cage.';
    header("Location: mouse_view.php?id=" . urlencode($mouse_id)); exit;
}

// Validate target cage exists & is active (skip when removing-from-cage)
if ($target_cage !== null) {
    $chk = $con->prepare("SELECT 1 FROM cages WHERE cage_id = ? AND status = 'active'");
    $chk->bind_param("s", $target_cage);
    $chk->execute();
    if ($chk->get_result()->num_rows === 0) {
        $_SESSION['message'] = "Target cage doesn't exist or is archived.";
        header("Location: mouse_view.php?id=" . urlencode($mouse_id)); exit;
    }
    $chk->close();
}

mysqli_begin_transaction($con);
try {
    // Close currently-open interval
    $close = $con->prepare("UPDATE mouse_cage_history SET moved_out_at = CURRENT_TIMESTAMP WHERE mouse_id = ? AND moved_out_at IS NULL");
    $close->bind_param("s", $mouse_id);
    $close->execute();
    $close->close();

    // Open new interval
    $open = $con->prepare("INSERT INTO mouse_cage_history (mouse_id, cage_id, reason, moved_by) VALUES (?, ?, ?, ?)");
    $open->bind_param("sssi", $mouse_id, $target_cage, $reason, $user_id);
    $open->execute();
    $open->close();

    // Update denormalized pointer + status if removing from cage
    $newStatus = $target_cage === null ? 'transferred_out' : 'alive';
    $upd = $con->prepare("UPDATE mice SET current_cage_id = ?, status = ? WHERE mouse_id = ?");
    $upd->bind_param("sss", $target_cage, $newStatus, $mouse_id);
    $upd->execute();
    $upd->close();

    mysqli_commit($con);
    log_activity($con, 'move', 'mouse', $mouse_id,
        "Moved " . ($row['current_cage_id'] ?? '(no cage)') . " → " . ($target_cage ?? '(no cage)') . ($reason ? ": $reason" : ''));

    $_SESSION['message'] = "Mouse moved to " . ($target_cage ?? 'no cage') . ".";
} catch (Exception $e) {
    mysqli_rollback($con);
    $_SESSION['message'] = 'Failed to move mouse: ' . $e->getMessage();
}

header("Location: " . ($redirectBase === 'mouse_view.php'
    ? 'mouse_view.php?id=' . urlencode($mouse_id)
    : $redirectBase));
exit;
