<?php

/**
 * Activity / Audit Log Viewer
 *
 * This page allows Admins and Vivarium Managers to view the system audit trail.
 * It displays a searchable, filterable, paginated table of all logged user actions.
 *
 * Access: Admin and Vivarium Manager roles only
 */

// Start session
require 'session_config.php';

// Include database connection
require 'dbcon.php';

// Disable error display in production
error_reporting(E_ALL);
ini_set('display_errors', 0);

// SECURITY: Check if user is logged in and has appropriate role
if (!isset($_SESSION['role']) ||
    ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'vivarium_manager')) {
    header("Location: index.php");
    exit;
}

// Pagination settings
$records_per_page = 50;
$current_page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($current_page - 1) * $records_per_page;

// Search and filter parameters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$entity_type_filter = isset($_GET['entity_type']) ? trim($_GET['entity_type']) : 'all';
$date_from = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
$date_to = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';

// Build WHERE clause with prepared statement parameters
$where_conditions = [];
$params = [];
$param_types = '';

// Search filter
if (!empty($search)) {
    $search_pattern = '%' . $search . '%';
    $where_conditions[] = "(u.name LIKE ? OR a.action LIKE ? OR a.entity_type LIKE ? OR a.entity_id LIKE ? OR a.details LIKE ?)";
    $params[] = $search_pattern;
    $params[] = $search_pattern;
    $params[] = $search_pattern;
    $params[] = $search_pattern;
    $params[] = $search_pattern;
    $param_types .= 'sssss';
}

// Entity type filter
if (!empty($entity_type_filter) && $entity_type_filter !== 'all') {
    $where_conditions[] = "a.entity_type = ?";
    $params[] = strtolower($entity_type_filter);
    $param_types .= 's';
}

// Date range filters
if (!empty($date_from)) {
    $where_conditions[] = "a.created_at >= ?";
    $params[] = $date_from . ' 00:00:00';
    $param_types .= 's';
}

if (!empty($date_to)) {
    $where_conditions[] = "a.created_at <= ?";
    $params[] = $date_to . ' 23:59:59';
    $param_types .= 's';
}

// Combine conditions
$where_clause = '';
if (!empty($where_conditions)) {
    $where_clause = "WHERE " . implode(" AND ", $where_conditions);
}

// Get total count for pagination
$count_query = "SELECT COUNT(*) as total
                FROM activity_log a
                LEFT JOIN users u ON a.user_id = u.id
                $where_clause";

if (!empty($params)) {
    $count_stmt = $con->prepare($count_query);
    $count_stmt->bind_param($param_types, ...$params);
    $count_stmt->execute();
    $count_result = $count_stmt->get_result();
    $total_records = $count_result->fetch_assoc()['total'];
    $count_stmt->close();
} else {
    $total_records = $con->query($count_query)->fetch_assoc()['total'];
}

$total_pages = ceil($total_records / $records_per_page);

// Fetch activity log entries with user information
$query = "SELECT a.id, a.user_id, a.action, a.entity_type, a.entity_id, a.details,
                 a.ip_address, a.created_at, u.name as user_name
          FROM activity_log a
          LEFT JOIN users u ON a.user_id = u.id
          $where_clause
          ORDER BY a.created_at DESC
          LIMIT ? OFFSET ?";

if (!empty($params)) {
    $stmt = $con->prepare($query);
    $params[] = $records_per_page;
    $params[] = $offset;
    $param_types .= 'ii';
    $stmt->bind_param($param_types, ...$params);
} else {
    $stmt = $con->prepare($query);
    $stmt->bind_param("ii", $records_per_page, $offset);
}

$stmt->execute();
$result = $stmt->get_result();
$log_entries = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Helper to build query string for pagination/filtering links
function buildQueryString($overrides = []) {
    $params = [
        'search' => $_GET['search'] ?? '',
        'entity_type' => $_GET['entity_type'] ?? 'all',
        'date_from' => $_GET['date_from'] ?? '',
        'date_to' => $_GET['date_to'] ?? '',
        'page' => $_GET['page'] ?? 1,
    ];
    $params = array_merge($params, $overrides);
    return http_build_query($params);
}

// Include header
require 'header.php';
?>

<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Activity Log | <?php echo htmlspecialchars($labName); ?></title>

    <style>
        body {
            margin: 0;
            padding: 0;
        }

        .content {
            min-height: 80vh;
        }

        .header-actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            margin-top: 20px;
            flex-wrap: wrap;
            gap: 10px;
        }

        .search-box {
            flex: 1;
            max-width: 400px;
        }

        .filter-row {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 15px;
            align-items: flex-end;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .filter-group label {
            font-weight: bold;
            color: var(--bs-secondary-color);
        }

        .timestamp {
            color: var(--bs-secondary-color);
        }

        .pagination-info {
            margin: 15px 0;
            color: var(--bs-secondary-color);
        }

        .table td {
            vertical-align: middle;
        }

        .table tbody tr {
            cursor: pointer;
        }

        .entity-label {
            display: inline;
        }

        /* Detail popup overlay */
        .detail-popup-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1050;
            justify-content: center;
            align-items: center;
        }

        .detail-popup-overlay.active {
            display: flex;
        }

        .detail-popup {
            background: var(--bs-body-bg);
            border: 1px solid var(--bs-border-color);
            border-radius: 8px;
            width: 90%;
            max-width: 550px;
            max-height: 80vh;
            overflow-y: auto;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
            position: relative;
        }

        .detail-popup-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px 20px;
            border-bottom: 1px solid var(--bs-border-color);
        }

        .detail-popup-header h5 {
            margin: 0;
        }

        .detail-popup-body {
            padding: 20px;
        }

        .detail-popup-body .details-table th {
            width: 35%;
            white-space: nowrap;
        }

        .detail-popup-body .details-table td {
            word-break: break-word;
        }

        @media print {
            .no-print {
                display: none !important;
            }

            .table {
                box-shadow: none;
            }

            body {
                font-size: 12pt;
            }

            .print-header {
                display: block !important;
                text-align: center;
                margin-bottom: 20px;
            }
        }

        .print-header {
            display: none;
        }

        @media (max-width: 768px) {
            .header-actions {
                flex-direction: column;
                align-items: stretch;
            }

            .search-box {
                max-width: 100%;
            }

            .filter-row {
                flex-direction: column;
            }

            .detail-popup {
                width: 95%;
                max-height: 90vh;
            }
        }
    </style>
</head>
<body>
    <div class="container mt-4 content" style="max-width: 900px;">
        <!-- Print Header (hidden on screen) -->
        <div class="print-header">
            <h2><?php echo htmlspecialchars($labName); ?></h2>
            <h3>Activity / Audit Log Report</h3>
            <p>Generated: <?php echo date('Y-m-d H:i:s'); ?></p>
            <?php if (!empty($search)): ?>
                <p>Search Filter: "<?php echo htmlspecialchars($search); ?>"</p>
            <?php endif; ?>
            <?php if ($entity_type_filter !== 'all'): ?>
                <p>Entity Type: <?php echo htmlspecialchars(ucfirst($entity_type_filter)); ?></p>
            <?php endif; ?>
            <?php if (!empty($date_from) || !empty($date_to)): ?>
                <p>Date Range: <?php echo htmlspecialchars($date_from ?: 'start'); ?> to <?php echo htmlspecialchars($date_to ?: 'present'); ?></p>
            <?php endif; ?>
            <hr>
        </div>

        <!-- Page Header -->
        <div class="no-print">
            <h1 class="text-center"><i class="fas fa-history"></i> Activity / Audit Log</h1>

            <!-- Search and Filters -->
            <form method="GET" action="">
                <div class="header-actions">
                    <div class="search-box">
                        <div class="input-group">
                            <input type="text"
                                   class="form-control"
                                   name="search"
                                   placeholder="Search user, action, entity, details..."
                                   value="<?php echo htmlspecialchars($search); ?>">
                            <button class="btn btn-primary" type="submit">
                                <i class="fas fa-search"></i> Search
                            </button>
                            <button type="button" class="btn btn-info" onclick="window.print()">
                                <i class="fas fa-print"></i>
                            </button>
                        </div>
                    </div>
                </div>

                <div class="filter-row">
                    <div class="filter-group">
                        <label for="entity_type">Entity Type</label>
                        <select class="form-select" id="entity_type" name="entity_type" onchange="this.form.submit()">
                            <option value="all" <?php echo $entity_type_filter === 'all' ? 'selected' : ''; ?>>All</option>
                            <option value="cage" <?php echo $entity_type_filter === 'cage' ? 'selected' : ''; ?>>Cage</option>
                            <option value="user" <?php echo $entity_type_filter === 'user' ? 'selected' : ''; ?>>User</option>
                            <option value="mouse" <?php echo $entity_type_filter === 'mouse' ? 'selected' : ''; ?>>Mouse</option>
                            <option value="maintenance" <?php echo $entity_type_filter === 'maintenance' ? 'selected' : ''; ?>>Maintenance</option>
                            <option value="system" <?php echo $entity_type_filter === 'system' ? 'selected' : ''; ?>>System</option>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label for="date_from">Date From</label>
                        <input type="date" class="form-control" id="date_from" name="date_from"
                               value="<?php echo htmlspecialchars($date_from); ?>">
                    </div>

                    <div class="filter-group">
                        <label for="date_to">Date To</label>
                        <input type="date" class="form-control" id="date_to" name="date_to"
                               value="<?php echo htmlspecialchars($date_to); ?>">
                    </div>

                    <div class="filter-group">
                        <label>&nbsp;</label>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-filter"></i> Apply Filters
                        </button>
                    </div>

                    <?php if (!empty($search) || $entity_type_filter !== 'all' || !empty($date_from) || !empty($date_to)): ?>
                        <div class="filter-group">
                            <label>&nbsp;</label>
                            <a href="activity_log.php" class="btn btn-secondary">
                                <i class="fas fa-times"></i> Clear All
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </form>

            <!-- Pagination Info -->
            <div class="pagination-info">
                <?php if ($total_records > 0): ?>
                    Showing <?php echo $offset + 1; ?> - <?php echo min($offset + $records_per_page, $total_records); ?>
                    of <?php echo $total_records; ?> total records
                <?php else: ?>
                    No records found
                <?php endif; ?>
            </div>
        </div>

        <!-- Activity Log Table -->
        <div class="table-responsive">
            <table class="table table-striped table-hover">
                <thead>
                    <tr>
                        <th>Date/Time</th>
                        <th>User</th>
                        <th>Action</th>
                        <th>Entity</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($log_entries)): ?>
                        <tr>
                            <td colspan="4" class="text-center py-4">
                                <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                                <p>No activity log entries found.</p>
                                <?php if (!empty($search) || $entity_type_filter !== 'all' || !empty($date_from) || !empty($date_to)): ?>
                                    <p>Try adjusting your search or filter criteria.</p>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($log_entries as $entry):
                            $action_class = 'bg-secondary';
                            switch ($entry['action']) {
                                case 'create': $action_class = 'bg-success'; break;
                                case 'edit': $action_class = 'bg-primary'; break;
                                case 'delete': $action_class = 'bg-danger'; break;
                                case 'archive': $action_class = 'bg-warning text-dark'; break;
                                case 'restore': $action_class = 'bg-info text-dark'; break;
                                case 'rename': $action_class = 'bg-primary'; break;
                                case 'role_change': $action_class = 'bg-dark'; break;
                            }
                        ?>
                            <tr onclick="showDetail(this)"
                                data-id="<?php echo htmlspecialchars($entry['id']); ?>"
                                data-date="<?php echo date('Y-m-d H:i:s', strtotime($entry['created_at'])); ?>"
                                data-user="<?php echo htmlspecialchars($entry['user_name'] ?? 'Unknown'); ?>"
                                data-action="<?php echo htmlspecialchars(ucfirst($entry['action'])); ?>"
                                data-action-class="<?php echo $action_class; ?>"
                                data-entity-type="<?php echo htmlspecialchars(ucfirst($entry['entity_type'])); ?>"
                                data-entity-id="<?php echo htmlspecialchars($entry['entity_id']); ?>"
                                data-details="<?php echo htmlspecialchars($entry['details'] ?? '—'); ?>"
                                data-ip="<?php echo htmlspecialchars($entry['ip_address'] ?? '—'); ?>">
                                <td class="timestamp">
                                    <?php echo date('Y-m-d', strtotime($entry['created_at'])); ?><br>
                                    <small><?php echo date('H:i:s', strtotime($entry['created_at'])); ?></small>
                                </td>
                                <td><?php echo htmlspecialchars($entry['user_name'] ?? 'Unknown'); ?></td>
                                <td>
                                    <span class="badge <?php echo $action_class; ?>">
                                        <?php echo htmlspecialchars(ucfirst($entry['action'])); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="entity-label"><?php echo htmlspecialchars(ucfirst($entry['entity_type'])); ?></span>
                                    <strong>#<?php echo htmlspecialchars($entry['entity_id']); ?></strong>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div><!-- End table-responsive -->

        <!-- Detail Popup -->
        <div class="detail-popup-overlay" id="detailPopup">
            <div class="detail-popup">
                <div class="detail-popup-header">
                    <h5><i class="fas fa-info-circle"></i> Log Entry Details</h5>
                    <button type="button" class="popup-close-btn" onclick="closeDetail()">&times;</button>
                </div>
                <div class="detail-popup-body">
                    <table class="details-table">
                        <tr>
                            <th>Log ID</th>
                            <td id="detailId"></td>
                        </tr>
                        <tr>
                            <th>Date / Time</th>
                            <td id="detailDate"></td>
                        </tr>
                        <tr>
                            <th>User</th>
                            <td id="detailUser"></td>
                        </tr>
                        <tr>
                            <th>Action</th>
                            <td id="detailAction"></td>
                        </tr>
                        <tr>
                            <th>Entity Type</th>
                            <td id="detailEntityType"></td>
                        </tr>
                        <tr>
                            <th>Entity ID</th>
                            <td id="detailEntityId"></td>
                        </tr>
                        <tr>
                            <th>Details</th>
                            <td id="detailDetails"></td>
                        </tr>
                        <tr>
                            <th>IP Address</th>
                            <td id="detailIp"></td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
            <nav aria-label="Page navigation" class="mt-4 no-print">
                <ul class="pagination justify-content-center">
                    <?php if ($current_page > 1): ?>
                        <li class="page-item">
                            <a class="page-link" href="?<?php echo buildQueryString(['page' => $current_page - 1]); ?>">
                                Previous
                            </a>
                        </li>
                    <?php endif; ?>

                    <?php for ($i = max(1, $current_page - 2); $i <= min($total_pages, $current_page + 2); $i++): ?>
                        <li class="page-item <?php echo $i == $current_page ? 'active' : ''; ?>">
                            <a class="page-link" href="?<?php echo buildQueryString(['page' => $i]); ?>">
                                <?php echo $i; ?>
                            </a>
                        </li>
                    <?php endfor; ?>

                    <?php if ($current_page < $total_pages): ?>
                        <li class="page-item">
                            <a class="page-link" href="?<?php echo buildQueryString(['page' => $current_page + 1]); ?>">
                                Next
                            </a>
                        </li>
                    <?php endif; ?>
                </ul>
            </nav>
        <?php endif; ?>
    </div>

    <?php include 'footer.php'; ?>

    <script>
    function showDetail(row) {
        document.getElementById('detailId').textContent = row.dataset.id;
        document.getElementById('detailDate').textContent = row.dataset.date;
        document.getElementById('detailUser').textContent = row.dataset.user;
        document.getElementById('detailEntityType').textContent = row.dataset.entityType;
        document.getElementById('detailEntityId').textContent = row.dataset.entityId;
        document.getElementById('detailDetails').textContent = row.dataset.details;
        document.getElementById('detailIp').textContent = row.dataset.ip;

        // Action badge
        var actionCell = document.getElementById('detailAction');
        actionCell.innerHTML = '<span class="badge ' + row.dataset.actionClass + '">' + row.dataset.action + '</span>';

        document.getElementById('detailPopup').classList.add('active');
    }

    function closeDetail() {
        document.getElementById('detailPopup').classList.remove('active');
    }

    // Close on overlay click
    document.getElementById('detailPopup').addEventListener('click', function(e) {
        if (e.target === this) closeDetail();
    });

    // Close on Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closeDetail();
    });
    </script>
</body>
</html>

<?php
$con->close();
?>
