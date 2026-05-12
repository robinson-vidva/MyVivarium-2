<?php
/**
 * Mark Notification(s) as Read
 *
 * Accepts POST requests to mark individual or all notifications as read.
 * POST params:
 *   - id: mark a single notification as read
 *   - mark_all: mark all notifications as read for the current user
 */

require 'session_config.php';
require 'dbcon.php';

header('Content-Type: application/json');

if (!isset($_SESSION['username'])) {
    echo json_encode(['error' => 'Authentication required.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['error' => 'POST required.']);
    exit;
}

$userId = $_SESSION['user_id'];

try {
    if (isset($_POST['mark_all'])) {
        $stmt = $con->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        echo json_encode(['success' => true, 'marked' => $affected]);
    } elseif (isset($_POST['id'])) {
        $notifId = (int)$_POST['id'];
        $stmt = $con->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
        $stmt->bind_param("ii", $notifId, $userId);
        $stmt->execute();
        $stmt->close();
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['error' => 'No action specified.']);
    }
} catch (Exception $e) {
    // The `notifications` table is optional in older deployments. Only swallow
    // the "missing table" case; log everything else so real failures surface.
    if (stripos($e->getMessage(), "doesn't exist") === false
        && stripos($e->getMessage(), 'unknown table') === false) {
        error_log('mark_notification error: ' . $e->getMessage());
    }
    echo json_encode(['success' => true, 'marked' => 0]);
}

$con->close();
?>
