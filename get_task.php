<?php
/**
 * Task Detail Fetcher
 * 
 * This script fetches the details of a specific task from the database based on the provided task ID.
 * It first checks if the user is logged in, then retrieves the task details from the database, and finally
 * returns the task information as a JSON response.
 * 
 */

// Start the session to use session variables
require 'session_config.php';

// Include the database connection file
require 'dbcon.php';

// Check if the user is logged in
if (!isset($_SESSION['name'])) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Authentication required.']);
    exit;
}

// Initialize the response array
$response = [];

// Check if a valid task ID is provided via GET request
if (isset($_GET['id']) && is_numeric($_GET['id'])) {
    // Validate and sanitize the task ID
    $taskId = intval($_GET['id']);

    // Prepare the SQL statement to prevent SQL injection
    $stmt = $con->prepare("SELECT * FROM tasks WHERE id = ?");
    $stmt->bind_param("i", $taskId);

    // Execute the query and get the result
    if ($stmt->execute()) {
        $result = $stmt->get_result();

        // Check if a matching task is found
        if ($result->num_rows > 0) {
            // Fetch task data if a matching task is found
            $task = $result->fetch_assoc();

            // Resolve assigned_by name
            $assignedByName = $task['assigned_by'];
            $nameStmt = $con->prepare("SELECT name FROM users WHERE id = ?");
            $nameStmt->bind_param("i", $task['assigned_by']);
            $nameStmt->execute();
            $nameResult = $nameStmt->get_result();
            if ($nameRow = $nameResult->fetch_assoc()) {
                $assignedByName = $nameRow['name'];
            }
            $nameStmt->close();

            // Resolve assigned_to names
            $assignedToNames = [];
            $assignedToIds = array_filter(explode(',', $task['assigned_to']));
            foreach ($assignedToIds as $uid) {
                $uid = trim($uid);
                $nameStmt = $con->prepare("SELECT name FROM users WHERE id = ?");
                $nameStmt->bind_param("i", $uid);
                $nameStmt->execute();
                $nameResult = $nameStmt->get_result();
                if ($nameRow = $nameResult->fetch_assoc()) {
                    $assignedToNames[] = $nameRow['name'];
                }
                $nameStmt->close();
            }

            // Populate the response array with task data
            $response = [
                'id' => $task['id'],
                'title' => $task['title'],
                'description' => $task['description'],
                'assigned_by' => $task['assigned_by'],
                'assigned_by_name' => $assignedByName,
                'assigned_to' => $task['assigned_to'],
                'assigned_to_names' => implode(', ', $assignedToNames),
                'status' => $task['status'],
                'completion_date' => $task['completion_date'],
                'creation_date' => $task['creation_date'],
                'cage_id' => $task['cage_id']
            ];
        } else {
            // Set error response if no matching task is found
            $response = ['error' => 'Task not found.'];
        }

        // Close the statement
        $stmt->close();
    } else {
        // Set error response if the query execution fails
        $response = ['error' => 'Error executing query: ' . $stmt->error];
    }
} else {
    // Set error response if the task ID is invalid
    $response = ['error' => 'Invalid task ID.'];
}

// Output the response as JSON
header('Content-Type: application/json');
echo json_encode($response);
?>
