<?php
/**
 * Get Reminder
 * 
 * This script handles AJAX requests to fetch a single reminder's data based on its ID.
 */

session_start();
require 'dbcon.php';

// Check if the user is logged in
if (!isset($_SESSION['name'])) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Authentication required.']);
    exit;
}

header('Content-Type: application/json');

if (isset($_GET['id'])) {
    $id = intval($_GET['id']);
    $stmt = $con->prepare("SELECT * FROM reminders WHERE id = ?");
    $stmt->bind_param("i", $id);
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        $reminder = $result->fetch_assoc();
        if ($reminder) {
            // Return the reminder details as JSON
            echo json_encode($reminder);
        } else {
            echo json_encode(['error' => 'Reminder not found']);
        }
    } else {
        echo json_encode(['error' => 'Error executing query']);
    }
    $stmt->close();
} else {
    echo json_encode(['error' => 'Invalid request']);
}
?>
