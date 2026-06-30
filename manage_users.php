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

// Include the role capability matrix (valid roles + labels)
require_once 'services/roles.php';

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

/**
 * How many cages a user owns (PI) and is assigned to. Used to warn an admin
 * before deleting a user that those cages will be left orphaned (no PI, and
 * admin-only until reassigned).
 */
function user_cage_impact(mysqli $con, string $username): array
{
    $stmt = $con->prepare("
        SELECT (SELECT COUNT(*) FROM cages c       WHERE c.pi_name  = u.id) AS owned,
               (SELECT COUNT(*) FROM cage_users cu WHERE cu.user_id = u.id) AS assigned
          FROM users u WHERE u.username = ?");
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return ['owned' => (int)($r['owned'] ?? 0), 'assigned' => (int)($r['assigned'] ?? 0)];
}

// Handle POST requests for user status and role updates
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die('CSRF token validation failed');
    }

    $username = trim($_POST['username'] ?? '');
    $action = trim($_POST['action'] ?? '');

    // Every role change other than "promote to admin" is a downgrade away from
    // admin. Built from the role matrix so new roles are covered automatically.
    $downgradeActions = array_merge(
        ['delete', 'pending'],
        array_values(array_diff(roles_all(), [ROLE_ADMIN]))
    );

    // Block actions that an admin should never be able to run against themselves.
    // (Deleting yourself or downgrading yourself can lock the system out.)
    if ($username === ($_SESSION['username'] ?? '') && in_array($action, $downgradeActions, true)) {
        $_SESSION['message'] = 'You cannot perform that action on your own account.';
        header('Location: manage_users.php');
        exit;
    }

    // Last-admin protection: refuse any change that would remove the final
    // active admin (delete, pending, or role downgrade away from admin).
    $wouldRemoveAdmin = in_array($action, $downgradeActions, true);
    if ($wouldRemoveAdmin) {
        $roleStmt = $con->prepare("SELECT role, status FROM users WHERE username = ?");
        $roleStmt->bind_param('s', $username);
        $roleStmt->execute();
        $targetRow = $roleStmt->get_result()->fetch_assoc();
        $roleStmt->close();

        if ($targetRow && $targetRow['role'] === 'admin' && $targetRow['status'] === 'approved') {
            $countStmt = $con->prepare("SELECT COUNT(*) AS n FROM users WHERE role='admin' AND status='approved'");
            $countStmt->execute();
            $adminCount = (int) ($countStmt->get_result()->fetch_assoc()['n'] ?? 0);
            $countStmt->close();
            if ($adminCount <= 1) {
                $_SESSION['message'] = 'Refused: this would leave the system with no active admin.';
                header('Location: manage_users.php');
                exit;
            }
        }
    }

    // Orphan-warning backstop for deletes: if the target owns or is assigned to
    // cages, require an explicit confirmation (the JS confirm sets confirm_orphan
    // when the admin accepts the warning). Protects against a direct POST too.
    $deleteImpact = null;
    if ($action === 'delete') {
        $deleteImpact = user_cage_impact($con, $username);
        if (($_POST['confirm_orphan'] ?? '') !== '1'
            && ($deleteImpact['owned'] + $deleteImpact['assigned']) > 0) {
            $_SESSION['message'] = sprintf(
                'Delete not confirmed: "%s" owns %d cage(s) and is assigned to %d. '
                . 'Deleting would leave those cages without a PI. Click Delete again and confirm the warning to proceed.',
                $username, $deleteImpact['owned'], $deleteImpact['assigned']);
            header('Location: manage_users.php');
            exit;
        }
    }

    // Initialize query variables. A role change is any action that names a
    // valid role (admin, vivarium_manager, veterinarian, iacuc_member, user).
    $query = "";
    $isRoleChange = role_is_valid($action);

    // Determine the action to take: approve, set to pending, delete user, or
    // change role.
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
        default:
            if ($isRoleChange) {
                $query = "UPDATE users SET role=? WHERE username=?";
            } else {
                die('Invalid action');
            }
    }

    // Execute the prepared statement if a valid action is set
    if (!empty($query)) {
        $statement = mysqli_prepare($con, $query);
        if ($statement) {
            // Role changes bind (role, username); everything else binds username.
            if ($isRoleChange) {
                mysqli_stmt_bind_param($statement, "ss", $action, $username);
            } else {
                mysqli_stmt_bind_param($statement, "s", $username);
            }
            mysqli_stmt_execute($statement);
            mysqli_stmt_close($statement);

            // Log role changes
            if ($isRoleChange) {
                log_activity($con, 'role_change', 'user', $username, "Changed role to " . role_label($action));
            }

            // After a delete that orphaned cages, tell the admin so they can
            // reassign a PI. (Reminders/maintenance the user created are kept
            // but now show as "Unknown".)
            if ($action === 'delete') {
                log_activity($con, 'delete', 'user', $username, 'Admin deleted user');
                if ($deleteImpact && $deleteImpact['owned'] > 0) {
                    $_SESSION['message'] = sprintf(
                        'User "%s" deleted. %d cage(s) are now without a PI — reassign an owner on the cage pages.',
                        $username, $deleteImpact['owned']);
                }
            }
        } else {
            // Log error and handle it gracefully
            error_log("Database error: " . mysqli_error($con));
            die('Database error');
        }
    }
}

// Pagination settings
$allowed_per_page = [10, 25, 50];
$records_per_page = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 10;
if (!in_array($records_per_page, $allowed_per_page)) {
    $records_per_page = 10;
}
$current_page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($current_page - 1) * $records_per_page;

// Search parameter
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Build query with optional search
if (!empty($search)) {
    $search_pattern = '%' . $search . '%';

    // Also match the friendly role label so that searching the displayed text
    // (e.g. "IACUC Member") finds rows stored under the slug "iacuc_member".
    $roleSlugMatches = [];
    foreach (roles_all() as $slug) {
        if (stripos(role_label($slug), $search) !== false) {
            $roleSlugMatches[] = $slug;
        }
    }
    $roleInClause = $roleSlugMatches
        ? ' OR role IN (' . implode(',', array_fill(0, count($roleSlugMatches), '?')) . ')'
        : '';
    $whereSql   = "name LIKE ? OR username LIKE ? OR role LIKE ? OR status LIKE ?" . $roleInClause;
    $baseTypes  = 'ssss' . str_repeat('s', count($roleSlugMatches));
    $baseParams = array_merge(
        [$search_pattern, $search_pattern, $search_pattern, $search_pattern],
        $roleSlugMatches
    );

    $count_stmt = $con->prepare("SELECT COUNT(*) as total FROM users WHERE $whereSql");
    $count_stmt->bind_param($baseTypes, ...$baseParams);
    $count_stmt->execute();
    $total_records = $count_stmt->get_result()->fetch_assoc()['total'];
    $count_stmt->close();

    $user_stmt = $con->prepare("SELECT *,
            (SELECT COUNT(*) FROM cages c       WHERE c.pi_name  = users.id) AS owned_cages,
            (SELECT COUNT(*) FROM cage_users cu WHERE cu.user_id = users.id) AS assigned_cages
        FROM users WHERE $whereSql ORDER BY name ASC LIMIT ? OFFSET ?");
    $user_stmt->bind_param($baseTypes . 'ii', ...array_merge($baseParams, [$records_per_page, $offset]));
    $user_stmt->execute();
    $userresult = $user_stmt->get_result();
    $user_stmt->close();
} else {
    $total_records = mysqli_query($con, "SELECT COUNT(*) as total FROM users")->fetch_assoc()['total'];
    $user_stmt = $con->prepare("SELECT *,
            (SELECT COUNT(*) FROM cages c       WHERE c.pi_name  = users.id) AS owned_cages,
            (SELECT COUNT(*) FROM cage_users cu WHERE cu.user_id = users.id) AS assigned_cages
        FROM users ORDER BY name ASC LIMIT ? OFFSET ?");
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
        'per_page' => $_GET['per_page'] ?? 10,
        'page' => $_GET['page'] ?? 1,
    ];
    $params = array_merge($params, $overrides);
    return http_build_query($params);
}

// Icon + colour for each role's "Make <role>" action button. Keyed by role so
// the row below can simply render a button for every role except the current one.
$roleButtonMeta = [
    ROLE_ADMIN            => ['icon' => 'fa-user-shield',     'btn' => 'btn-warning'],
    ROLE_VIVARIUM_MANAGER => ['icon' => 'fa-flask',           'btn' => 'btn-info'],
    ROLE_VETERINARIAN     => ['icon' => 'fa-user-md',         'btn' => 'btn-info'],
    ROLE_IACUC_MEMBER     => ['icon' => 'fa-clipboard-check', 'btn' => 'btn-info'],
    ROLE_USER             => ['icon' => 'fa-user',            'btn' => 'btn-secondary'],
];

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

        /* Mobile table card layout handled by unified styles in header.php */
    </style>

    <script>
        var currentAdminUsername = "<?php echo htmlspecialchars($_SESSION['username']); ?>";

        function confirmAdminAction(username) {
            if (username === currentAdminUsername) {
                return confirm("Are you sure you want to change settings for your own account?");
            }
            return true;
        }

        // Delete confirmation. Warns when the user owns or is assigned to cages,
        // and arms the server-side confirm_orphan backstop when accepted.
        function confirmDeleteUser(form, owned, assigned, username) {
            var msg;
            if (owned > 0 || assigned > 0) {
                msg = 'WARNING: "' + username + '" owns ' + owned + ' cage(s) and is assigned to ' + assigned + ' cage(s).\n\n'
                    + 'Deleting will leave owned cages WITHOUT a PI (admin-only until you reassign one) and remove their cage assignments. '
                    + 'Reminders and maintenance logs they created are kept but will show as "Unknown".\n\nDelete anyway?';
            } else {
                msg = 'Delete user "' + username + '"? This cannot be undone.';
            }
            if (!confirm(msg)) return false;
            if (form.confirm_orphan) form.confirm_orphan.value = '1';
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
                    <div class="d-flex align-items-center gap-2">
                        <label for="per_page" class="form-label mb-0 text-nowrap">Show</label>
                        <select class="form-select form-select-sm" id="per_page" name="per_page" style="width: auto;" onchange="this.form.submit()">
                            <?php foreach ($allowed_per_page as $pp): ?>
                                <option value="<?= $pp; ?>" <?= $records_per_page == $pp ? 'selected' : ''; ?>><?= $pp; ?></option>
                            <?php endforeach; ?>
                        </select>
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
                                <td data-label="Role"><?php echo htmlspecialchars(role_label($row['role'])); ?></td>
                                <td data-label="Actions">
                                    <form action="manage_users.php" method="post" class="action-buttons" onsubmit="return confirmAdminAction('<?php echo htmlspecialchars($row['username']); ?>')">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                        <input type="hidden" name="name" value="<?php echo htmlspecialchars($row['name']); ?>">
                                        <input type="hidden" name="username" value="<?php echo htmlspecialchars($row['username']); ?>">
                                        <input type="hidden" name="confirm_orphan" value="">

                                        <?php if ($row['status'] === 'pending') { ?>
                                            <button type="submit" class="btn btn-success btn-sm" name="action" value="approve" title="Approve User"><i class="fas fa-check"></i></button>
                                        <?php } elseif ($row['status'] === 'approved') { ?>
                                            <button type="submit" class="btn btn-secondary btn-sm" name="action" value="pending" title="Deactivate User"><i class="fas fa-ban"></i></button>
                                        <?php } ?>

                                        <?php foreach ($roleButtonMeta as $roleKey => $roleMeta):
                                            if ($row['role'] === $roleKey) continue; ?>
                                            <button type="submit" class="btn <?= $roleMeta['btn']; ?> btn-sm" name="action" value="<?= htmlspecialchars($roleKey); ?>" title="Make <?= htmlspecialchars(role_label($roleKey)); ?>"><i class="fas <?= $roleMeta['icon']; ?>"></i></button>
                                        <?php endforeach; ?>

                                        <button type="submit" class="btn btn-danger btn-sm" name="action" value="delete" title="Delete User" onclick="return confirmDeleteUser(this.form, <?php echo (int)$row['owned_cages']; ?>, <?php echo (int)$row['assigned_cages']; ?>, '<?php echo htmlspecialchars(addslashes($row['username']), ENT_QUOTES); ?>')"><i class="fas fa-trash"></i></button>
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