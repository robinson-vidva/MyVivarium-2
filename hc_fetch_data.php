<?php

/**
 * Holding Cage Pagination + Search (v2)
 *
 * Reads from `cages` directly (the v1 `holding` table is gone). The optional
 * strain/sex/age columns aggregate over the mice currently assigned to each
 * cage (status='alive', current_cage_id matches). A cage with no live mice
 * shows blank values for those columns rather than disappearing.
 */

require 'session_config.php';
error_reporting(E_ALL);
ini_set('display_errors', 0);
require 'dbcon.php';
ob_start();

if (!isset($_SESSION['username'])) {
    header("Location: index.php");
    exit;
}

$userRole = $_SESSION['role'] ?? '';
$currentUserId = $_SESSION['user_id'] ?? 0;

$allowedLimits = [10, 20, 30, 50];
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
if (!in_array($limit, $allowedLimits, true)) $limit = 10;

$sortParam = $_GET['sort'] ?? 'cage_id_asc';
$allowedSorts = [
    'cage_id_asc'     => 'c.cage_id ASC',
    'cage_id_desc'    => 'c.cage_id DESC',
    'created_at_desc' => 'c.created_at DESC',
    'created_at_asc'  => 'c.created_at ASC',
    'dob_desc'        => 'min_dob DESC',
    'dob_asc'         => 'min_dob ASC',
];
$orderBy = $allowedSorts[$sortParam] ?? $allowedSorts['cage_id_asc'];

$columnsParam   = $_GET['columns'] ?? 'strain,age';
$allowedColumns = ['strain', 'sex', 'age'];
$visibleColumns = array_intersect(explode(',', $columnsParam), $allowedColumns);

$showArchived = isset($_GET['show_archived']) && $_GET['show_archived'] === '1';
$cageStatus   = $showArchived ? 'archived' : 'active';

$page   = (int)($_GET['page'] ?? 1);
$offset = max(0, ($page - 1) * $limit);
$searchQuery = $_GET['search'] ?? '';

// Aggregate query: per-cage stats from live mice
$baseSql = "
    SELECT c.cage_id,
           c.created_at,
           (SELECT COUNT(*) FROM mice mi WHERE mi.current_cage_id = c.cage_id AND mi.status = 'alive') AS live_count,
           (SELECT MIN(mi.dob) FROM mice mi WHERE mi.current_cage_id = c.cage_id AND mi.status = 'alive') AS min_dob,
           (SELECT GROUP_CONCAT(DISTINCT mi.strain SEPARATOR ', ') FROM mice mi WHERE mi.current_cage_id = c.cage_id AND mi.status = 'alive' AND mi.strain IS NOT NULL) AS strain_ids,
           (SELECT GROUP_CONCAT(DISTINCT mi.sex SEPARATOR ', ') FROM mice mi WHERE mi.current_cage_id = c.cage_id AND mi.status = 'alive') AS sex_summary
      FROM cages c
     WHERE c.status = ?
";

$args  = [$cageStatus];
$types = 's';
$whereExtra = '';
if ($searchQuery !== '') {
    $like = '%' . $searchQuery . '%';
    // Search across cage_id and any live mouse's strain or sex
    $whereExtra = " AND (c.cage_id LIKE ?
        OR EXISTS (SELECT 1 FROM mice mi WHERE mi.current_cage_id = c.cage_id AND (mi.strain LIKE ? OR mi.sex LIKE ?))
    )";
    array_push($args, $like, $like, $like);
    $types .= 'sss';
}

// Total count
$countSql = "SELECT COUNT(*) AS n FROM cages c WHERE c.status = ?" . ($searchQuery !== '' ? "
    AND (c.cage_id LIKE ?
         OR EXISTS (SELECT 1 FROM mice mi WHERE mi.current_cage_id = c.cage_id AND (mi.strain LIKE ? OR mi.sex LIKE ?))
    )" : "");
$cs = $con->prepare($countSql);
$cs->bind_param($types, ...$args);
$cs->execute();
$totalRecords = (int)$cs->get_result()->fetch_assoc()['n'];
$cs->close();
$totalPages = max(1, (int)ceil($totalRecords / $limit));

$listSql = $baseSql . $whereExtra . " ORDER BY $orderBy LIMIT ? OFFSET ?";
$listArgs = $args;
$listTypes = $types . 'ii';
$listArgs[] = $limit;
$listArgs[] = $offset;
$stmt = $con->prepare($listSql);
$stmt->bind_param($listTypes, ...$listArgs);
$stmt->execute();
$result = $stmt->get_result();

$tableRows = '';
if ($totalRecords === 0 && $searchQuery !== '') {
    $colCount = 2 + count($visibleColumns);
    $tableRows = '<tr><td colspan="' . $colCount . '" class="text-center py-4">'
        . '<p class="text-muted mb-1"><i class="fas fa-search"></i> No cages found matching "<strong>' . htmlspecialchars($searchQuery) . '</strong>"</p>'
        . '</td></tr>';
}

while ($row = $result->fetch_assoc()) {
    $cageID = $row['cage_id'];
    $tableRows .= '<tr>';
    $tableRows .= '<td data-label="Cage ID">' . htmlspecialchars($cageID)
                . ' <small class="text-muted">(' . (int)$row['live_count'] . ' mice)</small></td>';

    if (in_array('strain', $visibleColumns, true)) {
        $tableRows .= '<td data-label="Strain">' . htmlspecialchars($row['strain_ids'] ?? '') . '</td>';
    }
    if (in_array('sex', $visibleColumns, true)) {
        $tableRows .= '<td data-label="Sex">' . htmlspecialchars($row['sex_summary'] ?? '') . '</td>';
    }
    if (in_array('age', $visibleColumns, true)) {
        $ageStr = '';
        if (!empty($row['min_dob'])) {
            $diff = (new DateTime($row['min_dob']))->diff(new DateTime());
            if ($diff->y > 0)      $ageStr = $diff->y . 'y ' . $diff->m . 'm';
            elseif ($diff->m > 0)  $ageStr = $diff->m . 'm ' . $diff->d . 'd';
            else                   $ageStr = $diff->d . 'd';
        }
        $tableRows .= '<td data-label="Age">' . htmlspecialchars($ageStr) . '</td>';
    }

    $tableRows .= '<td data-label="Action" class="action-icons">'
        . '<a href="hc_view.php?id=' . rawurlencode($cageID) . '&page=' . $page . '&search=' . urlencode($searchQuery) . '" class="btn btn-primary btn-sm" data-bs-toggle="tooltip" title="View"><i class="fas fa-eye"></i></a>'
        . '<a href="manage_tasks.php?id=' . rawurlencode($cageID) . '" class="btn btn-info btn-sm" data-bs-toggle="tooltip" title="Tasks"><i class="fas fa-tasks"></i></a>';

    $assignedCheck = $con->prepare("SELECT 1 FROM cage_users WHERE cage_id = ? AND user_id = ?");
    $assignedCheck->bind_param("si", $cageID, $currentUserId);
    $assignedCheck->execute();
    $isAssigned = $assignedCheck->get_result()->num_rows > 0;
    $assignedCheck->close();

    if ($userRole === 'admin' || $isAssigned) {
        if ($showArchived) {
            $tableRows .= '<a href="#" onclick="confirmRestore(\'' . htmlspecialchars($cageID, ENT_QUOTES) . '\')" class="btn btn-success btn-sm" data-bs-toggle="tooltip" title="Restore"><i class="fas fa-undo"></i></a>'
                        . '<a href="#" onclick="confirmPermanentDelete(\'' . htmlspecialchars($cageID, ENT_QUOTES) . '\')" class="btn btn-danger btn-sm" data-bs-toggle="tooltip" title="Delete Forever"><i class="fas fa-trash"></i></a>';
        } else {
            $tableRows .= '<a href="hc_edit.php?id=' . rawurlencode($cageID) . '" class="btn btn-warning btn-sm" data-bs-toggle="tooltip" title="Edit"><i class="fas fa-edit"></i></a>'
                        . '<a href="#" onclick="confirmDeletion(\'' . htmlspecialchars($cageID, ENT_QUOTES) . '\')" class="btn btn-danger btn-sm" data-bs-toggle="tooltip" title="Archive"><i class="fas fa-archive"></i></a>';
        }
    }
    $tableRows .= '</td></tr>';
}
$stmt->close();

$paginationLinks = '';
for ($i = 1; $i <= $totalPages; $i++) {
    $active = $i === $page ? 'active' : '';
    $paginationLinks .= '<li class="page-item ' . $active . '"><a class="page-link" href="javascript:void(0);" onclick="fetchData(' . $i . ', \'' . htmlspecialchars($searchQuery, ENT_QUOTES) . '\')">' . $i . '</a></li>';
}

ob_end_clean();
header('Content-Type: application/json');
echo json_encode([
    'tableRows'       => $tableRows,
    'paginationLinks' => $paginationLinks,
    'totalRecords'    => $totalRecords,
]);
