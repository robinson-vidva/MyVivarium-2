<?php

/**
 * Sacrifice Mouse
 *
 * Marks a mouse as sacrificed. Closes the open cage-history interval and
 * clears current_cage_id (a sacrificed mouse no longer occupies a cage).
 * The record itself is preserved for archival/lineage.
 */

require 'session_config.php';
require 'dbcon.php';
require_once 'log_activity.php';

if (!isset($_SESSION['username'])) { header('Location: index.php'); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: mouse_dash.php'); exit; }
if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) { die('CSRF token validation failed'); }

$mouse_id      = trim($_POST['mouse_id'] ?? '');
$sacrificed_at = trim($_POST['sacrificed_at'] ?? '');
$reason        = trim($_POST['reason'] ?? '') ?: null;
$user_id       = $_SESSION['user_id'] ?? null;

if ($mouse_id === '' || $sacrificed_at === '') {
    $_SESSION['message'] = 'Mouse ID and date are required.';
    header('Location: mouse_dash.php'); exit;
}

$stmt = $con->prepare("SELECT status FROM mice WHERE mouse_id = ?");
$stmt->bind_param("s", $mouse_id);
$stmt->execute();
$res = $stmt->get_result();
if ($res->num_rows !== 1) {
    $_SESSION['message'] = 'Mouse not found.';
    header('Location: mouse_dash.php'); exit;
}
$row = $res->fetch_assoc();
$stmt->close();

if ($row['status'] === 'sacrificed') {
    $_SESSION['message'] = 'Mouse is already marked sacrificed.';
    header("Location: mouse_view.php?id=" . urlencode($mouse_id)); exit;
}

mysqli_begin_transaction($con);
try {
    // Close current cage-history interval if open
    $close = $con->prepare("UPDATE mouse_cage_history SET moved_out_at = CURRENT_TIMESTAMP, reason = COALESCE(reason, 'sacrifice') WHERE mouse_id = ? AND moved_out_at IS NULL");
    $close->bind_param("s", $mouse_id);
    $close->execute();
    $close->close();

    $upd = $con->prepare("UPDATE mice SET status = 'sacrificed', sacrificed_at = ?, sacrifice_reason = ?, current_cage_id = NULL WHERE mouse_id = ?");
    $upd->bind_param("sss", $sacrificed_at, $reason, $mouse_id);
    $upd->execute();
    $upd->close();

    mysqli_commit($con);
    log_activity($con, 'sacrifice', 'mouse', $mouse_id, "Sacrificed on $sacrificed_at" . ($reason ? ": $reason" : ''));
    $_SESSION['message'] = "Mouse marked sacrificed on $sacrificed_at.";
} catch (Exception $e) {
    mysqli_rollback($con);
    $_SESSION['message'] = 'Failed to mark sacrificed: ' . $e->getMessage();
}

header("Location: mouse_view.php?id=" . urlencode($mouse_id));
exit;
