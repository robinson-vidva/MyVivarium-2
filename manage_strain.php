<?php

/**
 * Manage Strains
 * 
 * This script provides functionality for managing strains in a database. It allows users to add new strains,
 * edit existing strains, and delete strains. The interface includes a responsive popup form for data entry and 
 * a table for displaying existing strains. The script uses PHP sessions for message handling and includes basic 
 * input sanitization for security.
 * 
 */

require 'session_config.php'; // Start the session to use session variables
require 'dbcon.php'; // Include database connection

// Check if the user is logged in, redirect to login page if not
if (!isset($_SESSION['name'])) {
    header("Location: index.php");
    exit;
}

require 'header.php'; // Include the header for consistent page structure

// Generate CSRF token if not already set
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Initialize variables for strain data
$strainId = $strainName = $strainAka = $strainUrl = $strainRrid = $strainNotes = "";

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die('CSRF token validation failed');
    }

    if (isset($_POST['add'])) {
        // Add new strain - prepared statements handle SQL safety
        $strainId = trim($_POST['strain_id']);
        $strainName = trim($_POST['strain_name']);
        $strainAka = trim($_POST['strain_aka']);
        $strainUrl = trim($_POST['strain_url']);
        $strainRrid = trim($_POST['strain_rrid']);
        $strainNotes = trim($_POST['strain_notes']);

        // Check if strain ID already exists
        $checkStmt = $con->prepare("SELECT COUNT(*) FROM strains WHERE str_id = ?");
        $checkStmt->bind_param("s", $strainId);
        $checkStmt->execute();
        $checkStmt->bind_result($count);
        $checkStmt->fetch();
        $checkStmt->close();

        if ($count > 0) {
            $_SESSION['message'] = "Error: Strain ID already exists."; // Error message for duplicate ID
        } else {
            $stmt = $con->prepare("INSERT INTO strains (str_id, str_name, str_aka, str_url, str_rrid, str_notes) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("ssssss", $strainId, $strainName, $strainAka, $strainUrl, $strainRrid, $strainNotes);
            if ($stmt->execute()) {
                $_SESSION['message'] = "Strain added successfully."; // Success message
            } else {
                $_SESSION['message'] = "Error adding strain."; // Error message
            }
            $stmt->close(); // Close the statement
        }
    } elseif (isset($_POST['edit'])) {
        // Update existing strain - prepared statements handle SQL safety
        $strainId = trim($_POST['strain_id']);
        $strainName = trim($_POST['strain_name']);
        $strainAka = trim($_POST['strain_aka']);
        $strainUrl = trim($_POST['strain_url']);
        $strainRrid = trim($_POST['strain_rrid']);
        $strainNotes = trim($_POST['strain_notes']);
        $stmt = $con->prepare("UPDATE strains SET str_name = ?, str_aka = ?, str_url = ?, str_rrid = ?, str_notes = ? WHERE str_id = ?");
        $stmt->bind_param("ssssss", $strainName, $strainAka, $strainUrl, $strainRrid, $strainNotes, $strainId);
        if ($stmt->execute()) {
            $_SESSION['message'] = "Strain updated successfully."; // Success message
        } else {
            $_SESSION['message'] = "Error updating strain."; // Error message
        }
        $stmt->close(); // Close the statement
    } elseif (isset($_POST['delete'])) {
        // Delete strain - prepared statements handle SQL safety
        $strainId = trim($_POST['strain_id']);
        $stmt = $con->prepare("DELETE FROM strains WHERE str_id = ?");
        $stmt->bind_param("s", $strainId);
        if ($stmt->execute()) {
            $_SESSION['message'] = "Strain deleted successfully."; // Success message
        } else {
            $_SESSION['message'] = "Error deleting strain."; // Error message
        }
        $stmt->close(); // Close the statement
    }
}

// Pagination settings
$records_per_page = 10;
$current_page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($current_page - 1) * $records_per_page;

// Search parameter
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Get total count
if (!empty($search)) {
    $search_pattern = '%' . $search . '%';
    $count_stmt = $con->prepare("SELECT COUNT(*) as total FROM strains WHERE str_id LIKE ? OR str_name LIKE ? OR str_aka LIKE ? OR str_rrid LIKE ?");
    $count_stmt->bind_param("ssss", $search_pattern, $search_pattern, $search_pattern, $search_pattern);
    $count_stmt->execute();
    $total_records = $count_stmt->get_result()->fetch_assoc()['total'];
    $count_stmt->close();

    $strainStmt = $con->prepare("SELECT * FROM strains WHERE str_id LIKE ? OR str_name LIKE ? OR str_aka LIKE ? OR str_rrid LIKE ? ORDER BY str_id ASC LIMIT ? OFFSET ?");
    $strainStmt->bind_param("ssssii", $search_pattern, $search_pattern, $search_pattern, $search_pattern, $records_per_page, $offset);
    $strainStmt->execute();
    $strainResult = $strainStmt->get_result();
    $strainStmt->close();
} else {
    $total_records = $con->query("SELECT COUNT(*) as total FROM strains")->fetch_assoc()['total'];
    $strainStmt = $con->prepare("SELECT * FROM strains ORDER BY str_id ASC LIMIT ? OFFSET ?");
    $strainStmt->bind_param("ii", $records_per_page, $offset);
    $strainStmt->execute();
    $strainResult = $strainStmt->get_result();
    $strainStmt->close();
}
$total_pages = ceil($total_records / $records_per_page);

// Helper to build query string for pagination
function buildStrainQueryString($overrides = []) {
    $params = [
        'search' => $_GET['search'] ?? '',
        'page' => $_GET['page'] ?? 1,
    ];
    $params = array_merge($params, $overrides);
    return http_build_query($params);
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Strains</title>
    <!-- Bootstrap 5.3 loaded via header.php -->
    <!-- Font Awesome loaded via header.php -->
    <style>
        /* Popup Form Styles */
        .popup-form,
        .view-popup-form {
            display: none;
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background-color: var(--bs-body-bg);
            padding: 20px;
            border: 1px solid var(--bs-border-color);
            z-index: 1000;
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.2);
            border-radius: 10px;
            max-height: 90vh;
            overflow-y: auto;
            width: 80%;
            max-width: 800px;
        }

        .popup-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 999;
        }

        /* Form Layout */
        .form-buttons {
            display: flex;
            gap: 10px;
            justify-content: space-between;
        }

        /* Table column widths */
        .table {
            table-layout: auto;
        }

        /* ID column */
        .table th:nth-child(1),
        .table td:nth-child(1) {
            width: 80px;
            text-align: center;
            white-space: nowrap;
        }

        /* RRID column */
        .table th:nth-child(3),
        .table td:nth-child(3) {
            width: 160px;
            white-space: nowrap;
        }

        /* Actions column */
        .table th:nth-child(4),
        .table td:nth-child(4) {
            width: 160px;
        }

        /* Make delete form transparent to flex layout */
        .action-buttons form {
            display: contents;
        }

        .add-button {
            display: flex;
            justify-content: flex-end;
            margin-bottom: 20px;
        }

        .add-button .btn {
            margin-bottom: 20px;
        }

        .required-asterisk {
            color: red;
        }

        .extra-space {
            margin-bottom: 50px;
        }

        /* Responsive Styles */
        @media (max-width: 767px) {
            .form-buttons {
                flex-direction: column;
            }

            .form-buttons button {
                width: 100%;
                margin-bottom: 10px;
            }
        }

        @media (max-width: 576px) {
            .table thead {
                display: none;
            }

            .table tr {
                display: flex;
                flex-direction: column;
                margin-bottom: 20px;
            }

            .table td {
                display: flex;
                justify-content: space-between;
                padding: 10px;
                border: 1px solid var(--bs-border-color);
            }

            .table td::before {
                content: attr(data-label);
                font-weight: bold;
                text-transform: uppercase;
                margin-bottom: 5px;
                display: block;
            }

            /* Action button styles handled by unified styles in header.php */
        }
    </style>
</head>

<body>
    <div class="container mt-4 content" style="max-width: 900px;">
        <h1 class="text-center">Manage Strains</h1>
        <?php if (isset($_SESSION['message'])) : ?>
            <div class="alert alert-info">
                <?= htmlspecialchars($_SESSION['message']); ?>
                <?php unset($_SESSION['message']); ?>
            </div>
        <?php endif; ?>

        <!-- Search and Action Bar -->
        <form method="GET" action="">
            <div class="header-actions">
                <div class="search-box">
                    <div class="input-group">
                        <input type="text" class="form-control" name="search"
                               placeholder="Search strains..."
                               value="<?php echo htmlspecialchars($search); ?>">
                        <button class="btn btn-primary" type="submit">
                            <i class="fas fa-search"></i> Search
                        </button>
                    </div>
                </div>
                <button type="button" onclick="openForm()" class="btn btn-success"><i class="fas fa-plus"></i> Add New Strain</button>
            </div>
        </form>
        <?php if (!empty($search)): ?>
            <div class="mb-2">
                <a href="manage_strain.php" class="btn btn-secondary btn-sm"><i class="fas fa-times"></i> Clear Search</a>
            </div>
        <?php endif; ?>
        <div class="pagination-info">
            <?php if ($total_records > 0): ?>
                Showing <?= $offset + 1; ?> - <?= min($offset + $records_per_page, $total_records); ?>
                of <?= $total_records; ?> strains
            <?php else: ?>
                No strains found
            <?php endif; ?>
        </div>

        <!-- Popup form for adding and editing strains -->
        <div class="popup-overlay" id="popupOverlay"></div>
        <div class="popup-form" id="popupForm">
            <h4 id="formTitle">Add New Strain</h4>
            <form action="manage_strain.php" method="post">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                <div class="mb-3">
                    <label for="strain_id">Strain ID <span class="required-asterisk">*</span></label>
                    <input type="text" name="strain_id" id="strain_id" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label for="strain_name">Strain Name <span class="required-asterisk">*</span></label>
                    <input type="text" name="strain_name" id="strain_name" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label for="strain_aka">Common Names (comma separated)</label>
                    <input type="text" name="strain_aka" id="strain_aka" class="form-control">
                </div>
                <div class="mb-3">
                    <label for="strain_url">Strain URL</label>
                    <input type="url" name="strain_url" id="strain_url" class="form-control">
                </div>
                <div class="mb-3">
                    <label for="strain_rrid">Strain RRID</label>
                    <input type="text" name="strain_rrid" id="strain_rrid" class="form-control">
                </div>
                <div class="mb-3">
                    <label for="strain_notes">Notes</label>
                    <textarea name="strain_notes" id="strain_notes" class="form-control" rows="3"></textarea>
                </div>
                <div class="form-buttons">
                    <button type="submit" name="add" id="addButton" class="btn btn-primary"><i class="fas fa-plus"></i> Add Strain</button>
                    <button type="submit" name="edit" id="editButton" class="btn btn-success" style="display: none;"><i class="fas fa-save"></i> Update Strain</button>
                    <button type="button" class="btn btn-secondary" onclick="closeForm()">Cancel</button>
                </div>
            </form>
        </div>

        <!-- Popup form for viewing strain details -->
        <div class="popup-overlay" id="viewPopupOverlay"></div>
        <div class="view-popup-form" id="viewPopupForm">
            <h4 id="viewFormTitle">View Strain</h4>
            <div class="mb-3">
                <strong for="view_strain_id">Strain ID:</strong>
                <p id="view_strain_id"></p>
            </div>
            <div class="mb-3">
                <strong for="view_strain_name">Strain Name:</strong>
                <p id="view_strain_name"></p>
            </div>
            <div class="mb-3">
                <strong for="view_strain_aka">Common Names:</strong>
                <p id="view_strain_aka"></p>
            </div>
            <div class="mb-3">
                <strong for="view_strain_url">Strain URL:</strong>
                <p><a href="#" id="view_strain_url" target="_blank"></a></p>
            </div>
            <div class="mb-3">
                <strong for="view_strain_rrid">Strain RRID:</strong>
                <p id="view_strain_rrid"></p>
            </div>
            <div class="mb-3">
                <strong for="view_strain_notes">Notes:</strong>
                <p id="view_strain_notes"></p>
            </div>
            <div class="form-buttons">
                <button type="button" class="btn btn-secondary" onclick="closeViewForm()">Close</button>
            </div>
        </div>

        <!-- Display existing strains -->
        <h3>Existing Strains</h3>
        <table class="table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Name</th>
                    <th>RRID</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($row = $strainResult->fetch_assoc()) : ?>
                    <tr>
                        <td data-label="ID"><?= htmlspecialchars($row['str_id']); ?></td>
                        <td data-label="Name"><?= htmlspecialchars($row['str_name']); ?></td>
                        <td data-label="RRID"><?= htmlspecialchars($row['str_rrid']); ?></td>
                        <td data-label="Actions" class="table-actions">
                            <div class="action-buttons">
                                <button class="btn btn-info btn-sm" title="View" onclick="viewStrain(this)" data-id="<?= htmlspecialchars($row['str_id']); ?>" data-name="<?= htmlspecialchars($row['str_name']); ?>" data-aka="<?= htmlspecialchars($row['str_aka']); ?>" data-url="<?= htmlspecialchars($row['str_url']); ?>" data-rrid="<?= htmlspecialchars($row['str_rrid']); ?>" data-notes="<?= htmlspecialchars($row['str_notes']); ?>"><i class="fas fa-eye"></i></button>
                                <button class="btn btn-warning btn-sm" title="Edit" onclick="editStrain(this)" data-id="<?= htmlspecialchars($row['str_id']); ?>" data-name="<?= htmlspecialchars($row['str_name']); ?>" data-aka="<?= htmlspecialchars($row['str_aka']); ?>" data-url="<?= htmlspecialchars($row['str_url']); ?>" data-rrid="<?= htmlspecialchars($row['str_rrid']); ?>" data-notes="<?= htmlspecialchars($row['str_notes']); ?>"><i class="fas fa-edit"></i></button>
                                <form action="manage_strain.php" method="post" style="display:inline-block;">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                    <input type="hidden" name="strain_id" value="<?= htmlspecialchars($row['str_id']); ?>">
                                    <button type="submit" name="delete" class="btn btn-danger btn-sm" title="Delete" onclick="return confirm('Are you sure you want to delete this strain?');"><i class="fas fa-trash-alt"></i></button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
            <nav aria-label="Strain pagination" class="mt-3">
                <ul class="pagination justify-content-center">
                    <?php if ($current_page > 1): ?>
                        <li class="page-item">
                            <a class="page-link" href="?<?= buildStrainQueryString(['page' => $current_page - 1]); ?>">Previous</a>
                        </li>
                    <?php endif; ?>

                    <?php for ($i = max(1, $current_page - 2); $i <= min($total_pages, $current_page + 2); $i++): ?>
                        <li class="page-item <?= $i == $current_page ? 'active' : ''; ?>">
                            <a class="page-link" href="?<?= buildStrainQueryString(['page' => $i]); ?>"><?= $i; ?></a>
                        </li>
                    <?php endfor; ?>

                    <?php if ($current_page < $total_pages): ?>
                        <li class="page-item">
                            <a class="page-link" href="?<?= buildStrainQueryString(['page' => $current_page + 1]); ?>">Next</a>
                        </li>
                    <?php endif; ?>
                </ul>
            </nav>
        <?php endif; ?>

    <script>
        // Function to open the popup form
        function openForm() {
            document.getElementById('popupOverlay').style.display = 'block';
            document.getElementById('popupForm').style.display = 'block';
            document.getElementById('formTitle').innerText = 'Add New Strain';
            document.getElementById('addButton').style.display = 'block';
            document.getElementById('editButton').style.display = 'none';
            document.getElementById('strain_id').readOnly = false; // Ensure field is editable when adding
            document.getElementById('strain_id').value = '';
            document.getElementById('strain_name').value = '';
            document.getElementById('strain_aka').value = '';
            document.getElementById('strain_url').value = '';
            document.getElementById('strain_rrid').value = '';
            document.getElementById('strain_notes').value = ''; // Clear notes field
        }

        // Function to close the popup form
        function closeForm() {
            document.getElementById('popupOverlay').style.display = 'none';
            document.getElementById('popupForm').style.display = 'none';
        }

        // Function to populate the form for editing
        function editStrain(btn) {
            var id = btn.getAttribute('data-id');
            var name = btn.getAttribute('data-name');
            var aka = btn.getAttribute('data-aka');
            var url = btn.getAttribute('data-url');
            var rrid = btn.getAttribute('data-rrid');
            var notes = btn.getAttribute('data-notes');
            openForm();
            document.getElementById('formTitle').innerText = 'Edit Strain';
            document.getElementById('addButton').style.display = 'none';
            document.getElementById('editButton').style.display = 'block';
            document.getElementById('strain_id').readOnly = true; // Make field read-only when editing
            document.getElementById('strain_id').value = id;
            document.getElementById('strain_name').value = name;
            document.getElementById('strain_aka').value = aka;
            document.getElementById('strain_url').value = url;
            document.getElementById('strain_rrid').value = rrid;
            document.getElementById('strain_notes').value = notes;
        }

        // Function to open the view popup form
        function viewStrain(btn) {
            var id = btn.getAttribute('data-id');
            var name = btn.getAttribute('data-name');
            var aka = btn.getAttribute('data-aka');
            var url = btn.getAttribute('data-url');
            var rrid = btn.getAttribute('data-rrid');
            var notes = btn.getAttribute('data-notes');
            document.getElementById('viewPopupOverlay').style.display = 'block';
            document.getElementById('viewPopupForm').style.display = 'block';
            document.getElementById('view_strain_id').textContent = id;
            document.getElementById('view_strain_name').textContent = name;
            document.getElementById('view_strain_aka').textContent = aka;
            document.getElementById('view_strain_url').textContent = url;
            document.getElementById('view_strain_url').href = url;
            document.getElementById('view_strain_rrid').textContent = rrid;
            // Use textContent and create line breaks safely
            var notesEl = document.getElementById('view_strain_notes');
            notesEl.textContent = '';
            notes.split('\n').forEach(function(line, i) {
                if (i > 0) notesEl.appendChild(document.createElement('br'));
                notesEl.appendChild(document.createTextNode(line));
            });
        }

        // Function to close the view popup form
        function closeViewForm() {
            document.getElementById('viewPopupOverlay').style.display = 'none';
            document.getElementById('viewPopupForm').style.display = 'none';
        }
    </script>

    <div class="extra-space"></div> <!-- Add extra space before the footer -->
    <?php require 'footer.php'; // Include the footer 
    ?>
</body>

</html>
