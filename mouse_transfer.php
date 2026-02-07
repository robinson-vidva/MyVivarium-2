<?php

/**
 * Mouse Transfer Handler
 *
 * Processes the transfer of a mouse from one holding cage to another.
 * Updates the mouse's cage_id and recalculates cage quantities.
 * Validates CSRF token, source/target cages, and mouse ownership.
 */

require 'session_config.php';
require 'dbcon.php';

if (!isset($_SESSION['username'])) {
    header("Location: index.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: hc_dash.php");
    exit;
}

// Validate CSRF
if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    die('CSRF token validation failed');
}

$mouse_db_id = filter_input(INPUT_POST, 'mouse_db_id', FILTER_VALIDATE_INT);
$source_cage_id = trim($_POST['source_cage_id'] ?? '');
$target_cage_id = trim($_POST['target_cage_id'] ?? '');

if (!$mouse_db_id || empty($source_cage_id) || empty($target_cage_id)) {
    $_SESSION['message'] = 'Invalid transfer parameters.';
    header("Location: hc_dash.php");
    exit;
}

if ($source_cage_id === $target_cage_id) {
    $_SESSION['message'] = 'Source and target cages must be different.';
    header("Location: hc_view.php?id=" . urlencode($source_cage_id));
    exit;
}

// Verify target cage exists and is active
$checkTarget = $con->prepare("SELECT 1 FROM cages WHERE cage_id = ? AND status = 'active'");
$checkTarget->bind_param("s", $target_cage_id);
$checkTarget->execute();
if ($checkTarget->get_result()->num_rows === 0) {
    $_SESSION['message'] = 'Target cage does not exist or is archived.';
    header("Location: hc_view.php?id=" . urlencode($source_cage_id));
    exit;
}
$checkTarget->close();

// Verify mouse belongs to source cage
$checkMouse = $con->prepare("SELECT mouse_id FROM mice WHERE id = ? AND cage_id = ?");
$checkMouse->bind_param("is", $mouse_db_id, $source_cage_id);
$checkMouse->execute();
$mouseResult = $checkMouse->get_result();
if ($mouseResult->num_rows === 0) {
    $_SESSION['message'] = 'Mouse not found in source cage.';
    header("Location: hc_view.php?id=" . urlencode($source_cage_id));
    exit;
}
$mouseRow = $mouseResult->fetch_assoc();
$mouse_id = $mouseRow['mouse_id'];
$checkMouse->close();

// Transfer the mouse
mysqli_begin_transaction($con);
try {
    $stmt = $con->prepare("UPDATE mice SET cage_id = ? WHERE id = ?");
    $stmt->bind_param("si", $target_cage_id, $mouse_db_id);
    $stmt->execute();
    $stmt->close();

    // Update source cage quantity
    $updateSrc = $con->prepare("UPDATE cages SET quantity = (SELECT COUNT(*) FROM mice WHERE cage_id = ?) WHERE cage_id = ?");
    $updateSrc->bind_param("ss", $source_cage_id, $source_cage_id);
    $updateSrc->execute();
    $updateSrc->close();

    // Update target cage quantity
    $updateTgt = $con->prepare("UPDATE cages SET quantity = (SELECT COUNT(*) FROM mice WHERE cage_id = ?) WHERE cage_id = ?");
    $updateTgt->bind_param("ss", $target_cage_id, $target_cage_id);
    $updateTgt->execute();
    $updateTgt->close();

    // Log the activity if log_activity.php exists
    if (file_exists('log_activity.php')) {
        require_once 'log_activity.php';
        log_activity($con, 'transfer', 'mouse', $mouse_id, "Transferred from $source_cage_id to $target_cage_id");
    }

    mysqli_commit($con);
    $_SESSION['message'] = "Mouse '" . htmlspecialchars($mouse_id) . "' transferred from " . htmlspecialchars($source_cage_id) . " to " . htmlspecialchars($target_cage_id) . " successfully.";
} catch (Exception $e) {
    mysqli_rollback($con);
    $_SESSION['message'] = 'Failed to transfer mouse.';
}

header("Location: hc_view.php?id=" . urlencode($source_cage_id));
exit;
