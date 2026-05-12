<?php

/**
 * Holding Cage Archive/Restore/Delete Script
 *
 * This script handles archiving, restoring, and permanently deleting a holding cage.
 * Default action (no 'action' param): archives the cage by setting status to 'archived'.
 * action=permanent_delete: permanently deletes the cage and all related data.
 * action=restore: restores an archived cage by setting status back to 'active'.
 *
 */

// Start a new session or resume the existing session
require 'session_config.php';

// Include the database connection
require 'dbcon.php';

// Include the activity log helper
require_once 'log_activity.php';

// Check if the user is not logged in, redirect them to index.php
if (!isset($_SESSION['username'])) {
    header("Location: index.php");
    exit; // Exit to ensure no further code is executed
}

// State-changing actions require POST + CSRF — refuse GET outright so the
// CSRF check cannot be sidestepped via a one-click attacker URL.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: hc_dash.php");
    exit();
}

if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
    $_SESSION['message'] = 'CSRF token validation failed.';
    header("Location: hc_dash.php");
    exit();
}

$requestId      = $_POST['id'] ?? null;
$requestConfirm = $_POST['confirm'] ?? null;
$action         = $_POST['action'] ?? 'archive';

// Check if both 'id' and 'confirm' parameters are set, and if 'confirm' is 'true'
if (isset($requestId, $requestConfirm) && $requestConfirm == 'true') {
    // Use the request ID (already extracted above)
    $id = $requestId;

    // Start a transaction
    mysqli_begin_transaction($con);

    // Fetch the logged-in user's ID and role from the session
    $currentUserId = $_SESSION['user_id']; // Assuming user ID is stored in session
    $userRole = $_SESSION['role']; // Assuming user role is stored in session

    // Lock the cage row for the duration of the transaction so concurrent
    // delete/archive requests against the same cage serialize cleanly.
    $existsStmt = mysqli_prepare($con, "SELECT pi_name FROM cages WHERE cage_id = ? FOR UPDATE");
    if (!$existsStmt) {
        mysqli_rollback($con);
        $_SESSION['message'] = 'Error retrieving cage data.';
        header("Location: hc_dash.php");
        exit();
    }
    mysqli_stmt_bind_param($existsStmt, "s", $id);
    mysqli_stmt_execute($existsStmt);
    $existsResult = mysqli_stmt_get_result($existsStmt);
    $cageRow = mysqli_fetch_assoc($existsResult);
    mysqli_stmt_close($existsStmt);

    if (!$cageRow) {
        mysqli_rollback($con);
        $_SESSION['message'] = 'Cage not found.';
        header("Location: hc_dash.php");
        exit();
    }

    // Separate assignment lookup — "no assignees" is fine, just admin-only.
    $cageUsers = [];
    $usersStmt = mysqli_prepare($con, "SELECT user_id FROM cage_users WHERE cage_id = ?");
    if ($usersStmt) {
        mysqli_stmt_bind_param($usersStmt, "s", $id);
        mysqli_stmt_execute($usersStmt);
        $usersResult = mysqli_stmt_get_result($usersStmt);
        while ($row = mysqli_fetch_assoc($usersResult)) {
            if ($row['user_id']) {
                $cageUsers[] = $row['user_id'];
            }
        }
        mysqli_stmt_close($usersStmt);
    }

    // Check if the user is either an admin or assigned to the cage
    if ($userRole !== 'admin' && !in_array($currentUserId, $cageUsers)) {
        mysqli_rollback($con);
        $_SESSION['message'] = 'Access denied. Only the assigned user or an admin can perform this action.';
        header("Location: hc_dash.php");
        exit();
    }

    try {
        if ($action === 'permanent_delete') {
            // v2: mice are first-class entities; we don't delete them with the
            // cage. Instead, mark any mice currently in this cage as
            // transferred_out (their cage history will get the close stamp,
            // and current_cage_id auto-NULLs via FK ON DELETE SET NULL when
            // the cage row goes).
            $closeHist = $con->prepare("UPDATE mouse_cage_history SET moved_out_at = CURRENT_TIMESTAMP, reason = COALESCE(reason, 'cage permanently deleted') WHERE cage_id = ? AND moved_out_at IS NULL");
            $closeHist->bind_param("s", $id);
            if (!$closeHist->execute()) {
                throw new Exception("Error closing mouse history intervals: " . mysqli_error($con));
            }
            $closeHist->close();

            $orphanMice = $con->prepare("UPDATE mice SET status = 'transferred_out' WHERE current_cage_id = ? AND status = 'alive'");
            $orphanMice->bind_param("s", $id);
            if (!$orphanMice->execute()) {
                throw new Exception("Error updating orphan mice: " . mysqli_error($con));
            }
            $orphanMice->close();

            // Tables that need explicit cleanup before the cage row goes.
            // The `mice` table FK is ON DELETE SET NULL — current_cage_id auto-clears.
            // The `mouse_cage_history` FK is ON DELETE SET NULL — history rows survive with cage_id NULL.
            $tables = [
                'files'       => 'cage_id',
                'notes'       => 'cage_id',
                'cage_iacuc'  => 'cage_id',
                'cage_users'  => 'cage_id',
                'tasks'       => 'cage_id',
                'maintenance' => 'cage_id',
                'reminders'   => 'cage_id',
                'cages'       => 'cage_id',
            ];

            foreach ($tables as $table => $column) {
                $deleteQuery = "DELETE FROM $table WHERE $column = ?";
                if ($stmt = mysqli_prepare($con, $deleteQuery)) {
                    mysqli_stmt_bind_param($stmt, "s", $id);
                    if (!mysqli_stmt_execute($stmt)) {
                        throw new Exception("Error executing delete statement for $table table: " . mysqli_error($con));
                    }
                    mysqli_stmt_close($stmt);
                } else {
                    throw new Exception("Error preparing delete statement for $table table: " . mysqli_error($con));
                }
            }

            // Commit the transaction
            mysqli_commit($con);

            // Log the activity
            log_activity($con, 'delete', 'cage', $id, 'Cage permanently deleted');

            // Notify cage users about permanent deletion
            try {
            $actorName = $_SESSION['name'] ?? 'Someone';
            foreach ($cageUsers as $uid) {
                $uid = intval($uid);
                if ($uid > 0 && $uid != $currentUserId) {
                    $nTitle = "Cage Deleted: $id";
                    $nMessage = "$actorName permanently deleted holding cage $id";
                    $nLink = "hc_dash.php";
                    $nStmt = $con->prepare("INSERT INTO notifications (user_id, title, message, link, type) VALUES (?, ?, ?, ?, 'system')");
                    $nStmt->bind_param("isss", $uid, $nTitle, $nMessage, $nLink);
                    $nStmt->execute();
                    $nStmt->close();
                }
            }
            } catch (Exception $e) { error_log("Notification error: " . $e->getMessage()); }

            // Set a success message in the session
            $_SESSION['message'] = 'Cage ' . $id . ' and related data permanently deleted.';

        } elseif ($action === 'restore') {
            // Restore the cage by setting status back to 'active'
            $restoreQuery = "UPDATE cages SET status = 'active' WHERE cage_id = ?";
            if ($stmt = mysqli_prepare($con, $restoreQuery)) {
                mysqli_stmt_bind_param($stmt, "s", $id);
                if (!mysqli_stmt_execute($stmt)) {
                    throw new Exception("Error restoring cage: " . mysqli_error($con));
                }
                mysqli_stmt_close($stmt);
            } else {
                throw new Exception("Error preparing restore statement: " . mysqli_error($con));
            }

            // Commit the transaction
            mysqli_commit($con);

            // Log the activity
            log_activity($con, 'restore', 'cage', $id, 'Cage restored');

            // Notify cage users about restoration
            try {
            $actorName = $_SESSION['name'] ?? 'Someone';
            foreach ($cageUsers as $uid) {
                $uid = intval($uid);
                if ($uid > 0 && $uid != $currentUserId) {
                    $nTitle = "Cage Restored: $id";
                    $nMessage = "$actorName restored holding cage $id";
                    $nLink = "hc_view.php?id=" . urlencode($id);
                    $nStmt = $con->prepare("INSERT INTO notifications (user_id, title, message, link, type) VALUES (?, ?, ?, ?, 'system')");
                    $nStmt->bind_param("isss", $uid, $nTitle, $nMessage, $nLink);
                    $nStmt->execute();
                    $nStmt->close();
                }
            }
            } catch (Exception $e) { error_log("Notification error: " . $e->getMessage()); }

            // Set a success message in the session
            $_SESSION['message'] = 'Cage ' . $id . ' has been restored.';

        } else {
            // Default action: archive the cage by setting status to 'archived'
            $archiveQuery = "UPDATE cages SET status = 'archived' WHERE cage_id = ?";
            if ($stmt = mysqli_prepare($con, $archiveQuery)) {
                mysqli_stmt_bind_param($stmt, "s", $id);
                if (!mysqli_stmt_execute($stmt)) {
                    throw new Exception("Error archiving cage: " . mysqli_error($con));
                }
                mysqli_stmt_close($stmt);
            } else {
                throw new Exception("Error preparing archive statement: " . mysqli_error($con));
            }

            // Commit the transaction
            mysqli_commit($con);

            // Log the activity
            log_activity($con, 'archive', 'cage', $id, 'Cage archived');

            // Notify cage users about archival
            try {
            $actorName = $_SESSION['name'] ?? 'Someone';
            foreach ($cageUsers as $uid) {
                $uid = intval($uid);
                if ($uid > 0 && $uid != $currentUserId) {
                    $nTitle = "Cage Archived: $id";
                    $nMessage = "$actorName archived holding cage $id";
                    $nLink = "hc_dash.php";
                    $nStmt = $con->prepare("INSERT INTO notifications (user_id, title, message, link, type) VALUES (?, ?, ?, ?, 'system')");
                    $nStmt->bind_param("isss", $uid, $nTitle, $nMessage, $nLink);
                    $nStmt->execute();
                    $nStmt->close();
                }
            }
            } catch (Exception $e) { error_log("Notification error: " . $e->getMessage()); }

            // Set a success message in the session
            $_SESSION['message'] = 'Cage ' . $id . ' has been archived.';
        }
    } catch (Exception $e) {
        // Roll back the transaction
        mysqli_rollback($con);
        // Log the error and set a user-friendly message
        error_log($e->getMessage());
        $_SESSION['message'] = 'Error executing the requested action.';
    }

    // Redirect to the dashboard page
    header("Location: hc_dash.php");
    exit();
} else {
    // Set an error message if action is not confirmed or ID is missing
    $_SESSION['message'] = 'Action was not confirmed or ID parameter is missing.';
    // Redirect to the dashboard page
    header("Location: hc_dash.php");
    exit();
}
?>
