<?php

/**
 * Breeding Cage Pagination and Search Script
 *
 * This script handles the pagination and search functionality for breeding cages.
 * It starts a session, includes the database connection, handles search filters,
 * fetches cage data with pagination, and generates HTML for table rows and pagination links.
 * The generated HTML is returned as a JSON response.
 *
 */

// Start a new session or resume the existing session
require 'session_config.php';

// Disable error display in production (errors logged to server logs)
error_reporting(E_ALL);
ini_set('display_errors', 0);

// Include the database connection
require 'dbcon.php';

// Start output buffering
ob_start();

// Check if the user is not logged in, redirect them to index.php with the current URL for redirection after login
if (!isset($_SESSION['username'])) {
    header("Location: index.php");
    exit; // Exit to ensure no further code is executed
}

// Fetch user role and ID from session
$userRole = $_SESSION['role'];
$currentUserId = $_SESSION['user_id'];

// Validate and set dynamic limit (page size)
$allowedLimits = [10, 20, 30, 50];
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
if (!in_array($limit, $allowedLimits)) {
    $limit = 10; // Default to 10 if invalid value
}

// Validate and set sort direction
$sort = isset($_GET['sort']) && strtolower($_GET['sort']) === 'desc' ? 'DESC' : 'ASC';

// Determine whether to show archived cages
$showArchived = isset($_GET['show_archived']) && $_GET['show_archived'] === '1';
$cageStatus = $showArchived ? 'archived' : 'active';

$page = isset($_GET['page']) ? (int)$_GET['page'] : 1; // Current page number, default to 1
$offset = ($page - 1) * $limit; // Offset for the SQL query

// Handle the search filter
$searchQuery = '';
if (isset($_GET['search'])) {
    $searchQuery = $_GET['search']; // PHP auto-decodes GET parameters; prepared statements handle escaping
}

// Fetch the distinct cage IDs with pagination using prepared statements
// JOIN with cages table to filter by status
if (!empty($searchQuery)) {
    $searchPattern = '%' . $searchQuery . '%';
    // Query with search filter
    $totalQuery = "SELECT DISTINCT b.`cage_id` FROM breeding b INNER JOIN cages c ON b.cage_id = c.cage_id WHERE c.status = ? AND b.`cage_id` LIKE ?";
    $stmtTotal = $con->prepare($totalQuery);
    $stmtTotal->bind_param("ss", $cageStatus, $searchPattern);
    $stmtTotal->execute();
    $totalResult = $stmtTotal->get_result();
    $totalRecords = $totalResult->num_rows;
    $totalPages = ceil($totalRecords / $limit);
    $stmtTotal->close();

    // Query with pagination and sort
    $query = "SELECT DISTINCT b.`cage_id` FROM breeding b INNER JOIN cages c ON b.cage_id = c.cage_id WHERE c.status = ? AND b.`cage_id` LIKE ? ORDER BY b.`cage_id` $sort LIMIT ? OFFSET ?";
    $stmt = $con->prepare($query);
    $stmt->bind_param("ssii", $cageStatus, $searchPattern, $limit, $offset);
    $stmt->execute();
    $result = $stmt->get_result();
} else {
    // Query without search filter
    $totalQuery = "SELECT DISTINCT b.`cage_id` FROM breeding b INNER JOIN cages c ON b.cage_id = c.cage_id WHERE c.status = ?";
    $stmtTotal = $con->prepare($totalQuery);
    $stmtTotal->bind_param("s", $cageStatus);
    $stmtTotal->execute();
    $totalResult = $stmtTotal->get_result();
    $totalRecords = $totalResult->num_rows;
    $totalPages = ceil($totalRecords / $limit);
    $stmtTotal->close();

    $query = "SELECT DISTINCT b.`cage_id` FROM breeding b INNER JOIN cages c ON b.cage_id = c.cage_id WHERE c.status = ? ORDER BY b.`cage_id` $sort LIMIT ? OFFSET ?";
    $stmt = $con->prepare($query);
    $stmt->bind_param("sii", $cageStatus, $limit, $offset);
    $stmt->execute();
    $result = $stmt->get_result();
}

// Generate the table rows
$tableRows = '';
while ($row = mysqli_fetch_assoc($result)) {
    $cageID = $row['cage_id']; // Get the cage ID
    // Use prepared statement to fetch records for the current cage ID
    $cageQuery = "SELECT * FROM breeding WHERE `cage_id` = ?";
    $stmtCage = $con->prepare($cageQuery);
    $stmtCage->bind_param("s", $cageID);
    $stmtCage->execute();
    $cageResult = $stmtCage->get_result();
    $numRows = $cageResult->num_rows; // Get the number of rows for the cage ID
    $firstRow = true; // Flag to check if it is the first row for the cage ID

    while ($breedingcage = mysqli_fetch_assoc($cageResult)) {
        $tableRows .= '<tr>';
        if ($firstRow) {
            $tableRows .= '<td>' . htmlspecialchars($breedingcage['cage_id']) . '</td>'; // Display cage ID only once per group
            $firstRow = false;
        }
        $tableRows .= '<td class="action-icons" style="white-space: nowrap;">
                        <a href="bc_view.php?id=' . rawurlencode($breedingcage['cage_id']) . '&page=' . $page . '&search=' . urlencode($searchQuery) . '" class="btn btn-primary btn-sm" data-bs-toggle="tooltip" data-bs-placement="top" title="View"><i class="fas fa-eye"></i></a>
                        <a href="manage_tasks.php?id=' . rawurlencode($breedingcage['cage_id']) . '&page=' . $page . '&search=' . urlencode($searchQuery) . '" class="btn btn-info btn-sm" data-bs-toggle="tooltip" data-bs-placement="top" title="Tasks"><i class="fas fa-tasks"></i></a>';

        // Check if the user is an admin or assigned to this cage via cage_users table
        $assignedCheck = $con->prepare("SELECT 1 FROM cage_users WHERE cage_id = ? AND user_id = ?");
        $assignedCheck->bind_param("si", $breedingcage['cage_id'], $currentUserId);
        $assignedCheck->execute();
        $isAssigned = $assignedCheck->get_result()->num_rows > 0;
        $assignedCheck->close();
        if ($userRole === 'admin' || $isAssigned) {
            if ($showArchived) {
                // Archived view: show Restore and Permanently Delete buttons
                $tableRows .= '<a href="#" onclick="confirmRestore(\'' . htmlspecialchars($breedingcage['cage_id']) . '\')" class="btn btn-success btn-sm" data-bs-toggle="tooltip" data-bs-placement="top" title="Restore"><i class="fas fa-undo"></i></a>
                               <a href="#" onclick="confirmPermanentDelete(\'' . htmlspecialchars($breedingcage['cage_id']) . '\')" class="btn btn-danger btn-sm" data-bs-toggle="tooltip" data-bs-placement="top" title="Delete Forever"><i class="fas fa-trash"></i></a>';
            } else {
                // Active view: show Edit and Archive buttons
                $tableRows .= '<a href="bc_edit.php?id=' . rawurlencode($breedingcage['cage_id']) . '&page=' . $page . '&search=' . urlencode($searchQuery) . '" class="btn btn-warning btn-sm" data-bs-toggle="tooltip" data-bs-placement="top" title="Edit"><i class="fas fa-edit"></i></a>
                               <a href="#" onclick="confirmDeletion(\'' . htmlspecialchars($breedingcage['cage_id']) . '\')" class="btn btn-danger btn-sm" data-bs-toggle="tooltip" data-bs-placement="top" title="Archive"><i class="fas fa-archive"></i></a>';
            }
        }
        $tableRows .= '</td></tr>';
    }
    $stmtCage->close();
}

// Generate the pagination links
$paginationLinks = '';
for ($i = 1; $i <= $totalPages; $i++) {
    $activeClass = ($i == $page) ? 'active' : ''; // Highlight the active page
    $paginationLinks .= '<li class="page-item ' . $activeClass . '"><a class="page-link" href="javascript:void(0);" onclick="fetchData(' . $i . ', \'' . htmlspecialchars($searchQuery, ENT_QUOTES) . '\')">' . $i . '</a></li>';
}

// Clear the output buffer to avoid sending unwanted output before JSON
ob_end_clean();

// Return the table rows and pagination links as a JSON response
header('Content-Type: application/json');
echo json_encode([
    'tableRows' => $tableRows,
    'paginationLinks' => $paginationLinks
]);

?>
