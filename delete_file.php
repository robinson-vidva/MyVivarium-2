<?php

/**
 * File Deletion Script
 *
 * This script handles the deletion of a file from the server and the database based on the file ID.
 * The deletion itself requires a POST request with a valid CSRF token; a GET request with an id
 * renders a small confirmation form that performs that POST.
 *
 */

// Start a new session or resume the existing session
require 'session_config.php';

// Include the database connection file
require 'dbcon.php';

// Include the role capability matrix
require_once 'services/roles.php';

// Check if the user is not logged in, redirect them to index.php with the current URL for redirection after login
if (!isset($_SESSION['username'])) {
    header("Location: index.php");
    exit; // Exit to ensure no further code is executed
}

// Generate CSRF token if not already set
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Validate and sanitize the redirect page parameter (whitelist only)
$allowedPages = ['bc_edit', 'hc_edit', 'bc_view', 'hc_view', 'bc_dash', 'hc_dash'];
$redirect = $_POST['url'] ?? $_GET['url'] ?? 'bc_edit';
if (!in_array($redirect, $allowedPages, true)) {
    $redirect = 'bc_edit';
}

// A plain GET link (e.g. from bc_edit.php) only gets a confirmation form that
// POSTs back here with the CSRF token; it never deletes anything itself.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $linkId = isset($_GET['id']) ? intval($_GET['id']) : 0;
    if ($linkId <= 0) {
        echo "File ID not specified.";
        exit;
    }
    ?>
    <!doctype html>
    <html lang="en"><head><meta charset="utf-8"><title>Confirm file deletion</title></head>
    <body>
        <form method="post" action="delete_file.php">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']); ?>">
            <input type="hidden" name="id" value="<?= $linkId; ?>">
            <input type="hidden" name="url" value="<?= htmlspecialchars($redirect); ?>">
            <p>Are you sure you want to delete this file?</p>
            <button type="submit">Delete file</button>
            <a href="javascript:history.back()">Cancel</a>
        </form>
    </body></html>
    <?php
    exit;
}

if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
    $_SESSION['message'] = 'CSRF token validation failed.';
    header("Location: index.php");
    exit();
}

// Check if the 'id' parameter is set in the POST request
if (isset($_POST['id'])) {
    $id = intval($_POST['id']); // Get the file ID from the POST request

    // SQL query to select the file record from the database
    $fileQuery = "SELECT * FROM files WHERE id = ?";

    // Prepare the statement to prevent SQL injection
    $stmt = $con->prepare($fileQuery);
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $file = $result->fetch_assoc();

    // Check if a file record was found
    if ($file) {
        // Get the cage ID from the file record
        $cage_id = $file['cage_id'];

        // SECURITY: Authorization check - verify user has permission to delete this file
        // Only users assigned to the cage or admins can delete files
        $currentUserId = $_SESSION['user_id'];
        $userRole = $_SESSION['role'];

        // Fetch users assigned to this cage
        $cageUsersQuery = "SELECT user_id FROM cage_users WHERE cage_id = ?";
        $stmtCageUsers = $con->prepare($cageUsersQuery);
        $stmtCageUsers->bind_param("s", $cage_id);
        $stmtCageUsers->execute();
        $cageUsersResult = $stmtCageUsers->get_result();
        $cageUsers = array_column($cageUsersResult->fetch_all(MYSQLI_ASSOC), 'user_id');
        $stmtCageUsers->close();

        // View-only roles (vivarium_manager, iacuc_member) cannot delete files,
        // even when assigned to the cage.
        if (!role_can_write($userRole)) {
            $_SESSION['message'] = 'Access denied. Your role has view-only access and cannot delete files.';
            header("Location: index.php");
            exit();
        }

        // Check if user is authorized (admin or assigned to cage)
        if ($userRole !== 'admin' && !in_array($currentUserId, $cageUsers)) {
            $_SESSION['message'] = 'Access denied. Only assigned users or admins can delete files.';
            header("Location: index.php");
            exit();
        }

        // Delete the file from the server, only if it resolves inside uploads/.
        $uploadsRoot = realpath(__DIR__ . '/uploads');
        $candidate = (strpos($file['file_path'], '/') === 0) ? $file['file_path'] : __DIR__ . '/' . $file['file_path'];
        $realPath = realpath($candidate);
        if ($uploadsRoot && $realPath && is_file($realPath)
            && strpos($realPath, $uploadsRoot . DIRECTORY_SEPARATOR) === 0) {
            unlink($realPath);
        }

        // SQL query to delete the file record from the database
        $deleteQuery = "DELETE FROM files WHERE id = ?";

        // Prepare the statement to prevent SQL injection
        $deleteStmt = $con->prepare($deleteQuery);
        $deleteStmt->bind_param("i", $id);
        $deleteStmt->execute();

        // Defensive: strip control characters before composing the Location
        // header. urlencode() already escapes them, but stripping leaves the
        // redirect target clean for legitimate use too.
        $safeCageId = preg_replace('/[\r\n\0]/', '', (string) $cage_id);

        // Build safe redirect URL
        $url = $redirect . ".php?id=" . urlencode($safeCageId);

        // Redirect to the validated URL
        header("Location: $url");
        exit();
    } else {
        // Handle the case where the file record was not found
        echo "File record not found.";
    }
} else {
    // Handle the case where the 'id' parameter is not set in the POST request
    echo "File ID not specified.";
}
