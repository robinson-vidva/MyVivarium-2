<?php

/**
 * Mouse Hard Delete (Admin)
 *
 * Permanently removes a mouse from the system. Reserved for cleanup of
 * accidental entries or duplicates — for end-of-life, use sacrifice/archive.
 *
 * Gates:
 *   1. Caller must have role='admin'.
 *   2. CSRF token.
 *   3. Caller must retype the exact mouse_id ("type to confirm").
 *   4. Reason is required and gets recorded in activity_log BEFORE the
 *      delete, so the audit trail survives the delete itself.
 *
 * The DB schema does the rest: cage_history rows cascade-delete; sire/dam
 * FKs in offspring nullify automatically (so children stay registered with
 * their parent reference cleared).
 */

require 'session_config.php';
require 'dbcon.php';
require_once 'log_activity.php';

if (!isset($_SESSION['username'])) { header('Location: index.php'); exit; }
if (($_SESSION['role'] ?? '') !== 'admin') {
    $_SESSION['message'] = 'Hard delete is restricted to admins.';
    header('Location: mouse_dash.php'); exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: mouse_dash.php'); exit; }
if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) { die('CSRF token validation failed'); }

$mouse_id    = trim($_POST['mouse_id'] ?? '');
$confirm_id  = trim($_POST['confirm_mouse_id'] ?? '');
$reason      = trim($_POST['reason'] ?? '');

if ($mouse_id === '' || $confirm_id !== $mouse_id) {
    $_SESSION['message'] = 'Confirmation mismatch — type the exact Mouse ID to delete.';
    header("Location: mouse_view.php?id=" . urlencode($mouse_id)); exit;
}
if (strlen($reason) < 3) {
    $_SESSION['message'] = 'A reason (min 3 chars) is required for hard delete.';
    header("Location: mouse_view.php?id=" . urlencode($mouse_id)); exit;
}

$check = $con->prepare("SELECT 1 FROM mice WHERE mouse_id = ?");
$check->bind_param("s", $mouse_id);
$check->execute();
if ($check->get_result()->num_rows === 0) {
    $_SESSION['message'] = 'Mouse not found.';
    header('Location: mouse_dash.php'); exit;
}
$check->close();

// Audit FIRST — so the record of the deletion survives even if the row goes.
log_activity($con, 'hard_delete', 'mouse', $mouse_id, "Admin hard delete. Reason: $reason");

$del = $con->prepare("DELETE FROM mice WHERE mouse_id = ?");
$del->bind_param("s", $mouse_id);
if ($del->execute()) {
    $_SESSION['message'] = "Mouse '" . htmlspecialchars($mouse_id) . "' permanently deleted.";
    header('Location: mouse_dash.php');
} else {
    $_SESSION['message'] = 'Delete failed: ' . htmlspecialchars($con->error);
    header("Location: mouse_view.php?id=" . urlencode($mouse_id));
}
$del->close();
exit;
