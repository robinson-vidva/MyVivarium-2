<?php

/**
 * Breeding Cage Archive/Restore/Delete Script
 *
 * This script handles archiving, restoring, and permanently deleting a breeding cage.
 * Default action (no 'action' param): archives the cage by setting status to 'archived'.
 * action=permanent_delete: permanently deletes the cage and all related data.
 * action=restore: restores an archived cage by setting status back to 'active'.
 *
 */

// Start a new session or resume the existing session
require 'session_config.php';

// Include the database connection
require 'dbcon.php';

// Check if the user is not logged in, redirect them to index.php with the current URL for redirection after login
if (!isset($_SESSION['username'])) {
    header("Location: index.php");
    exit; // Exit to ensure no further code is executed
}

// Accept both POST (preferred) and GET (legacy) requests for deletion
$requestId = $_POST['id'] ?? $_GET['id'] ?? null;
$requestConfirm = $_POST['confirm'] ?? $_GET['confirm'] ?? null;

// Validate CSRF token for POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
        $_SESSION['message'] = 'CSRF token validation failed.';
        header("Location: bc_dash.php");
        exit();
    }
}

// Determine the action: default is 'archive', also supports 'permanent_delete' and 'restore'
$action = $_POST['action'] ?? $_GET['action'] ?? 'archive';

// Check if both 'id' and 'confirm' parameters are set, and if 'confirm' is 'true'
if (isset($requestId, $requestConfirm) && $requestConfirm == 'true') {
    // Use the request ID (already extracted above)
    $id = $requestId;

    // Start a transaction
    mysqli_begin_transaction($con);

    // Fetch the logged-in user's ID and role from the session
    $currentUserId = $_SESSION['user_id']; // Assuming user ID is stored in session
    $userRole = $_SESSION['role']; // Assuming user role is stored in session

    // Fetch the cage record to check for user assignment
    $cageQuery = "SELECT c.pi_name, cu.user_id FROM cages c LEFT JOIN cage_users cu ON c.cage_id = cu.cage_id WHERE c.cage_id = ?";
    if ($stmt = mysqli_prepare($con, $cageQuery)) {
        mysqli_stmt_bind_param($stmt, "s", $id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $cage = mysqli_fetch_assoc($result);
        mysqli_stmt_close($stmt);

        if (!$cage) {
            $_SESSION['message'] = 'Cage not found.';
            header("Location: bc_dash.php");
            exit();
        }

        $cageUsers = [];
        do {
            if ($cage['user_id']) {
                $cageUsers[] = $cage['user_id'];
            }
        } while ($cage = mysqli_fetch_assoc($result));
    } else {
        $_SESSION['message'] = 'Error retrieving cage data.';
        header("Location: bc_dash.php");
        exit();
    }

    // Check if the user is either an admin or assigned to the cage
    if ($userRole !== 'admin' && !in_array($currentUserId, $cageUsers)) {
        $_SESSION['message'] = 'Access denied. Only the assigned user or an admin can perform this action.';
        header("Location: bc_dash.php");
        exit();
    }

    try {
        if ($action === 'permanent_delete') {
            // Permanently delete records from all related tables
            $tables = [
                'breeding' => 'cage_id',
                'litters' => 'cage_id',
                'files' => 'cage_id',
                'notes' => 'cage_id',
                'cage_iacuc' => 'cage_id',
                'cage_users' => 'cage_id',
                'tasks' => 'cage_id',
                'maintenance' => 'cage_id',
                'reminders' => 'cage_id',
                'cages' => 'cage_id'
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
    header("Location: bc_dash.php");
    exit();
} else {
    // Set an error message if action is not confirmed or ID is missing
    $_SESSION['message'] = 'Action was not confirmed or ID parameter is missing.';
    // Redirect to the dashboard page
    header("Location: bc_dash.php");
    exit();
}
