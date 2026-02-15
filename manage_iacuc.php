<?php
/**
 * Manage IACUC
 * 
 * This script provides functionality for managing IACUC records in a database. It allows users to add new IACUC records,
 * edit existing records, and delete records. The interface includes a responsive popup form for data entry and 
 * a table for displaying existing records. The script uses PHP sessions for message handling and includes basic 
 * input sanitization for security. File upload functionality is included for the IACUC records.
 * 
 */

require 'session_config.php'; // Start the session to use session variables
require 'dbcon.php'; // Include database connection
require 'header.php'; // Include the header for consistent page structure

// Generate CSRF token if not already set
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Handle file upload with validation
function handleFileUpload($file) {
    // Define allowed file types and maximum size
    $allowedExtensions = ['pdf', 'doc', 'docx', 'txt', 'xls', 'xlsx', 'ppt', 'pptx',
                          'jpg', 'jpeg', 'png', 'gif', 'bmp', 'svg', 'webp'];
    $maxFileSize = 10 * 1024 * 1024; // 10MB in bytes

    // Check if file was uploaded without errors
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return false;
    }

    // Validate file size
    if ($file['size'] > $maxFileSize) {
        $_SESSION['message'] = "File size exceeds 10MB limit.";
        return false;
    }

    // Get file extension
    $fileExtension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

    // Validate file extension
    if (!in_array($fileExtension, $allowedExtensions)) {
        $_SESSION['message'] = "Invalid file type. Allowed: pdf, doc, docx, txt, xls, xlsx, ppt, pptx, jpg, jpeg, png, gif, bmp, svg, webp";
        return false;
    }

    // Sanitize filename to prevent directory traversal
    $safeFilename = preg_replace("/[^a-zA-Z0-9._-]/", "_", basename($file["name"]));

    $targetDir = "uploads/iacuc/";
    if (!is_dir($targetDir)) {
        mkdir($targetDir, 0755, true); // Create directory if it doesn't exist
    }

    $targetFile = $targetDir . $safeFilename;

    // Move uploaded file
    if (move_uploaded_file($file["tmp_name"], $targetFile)) {
        return $targetFile;
    } else {
        $_SESSION['message'] = "Error uploading file.";
        return false;
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die('CSRF token validation failed');
    }

    if (isset($_POST['add'])) {
        // Add new IACUC
        $iacucId = htmlspecialchars($_POST['iacuc_id']); // Sanitize input
        $iacucTitle = htmlspecialchars($_POST['iacuc_title']); // Sanitize input

        // Check if the IACUC ID already exists
        $checkQuery = $con->prepare("SELECT iacuc_id FROM iacuc WHERE iacuc_id = ?");
        $checkQuery->bind_param("s", $iacucId);
        $checkQuery->execute();
        $checkQuery->store_result();
        if ($checkQuery->num_rows > 0) {
            $_SESSION['message'] = "IACUC ID already exists. Please use a different ID.";
            $checkQuery->close();
        } else {
            $checkQuery->close();
            $fileUrl = !empty($_FILES['iacuc_file']['name']) ? handleFileUpload($_FILES['iacuc_file']) : null;
            if ($fileUrl !== false) {
                $stmt = $con->prepare("INSERT INTO iacuc (iacuc_id, iacuc_title, file_url) VALUES (?, ?, ?)");
                $stmt->bind_param("sss", $iacucId, $iacucTitle, $fileUrl);
                if ($stmt->execute()) {
                    $_SESSION['message'] = "IACUC record added successfully."; // Success message
                } else {
                    $_SESSION['message'] = "Error adding IACUC record."; // Error message
                }
                $stmt->close(); // Close the statement
            } else {
                $_SESSION['message'] = "Error uploading file.";
            }
        }
    } elseif (isset($_POST['edit'])) {
        // Update existing IACUC
        $iacucId = htmlspecialchars($_POST['iacuc_id']); // Sanitize input
        $iacucTitle = htmlspecialchars($_POST['iacuc_title']); // Sanitize input
        $fileUrl = !empty($_FILES['iacuc_file']['name']) ? handleFileUpload($_FILES['iacuc_file']) : htmlspecialchars($_POST['existing_file_url']);
        
        if ($fileUrl !== false) {
            $stmt = $con->prepare("UPDATE iacuc SET iacuc_title = ?, file_url = ? WHERE iacuc_id = ?");
            $stmt->bind_param("sss", $iacucTitle, $fileUrl, $iacucId);
            if ($stmt->execute()) {
                $_SESSION['message'] = "IACUC record updated successfully."; // Success message
            } else {
                $_SESSION['message'] = "Error updating IACUC record."; // Error message
            }
            $stmt->close(); // Close the statement
        } else {
            $_SESSION['message'] = "Error uploading file.";
        }
    } elseif (isset($_POST['delete'])) {
        // Delete IACUC
        $iacucId = htmlspecialchars($_POST['iacuc_id']); // Sanitize input
        $stmt = $con->prepare("DELETE FROM iacuc WHERE iacuc_id = ?");
        $stmt->bind_param("s", $iacucId);
        if ($stmt->execute()) {
            $_SESSION['message'] = "IACUC record deleted successfully."; // Success message
        } else {
            $_SESSION['message'] = "Error deleting IACUC record."; // Error message
        }
        $stmt->close(); // Close the statement
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

// Get total count and fetch records
if (!empty($search)) {
    $search_pattern = '%' . $search . '%';
    $count_stmt = $con->prepare("SELECT COUNT(*) as total FROM iacuc WHERE iacuc_id LIKE ? OR iacuc_title LIKE ?");
    $count_stmt->bind_param("ss", $search_pattern, $search_pattern);
    $count_stmt->execute();
    $total_records = $count_stmt->get_result()->fetch_assoc()['total'];
    $count_stmt->close();

    $iacucStmt = $con->prepare("SELECT * FROM iacuc WHERE iacuc_id LIKE ? OR iacuc_title LIKE ? ORDER BY iacuc_id ASC LIMIT ? OFFSET ?");
    $iacucStmt->bind_param("ssii", $search_pattern, $search_pattern, $records_per_page, $offset);
    $iacucStmt->execute();
    $iacucResult = $iacucStmt->get_result();
    $iacucStmt->close();
} else {
    $total_records = $con->query("SELECT COUNT(*) as total FROM iacuc")->fetch_assoc()['total'];
    $iacucStmt = $con->prepare("SELECT * FROM iacuc ORDER BY iacuc_id ASC LIMIT ? OFFSET ?");
    $iacucStmt->bind_param("ii", $records_per_page, $offset);
    $iacucStmt->execute();
    $iacucResult = $iacucStmt->get_result();
    $iacucStmt->close();
}
$total_pages = ceil($total_records / $records_per_page);

// Helper to build query string for pagination
function buildIacucQueryString($overrides = []) {
    $params = [
        'search' => $_GET['search'] ?? '',
        'per_page' => $_GET['per_page'] ?? 10,
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
    <title>Manage IACUC</title>
    <!-- Bootstrap 5.3 loaded via header.php -->
    <!-- Font Awesome loaded via header.php -->
    <style>
        /* Popup Form Styles */
        .popup-form {
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

        /* Action button styles handled by unified styles in header.php */
        .form-buttons {
            display: flex;
            gap: 10px;
        }

        .form-buttons {
            justify-content: space-between;
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

            /* Mobile action button styles handled by unified styles in header.php */
        }

        .table {
            width: 100%;
            table-layout: auto;
        }

        /* Column widths */
        .table th:nth-child(1),
        .table td:nth-child(1) {
            width: 80px;
            text-align: center;
            white-space: nowrap;
        }

        .table th:nth-child(3),
        .table td:nth-child(3) {
            width: 130px;
            text-align: center;
        }

        .table th:nth-child(4),
        .table td:nth-child(4) {
            width: 120px;
        }
    </style>

</head>

<body>
    <div class="container mt-4 content" style="max-width: 900px;">
        <h1 class="text-center">Manage IACUC</h1>
        <?php if (isset($_SESSION['message'])) : ?>
            <div class="alert alert-info">
                <?= $_SESSION['message']; ?>
                <?php unset($_SESSION['message']); ?>
            </div>
        <?php endif; ?>

        <!-- Search and Action Bar -->
        <form method="GET" action="">
            <div class="header-actions">
                <div class="search-box">
                    <div class="input-group">
                        <input type="text" class="form-control" name="search"
                               placeholder="Search IACUC records..."
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
                <button type="button" onclick="openForm()" class="btn btn-success"><i class="fas fa-plus"></i> Add New IACUC</button>
            </div>
        </form>
        <?php if (!empty($search)): ?>
            <div class="mb-2">
                <a href="manage_iacuc.php" class="btn btn-secondary btn-sm"><i class="fas fa-times"></i> Clear Search</a>
            </div>
        <?php endif; ?>
        <div class="pagination-info">
            <?php if ($total_records > 0): ?>
                Showing <?= $offset + 1; ?> - <?= min($offset + $records_per_page, $total_records); ?>
                of <?= $total_records; ?> records
            <?php else: ?>
                No records found
            <?php endif; ?>
        </div>

        <!-- Popup form for adding and editing IACUC records -->
        <div class="popup-overlay" id="popupOverlay"></div>
        <div class="popup-form" id="popupForm">
            <h4 id="formTitle">Add New IACUC</h4>
            <form action="manage_iacuc.php" method="post" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                <div class="mb-3">
                    <label for="iacuc_id">IACUC ID <span class="required-asterisk">*</span></label>
                    <input type="text" name="iacuc_id" id="iacuc_id" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label for="iacuc_title">Title <span class="required-asterisk">*</span></label>
                    <input type="text" name="iacuc_title" id="iacuc_title" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label for="iacuc_file">Upload File</label>
                    <input type="file" name="iacuc_file" id="iacuc_file" class="form-control">
                    <div id="existingFile" style="margin-top: 10px;"></div>
                </div>
                <input type="hidden" name="existing_file_url" id="existing_file_url">
                <div class="form-buttons">
                    <button type="submit" name="add" id="addButton" class="btn btn-primary"><i class="fas fa-plus"></i> Add IACUC</button>
                    <button type="submit" name="edit" id="editButton" class="btn btn-success" style="display: none;"><i class="fas fa-save"></i> Update IACUC</button>
                    <button type="button" class="btn btn-secondary" onclick="closeForm()">Cancel</button>
                </div>
            </form>
        </div>

        <!-- Display existing IACUC records -->
        <h3>Existing IACUC Records</h3>
        <table class="table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Title</th>
                    <th>File</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($row = $iacucResult->fetch_assoc()) : ?>
                    <tr>
                        <td data-label="ID"><?= htmlspecialchars($row['iacuc_id']); ?></td>
                        <td data-label="Title"><?= htmlspecialchars($row['iacuc_title']); ?></td>
                        <td data-label="File">
                            <?php if ($row['file_url']) : ?>
                                <a href="<?= htmlspecialchars($row['file_url']); ?>" target="_blank">View/Download</a>
                            <?php else : ?>
                                No file uploaded
                            <?php endif; ?>
                        </td>
                        <td data-label="Actions" class="table-actions">
                            <div class="action-buttons">
                                <button class="btn btn-warning btn-sm" title="Edit" onclick="editIACUC('<?= $row['iacuc_id']; ?>', '<?= htmlspecialchars($row['iacuc_title']); ?>', '<?= htmlspecialchars($row['file_url']); ?>')"><i class="fas fa-edit"></i></button>
                                <form action="manage_iacuc.php" method="post" style="display:inline-block;">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                    <input type="hidden" name="iacuc_id" value="<?= $row['iacuc_id']; ?>">
                                    <button type="submit" name="delete" class="btn btn-danger btn-sm" title="Delete" onclick="return confirm('Are you sure you want to delete this IACUC record?');"><i class="fas fa-trash-alt"></i></button>
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
            <nav aria-label="IACUC pagination" class="mt-3">
                <ul class="pagination justify-content-center">
                    <?php if ($current_page > 1): ?>
                        <li class="page-item">
                            <a class="page-link" href="?<?= buildIacucQueryString(['page' => $current_page - 1]); ?>">Previous</a>
                        </li>
                    <?php endif; ?>

                    <?php for ($i = max(1, $current_page - 2); $i <= min($total_pages, $current_page + 2); $i++): ?>
                        <li class="page-item <?= $i == $current_page ? 'active' : ''; ?>">
                            <a class="page-link" href="?<?= buildIacucQueryString(['page' => $i]); ?>"><?= $i; ?></a>
                        </li>
                    <?php endfor; ?>

                    <?php if ($current_page < $total_pages): ?>
                        <li class="page-item">
                            <a class="page-link" href="?<?= buildIacucQueryString(['page' => $current_page + 1]); ?>">Next</a>
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
            document.getElementById('formTitle').innerText = 'Add New IACUC';
            document.getElementById('addButton').style.display = 'block';
            document.getElementById('editButton').style.display = 'none';
            document.getElementById('iacuc_id').readOnly = false; // Ensure field is editable when adding
            document.getElementById('iacuc_id').value = '';
            document.getElementById('iacuc_title').value = '';
            document.getElementById('iacuc_file').value = '';
            document.getElementById('existing_file_url').value = '';
            document.getElementById('existingFile').innerHTML = ''; // Clear existing file info
        }

        // Function to close the popup form
        function closeForm() {
            document.getElementById('popupOverlay').style.display = 'none';
            document.getElementById('popupForm').style.display = 'none';
        }

        // Function to populate the form for editing
        function editIACUC(id, title, fileUrl) {
            openForm();
            document.getElementById('formTitle').innerText = 'Edit IACUC';
            document.getElementById('addButton').style.display = 'none';
            document.getElementById('editButton').style.display = 'block';
            document.getElementById('iacuc_id').readOnly = true; // Make field read-only when editing
            document.getElementById('iacuc_id').value = id;
            document.getElementById('iacuc_title').value = title;
            document.getElementById('existing_file_url').value = fileUrl;
            document.getElementById('existingFile').innerHTML = fileUrl ? `<a href="${fileUrl}" target="_blank">Current File</a>` : 'No file uploaded';
        }
    </script>

    <div class="extra-space"></div> <!-- Add extra space before the footer -->
    <?php require 'footer.php'; // Include the footer ?>
</body>

</html>
