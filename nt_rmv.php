<?php

/**
 * Delete Note Script
 *
 * Handles the deletion of a note. Only the user who created the note (or an
 * admin) may delete it. Requires POST + CSRF token.
 *
 */

require 'session_config.php';
require 'dbcon.php';

header('Content-Type: application/json');

$response = ['success' => false, 'message' => 'Invalid request.'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    $response['message'] = 'Method not allowed.';
    echo json_encode($response);
    exit;
}

if (!isset($_SESSION['username']) || !isset($_SESSION['user_id'])) {
    http_response_code(401);
    $response['message'] = 'You must be logged in to delete a note.';
    echo json_encode($response);
    exit;
}

if (!isset($_POST['csrf_token']) || !isset($_SESSION['csrf_token'])
    || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    http_response_code(403);
    $response['message'] = 'Invalid CSRF token.';
    echo json_encode($response);
    exit;
}

if (!isset($_POST['note_id'])) {
    echo json_encode($response);
    exit;
}

$note_id = (int) $_POST['note_id'];
$userId  = (int) $_SESSION['user_id'];
$isAdmin = (($_SESSION['role'] ?? '') === 'admin');

if ($note_id <= 0) {
    echo json_encode($response);
    exit;
}

if ($isAdmin) {
    $sql = "DELETE FROM notes WHERE id = ?";
    $stmt = $con->prepare($sql);
    $stmt->bind_param('i', $note_id);
} else {
    $sql = "DELETE FROM notes WHERE id = ? AND user_id = ?";
    $stmt = $con->prepare($sql);
    $stmt->bind_param('ii', $note_id, $userId);
}

if ($stmt === false) {
    $response['message'] = 'Database error.';
    error_log('nt_rmv prepare failed: ' . $con->error);
    echo json_encode($response);
    exit;
}

if ($stmt->execute()) {
    if ($stmt->affected_rows > 0) {
        $response['success'] = true;
        $response['message'] = 'Note deleted successfully.';
    } else {
        $response['message'] = 'Note not found or you do not have permission to delete it.';
    }
} else {
    $response['message'] = 'Failed to delete note.';
    error_log('nt_rmv execute failed: ' . $stmt->error);
}

$stmt->close();
$con->close();

echo json_encode($response);
