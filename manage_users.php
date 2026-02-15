<?php

/**
 * User Management Page
 * 
 * This script provides functionality for an admin to manage users, including approving, setting to pending, deleting users,
 * and changing user roles. It also includes CSRF protection and session security enhancements.
 * 
 */

// Start a new session or resume the existing session
require 'session_config.php';

// Include the database connection file
require 'dbcon.php';

// Include the activity log helper
require_once 'log_activity.php';

// Check if the user is logged in and has admin role
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    // Redirect non-admin users to the index page
    header("Location: index.php");
    exit; // Ensure no further code is executed
}

// CSRF token generation
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Handle POST requests for user status and role updates
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die('CSRF token validation failed');
    }

    $username = trim($_POST['username'] ?? '');
    $action = trim($_POST['action'] ?? '');

    // Initialize query variables
    $query = "";

    // Determine the action to take: approve, set to pending, delete user, set role to admin, vivarium_manager, or user
    switch ($action) {
        case 'approve':
            $query = "UPDATE users SET status='approved' WHERE username=?";
            break;
        case 'pending':
            $query = "UPDATE users SET status='pending' WHERE username=?";
            break;
        case 'delete':
            $query = "DELETE FROM users WHERE username=?";
            break;
        case 'admin':
            $query = "UPDATE users SET role='admin' WHERE username=?";
            break;
        case 'vivarium_manager':
            $query = "UPDATE users SET role='vivarium_manager' WHERE username=?";
            break;
        case 'user':
            $query = "UPDATE users SET role='user' WHERE username=?";
            break;
        default:
            die('Invalid action');
    }

    // Execute the prepared statement if a valid action is set
    if (!empty($query)) {
        $statement = mysqli_prepare($con, $query);
        if ($statement) {
            mysqli_stmt_bind_param($statement, "s", $username);
            mysqli_stmt_execute($statement);
            mysqli_stmt_close($statement);

            // Log role changes
            if (in_array($action, ['admin', 'vivarium_manager', 'user'])) {
                log_activity($con, 'role_change', 'user', $username, "Changed role to $action");
            }
        } else {
            // Log error and handle it gracefully
            error_log("Database error: " . mysqli_error($con));
            die('Database error');
        }
    }
}

// Pagination settings
$records_per_page = 10;
$current_page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($current_page - 1) * $records_per_page;

// Search parameter
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Build query with optional search
if (!empty($search)) {
    $search_pattern = '%' . $search . '%';
    $count_stmt = $con->prepare("SELECT COUNT(*) as total FROM users WHERE name LIKE ? OR username LIKE ? OR role LIKE ? OR status LIKE ?");
    $count_stmt->bind_param("ssss", $search_pattern, $search_pattern, $search_pattern, $search_pattern);
    $count_stmt->execute();
    $total_records = $count_stmt->get_result()->fetch_assoc()['total'];
    $count_stmt->close();

    $user_stmt = $con->prepare("SELECT * FROM users WHERE name LIKE ? OR username LIKE ? OR role LIKE ? OR status LIKE ? ORDER BY name ASC LIMIT ? OFFSET ?");
    $user_stmt->bind_param("ssssii", $search_pattern, $search_pattern, $search_pattern, $search_pattern, $records_per_page, $offset);
    $user_stmt->execute();
    $userresult = $user_stmt->get_result();
    $user_stmt->close();
} else {
    $total_records = mysqli_query($con, "SELECT COUNT(*) as total FROM users")->fetch_assoc()['total'];
    $user_stmt = $con->prepare("SELECT * FROM users ORDER BY name ASC LIMIT ? OFFSET ?");
    $user_stmt->bind_param("ii", $records_per_page, $offset);
    $user_stmt->execute();
    $userresult = $user_stmt->get_result();
    $user_stmt->close();
}
$total_pages = ceil($total_records / $records_per_page);

// Helper to build query string
function buildUserQueryString($overrides = []) {
    $params = [
        'search' => $_GET['search'] ?? '',
        'page' => $_GET['page'] ?? 1,
    ];
    $params = array_merge($params, $overrides);
    return http_build_query($params);
}

// Include the header file
require 'header.php';
mysqli_close($con);
?>

<!doctype html>
<html lang="en">

<head>
    <!-- Required meta tags -->
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>User Management | <?php echo htmlspecialchars($labName); ?></title>

    <!-- Bootstrap CSS -->
    <!-- Bootstrap 5.3 loaded via header.php -->

    <!-- Inline CSS for styling -->
    <style>
        body {
            margin: 0;
            padding: 0;
        }

        .main-content {
            justify-content: center;
            align-items: center;
        }

        /* Action button styles handled by unified styles in header.php */

        @media (max-width: 576px) {
            .table th,
            .table td {
                display: block;
                width: 100%;
            }

            .table thead {
                display: none;
            }

            .table tr {
                margin-bottom: 15px;
                display: block;
                border: 1px solid var(--bs-border-color);
                border-radius: 8px;
                padding: 10px;
            }

            .table td {
                border: none;
                padding: 6px 10px;
                position: relative;
                padding-left: 40%;
                text-align: left;
            }

            .table td::before {
                content: attr(data-label);
                font-weight: bold;
                text-transform: uppercase;
                position: absolute;
                left: 10px;
                width: 35%;
                white-space: nowrap;
                color: var(--bs-body-color);
            }

            .table td[data-label="Actions"] {
                padding-left: 10px;
            }

            .table td[data-label="Actions"]::before {
                display: none;
            }

            .table td[data-label="Actions"] .action-buttons {
                justify-content: flex-start;
            }
        }
    </style>

    <script>
        var currentAdminUsername = "<?php echo htmlspecialchars($_SESSION['username']); ?>";

        function confirmAdminAction(username) {
            if (username === currentAdminUsername) {
                return confirm("Are you sure you want to change settings for your own account?");
            }
            return true;
        }
    </script>
</head>

<body>
    <div class="container mt-4 content" style="max-width: 900px;">
        <div class="main-content">
            <h1 class="text-center">User Management</h1>

            <!-- Search Bar -->
            <form method="GET" action="">
                <div class="header-actions">
                    <div class="search-box">
                        <div class="input-group">
                            <input type="text" class="form-control" name="search"
                                   placeholder="Search name, username, role, status..."
                                   value="<?php echo htmlspecialchars($search); ?>">
                            <button class="btn btn-primary" type="submit">
                                <i class="fas fa-search"></i> Search
                            </button>
                        </div>
                    </div>
                    <?php if (!empty($search)): ?>
                        <a href="manage_users.php" class="btn btn-secondary"><i class="fas fa-times"></i> Clear</a>
                    <?php endif; ?>
                </div>
            </form>
            <div class="pagination-info">
                <?php if ($total_records > 0): ?>
                    Showing <?= $offset + 1; ?> - <?= min($offset + $records_per_page, $total_records); ?>
                    of <?= $total_records; ?> users
                <?php else: ?>
                    No users found
                <?php endif; ?>
            </div>
            <div class="table-responsive">
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Username</th>
                            <th>Status</th>
                            <th>Role</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($row = mysqli_fetch_assoc($userresult)) { ?>
                            <tr>
                                <td data-label="Name"><?php echo htmlspecialchars($row['name']); ?></td>
                                <td data-label="Username"><?php echo htmlspecialchars($row['username']); ?></td>
                                <td data-label="Status"><?php echo htmlspecialchars($row['status']); ?></td>
                                <td data-label="Role"><?php echo htmlspecialchars($row['role']); ?></td>
                                <td data-label="Actions">
                                    <form action="manage_users.php" method="post" class="action-buttons" onsubmit="return confirmAdminAction('<?php echo htmlspecialchars($row['username']); ?>')">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                        <input type="hidden" name="name" value="<?php echo htmlspecialchars($row['name']); ?>">
                                        <input type="hidden" name="username" value="<?php echo htmlspecialchars($row['username']); ?>">

                                        <?php if ($row['status'] === 'pending') { ?>
                                            <button type="submit" class="btn btn-success btn-sm" name="action" value="approve" title="Approve User"><i class="fas fa-check"></i></button>
                                        <?php } elseif ($row['status'] === 'approved') { ?>
                                            <button type="submit" class="btn btn-secondary btn-sm" name="action" value="pending" title="Deactivate User"><i class="fas fa-ban"></i></button>
                                        <?php } ?>

                                        <?php if ($row['role'] === 'user') { ?>
                                            <button type="submit" class="btn btn-warning btn-sm" name="action" value="admin" title="Make Admin"><i class="fas fa-user-shield"></i></button>
                                            <button type="submit" class="btn btn-info btn-sm" name="action" value="vivarium_manager" title="Make Vivarium Manager"><i class="fas fa-flask"></i></button>
                                        <?php } elseif ($row['role'] == 'admin') { ?>
                                            <button type="submit" class="btn btn-info btn-sm" name="action" value="user" title="Make User"><i class="fas fa-user"></i></button>
                                            <button type="submit" class="btn btn-info btn-sm" name="action" value="vivarium_manager" title="Make Vivarium Manager"><i class="fas fa-flask"></i></button>
                                        <?php } elseif ($row['role'] == 'vivarium_manager') { ?>
                                            <button type="submit" class="btn btn-warning btn-sm" name="action" value="admin" title="Make Admin"><i class="fas fa-user-shield"></i></button>
                                            <button type="submit" class="btn btn-secondary btn-sm" name="action" value="user" title="Make User"><i class="fas fa-user"></i></button>
                                        <?php } ?>

                                        <button type="submit" class="btn btn-danger btn-sm" name="action" value="delete" title="Delete User"><i class="fas fa-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                        <?php } ?>
                    </tbody>

                </table>
            </div>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
                <nav aria-label="User pagination" class="mt-3">
                    <ul class="pagination justify-content-center">
                        <?php if ($current_page > 1): ?>
                            <li class="page-item">
                                <a class="page-link" href="?<?= buildUserQueryString(['page' => $current_page - 1]); ?>">Previous</a>
                            </li>
                        <?php endif; ?>

                        <?php for ($i = max(1, $current_page - 2); $i <= min($total_pages, $current_page + 2); $i++): ?>
                            <li class="page-item <?= $i == $current_page ? 'active' : ''; ?>">
                                <a class="page-link" href="?<?= buildUserQueryString(['page' => $i]); ?>"><?= $i; ?></a>
                            </li>
                        <?php endfor; ?>

                        <?php if ($current_page < $total_pages): ?>
                            <li class="page-item">
                                <a class="page-link" href="?<?= buildUserQueryString(['page' => $current_page + 1]); ?>">Next</a>
                            </li>
                        <?php endif; ?>
                    </ul>
                </nav>
            <?php endif; ?>
        </div>
    </div>

    <!-- Include the footer file -->
    <?php include 'footer.php'; ?>
</body>

</html>