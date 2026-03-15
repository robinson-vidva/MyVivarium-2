<?php
/**
 * Get Notifications API Endpoint
 *
 * Returns recent notifications and unread count for the logged-in user.
 * Used by the notification bell dropdown in header.php.
 */

require 'session_config.php';
require 'dbcon.php';

header('Content-Type: application/json');

if (!isset($_SESSION['username'])) {
    echo json_encode(['error' => 'Authentication required.']);
    exit;
}

$userId = $_SESSION['user_id'];

try {
    // Get unread count
    $countStmt = $con->prepare("SELECT COUNT(*) as cnt FROM notifications WHERE user_id = ? AND is_read = 0");
    $countStmt->bind_param("i", $userId);
    $countStmt->execute();
    $unreadCount = $countStmt->get_result()->fetch_assoc()['cnt'];
    $countStmt->close();

    // Get recent notifications (last 20)
    $stmt = $con->prepare("SELECT id, title, message, link, type, is_read, created_at FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 20");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();

    $notifications = [];
    while ($row = $result->fetch_assoc()) {
        $notifications[] = $row;
    }
    $stmt->close();

    echo json_encode([
        'unread_count' => (int)$unreadCount,
        'notifications' => $notifications
    ]);
} catch (Exception $e) {
    // Table may not exist yet - return empty state
    echo json_encode([
        'unread_count' => 0,
        'notifications' => []
    ]);
}

$con->close();
?>
