<?php

/**
 * Edit Note Script
 *
 * Handles updating a note in the database. Expects a POST request with the
 * note ID, the updated note text, and a CSRF token. Only the user who created
 * the note (or an admin) may edit it.
 *
 */

require 'session_config.php';
require 'dbcon.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

if (!isset($_SESSION['username']) || !isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'You must be logged in to edit a note.']);
    exit;
}

if (!isset($_POST['csrf_token']) || !isset($_SESSION['csrf_token'])
    || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token.']);
    exit;
}

$noteId   = isset($_POST['note_id']) ? (int) $_POST['note_id'] : 0;
$noteText = isset($_POST['note_text']) ? (string) $_POST['note_text'] : '';
$userId   = (int) $_SESSION['user_id'];
$isAdmin  = (($_SESSION['role'] ?? '') === 'admin');

if ($noteId <= 0 || $noteText === '') {
    echo json_encode(['success' => false, 'message' => 'Invalid request.']);
    exit;
}

if ($isAdmin) {
    $sql = "UPDATE notes SET note_text = ? WHERE id = ?";
    $stmt = $con->prepare($sql);
    $stmt->bind_param('si', $noteText, $noteId);
} else {
    $sql = "UPDATE notes SET note_text = ? WHERE id = ? AND user_id = ?";
    $stmt = $con->prepare($sql);
    $stmt->bind_param('sii', $noteText, $noteId, $userId);
}

if ($stmt->execute() && $stmt->affected_rows > 0) {
    echo json_encode(['success' => true, 'message' => 'Note updated successfully.']);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to update note.']);
}

$stmt->close();
$con->close();
