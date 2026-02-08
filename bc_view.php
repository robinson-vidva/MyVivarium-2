<?php

/**
 * View Breeding Cage 
 *
 * This script displays detailed information about a specific breeding cage identified by its cage ID.
 * It retrieves data from the database, including basic information, associated files, and litter details.
 * The script ensures that only logged-in users can access the page and provides options for editing, printing, and viewing a QR code.
 *
 */

// Start a new session or resume the existing session
require 'session_config.php';

// Include the database connection file
require 'dbcon.php';

// Check if the user is not logged in, redirect them to index.php with the current URL for redirection after login
if (!isset($_SESSION['username'])) {
    $currentUrl = urlencode($_SERVER['REQUEST_URI']);
    header("Location: index.php?redirect=$currentUrl");
    exit; // Exit to ensure no further code is executed
}

// Disable error display in production (errors logged to server logs)
error_reporting(E_ALL);
ini_set('display_errors', 0);

// Query to get lab data (URL) from the settings table
$labQuery = "SELECT value FROM settings WHERE name = 'url' LIMIT 1";
$labResult = mysqli_query($con, $labQuery);

// Default value if the query fails or returns no result
$url = "";
if ($row = mysqli_fetch_assoc($labResult)) {
    $url = $row['value'];
}

// Check if the ID parameter is set in the URL
if (isset($_GET['id'])) {
    $id = mysqli_real_escape_string($con, $_GET['id']);

    // Fetch the breeding cage record with the specified ID
    $query = "SELECT b.*, c.remarks AS remarks, pi.initials AS pi_initials, pi.name AS pi_name, c.room, c.rack
          FROM breeding b
          LEFT JOIN cages c ON b.cage_id = c.cage_id
          LEFT JOIN users pi ON c.pi_name = pi.id
          WHERE b.cage_id = ?";
    $stmt = $con->prepare($query);
    $stmt->bind_param("s", $id);
    $stmt->execute();
    $result = $stmt->get_result();

    // Fetch files associated with the specified cage ID
    $query2 = "SELECT * FROM files WHERE cage_id = ?";
    $stmt2 = $con->prepare($query2);
    $stmt2->bind_param("s", $id);
    $stmt2->execute();
    $files = $stmt2->get_result();

    // Fetch the breeding cage litter records with the specified ID
    $query3 = "SELECT * FROM litters WHERE `cage_id` = ?";
    $stmt3 = $con->prepare($query3);
    $stmt3->bind_param("s", $id);
    $stmt3->execute();
    $litters = $stmt3->get_result();

    // Check if the breeding cage record exists
    if (mysqli_num_rows($result) === 1) {
        $breedingcage = mysqli_fetch_assoc($result);

        // Fetch IACUC codes associated with the cage
        $iacucQuery = "SELECT ci.iacuc_id, i.file_url
                        FROM cage_iacuc ci
                        LEFT JOIN iacuc i ON ci.iacuc_id = i.iacuc_id
                        WHERE ci.cage_id = ?";
        $stmtIacuc = $con->prepare($iacucQuery);
        $stmtIacuc->bind_param("s", $id);
        $stmtIacuc->execute();
        $iacucResult = $stmtIacuc->get_result();
        $iacucLinks = [];
        while ($row = mysqli_fetch_assoc($iacucResult)) {
            if (!empty($row['file_url'])) {
                $iacucLinks[] = "<a href='" . htmlspecialchars($row['file_url']) . "' target='_blank'>" . htmlspecialchars($row['iacuc_id']) . "</a>";
            } else {
                $iacucLinks[] = htmlspecialchars($row['iacuc_id']);
            }
        }
        $iacucDisplayString = implode(', ', $iacucLinks);
    } else {
        // If the record does not exist, set an error message and redirect to the dashboard
        $_SESSION['message'] = 'Invalid ID.';
        header("Location: bc_dash.php");
        exit();
    }
} else {
    // If the ID parameter is missing, set an error message and redirect to the dashboard
    $_SESSION['message'] = 'ID parameter is missing.';
    header("Location: bc_dash.php");
    exit();
}

function getUserDetailsByIds($con, $userIds)
{
    $placeholders = implode(',', array_fill(0, count($userIds), '?'));
    $query = "SELECT id, initials, name FROM users WHERE id IN ($placeholders)";
    $stmt = $con->prepare($query);
    $stmt->bind_param(str_repeat('i', count($userIds)), ...$userIds);
    $stmt->execute();
    $result = $stmt->get_result();
    $userDetails = [];
    while ($row = $result->fetch_assoc()) {
        $userDetails[$row['id']] = htmlspecialchars($row['initials'] . ' [' . $row['name'] . ']');
    }
    $stmt->close();
    return $userDetails;
}

// Fetch user IDs associated with the cage
$userIdsQuery = "SELECT cu.user_id
                 FROM cage_users cu
                 WHERE cu.cage_id = ?";
$stmtUserIds = $con->prepare($userIdsQuery);
$stmtUserIds->bind_param("s", $id);
$stmtUserIds->execute();
$userIdsResult = $stmtUserIds->get_result();
$userIds = [];
while ($row = mysqli_fetch_assoc($userIdsResult)) {
    $userIds[] = $row['user_id'];
}

// Fetch the user details based on IDs
$userDetails = getUserDetailsByIds($con, $userIds);

// Prepare a string to display user details
$userDisplay = [];
foreach ($userIds as $userId) {
    if (isset($userDetails[$userId])) {
        $userDisplay[] = $userDetails[$userId];
    } else {
        $userDisplay[] = htmlspecialchars($userId);
    }
}
$userDisplayString = implode(', ', $userDisplay);

// Fetch the maintenance logs for the current cage
$maintenanceQuery = "
    SELECT m.timestamp, u.name AS user_name, m.comments 
    FROM maintenance m
    JOIN users u ON m.user_id = u.id
    WHERE m.cage_id = ?
    ORDER BY m.timestamp DESC";

$stmtMaintenance = $con->prepare($maintenanceQuery);
$stmtMaintenance->bind_param("s", $id); // Assuming $id holds the current cage_id
$stmtMaintenance->execute();
$maintenanceLogs = $stmtMaintenance->get_result();

// Include the header file
require 'header.php';
?>

<!doctype html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Breeding Cage | <?php echo htmlspecialchars($labName); ?></title>

    <script>
        // Function to display a QR code in an inline modal
        function showQrCodePopup(cageId) {
            var baseUrl = <?php echo json_encode($url); ?>;
            var pageUrl = 'https://' + baseUrl + '/bc_view.php?id=' + encodeURIComponent(cageId);
            var qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' + encodeURIComponent(pageUrl);

            document.getElementById('qrTitle').textContent = 'QR Code for Cage ' + cageId;
            document.getElementById('qrImage').src = qrUrl;
            document.getElementById('qrOverlay').style.display = 'block';
            document.getElementById('qrForm').style.display = 'block';
        }

        function closeQrCodePopup() {
            document.getElementById('qrOverlay').style.display = 'none';
            document.getElementById('qrForm').style.display = 'none';
        }

        // Function to navigate back to the previous page
        function goBack() {
            const urlParams = new URLSearchParams(window.location.search);
            const page = urlParams.get('page') || 1;
            const search = urlParams.get('search') || '';
            window.location.href = 'bc_dash.php?page=' + page + '&search=' + encodeURIComponent(search);
        }

        // Information Completeness Calculation
        document.addEventListener('DOMContentLoaded', function() {
            const fields = {
                critical: ['male-id', 'female-id'],
                important: ['pi', 'cross', 'iacuc', 'user'],
                useful: ['male-dob', 'female-dob']
            };

            let totalFields = 0;
            let filledFields = 0;
            let missingCritical = [];
            let missingImportant = [];
            let missingUseful = [];

            // Count critical fields
            fields.critical.forEach(fieldId => {
                totalFields++;
                const field = document.getElementById(fieldId + '-data');
                if (field && field.dataset.value) {
                    filledFields++;
                } else {
                    const label = fieldId.split('-').map(word => word.charAt(0).toUpperCase() + word.slice(1)).join(' ');
                    missingCritical.push(label);
                }
            });

            // Count important fields
            fields.important.forEach(fieldId => {
                totalFields++;
                const field = document.getElementById(fieldId + '-data');
                if (field && field.dataset.value) {
                    filledFields++;
                } else {
                    const label = fieldId === 'pi' ? 'PI Name' : (fieldId.charAt(0).toUpperCase() + fieldId.slice(1));
                    missingImportant.push(label);
                }
            });

            // Count useful fields
            fields.useful.forEach(fieldId => {
                totalFields++;
                const field = document.getElementById(fieldId + '-data');
                if (field && field.dataset.value) {
                    filledFields++;
                } else {
                    const label = fieldId.split('-').map(word => word.charAt(0).toUpperCase() + word.slice(1)).join(' ');
                    missingUseful.push(label);
                }
            });

            const percentage = Math.round((filledFields / totalFields) * 100);

            // Only show alert if not 100% complete
            if (percentage < 100) {
                const alert = document.getElementById('completeness-alert');
                const bar = document.getElementById('completeness-bar');
                const percentageText = document.getElementById('completeness-percentage');
                const missingFieldsDiv = document.getElementById('missing-fields');

                // Update percentage
                bar.style.width = percentage + '%';
                bar.setAttribute('aria-valuenow', percentage);
                bar.textContent = percentage + '%';
                percentageText.textContent = percentage + '%';

                // Change bar and alert color based on completion
                bar.classList.remove('bg-danger', 'bg-warning', 'bg-success');
                alert.classList.remove('alert-danger', 'alert-warning', 'alert-success');
                if (percentage < 50) {
                    bar.classList.add('bg-danger');
                    alert.classList.add('alert-danger');
                } else if (percentage < 80) {
                    bar.classList.add('bg-warning');
                    alert.classList.add('alert-warning');
                } else {
                    bar.classList.add('bg-success');
                    alert.classList.add('alert-success');
                }

                // Show missing fields
                let missingText = '';
                if (missingCritical.length > 0) {
                    missingText += '<strong class="text-danger">Critical fields missing:</strong> ' + missingCritical.join(', ') + '<br>';
                }
                if (missingImportant.length > 0) {
                    missingText += '<strong class="text-warning">Important fields missing:</strong> ' + missingImportant.join(', ') + '<br>';
                }
                if (missingUseful.length > 0) {
                    missingText += '<strong class="text-muted">Useful fields missing:</strong> ' + missingUseful.join(', ');
                }

                missingFieldsDiv.innerHTML = missingText;
                alert.style.display = 'block';
            }
        });
    </script>

    <style>
        body {
            background: none !important;
            background-color: transparent !important;
        }

        .container {
            max-width: 900px;
            padding: 20px 15px;
            margin: auto;
        }

        /* Section Cards */
        .section-card {
            background-color: var(--bs-tertiary-bg);
            border: 1px solid var(--bs-border-color);
            border-radius: 10px;
            padding: 24px;
            margin-bottom: 20px;
        }

        .section-header {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 18px;
            padding-bottom: 12px;
            border-bottom: 1px solid var(--bs-border-color);
        }

        .section-header i {
            font-size: 1.1rem;
            color: var(--bs-primary);
            width: 22px;
            text-align: center;
        }

        .section-header h5 {
            margin: 0;
            font-weight: 600;
            font-size: 1.05rem;
        }

        .section-header .action-buttons {
            margin-left: auto;
        }

        /* Details Table (key-value pairs) */
        .details-table {
            width: 100%;
            border-collapse: collapse;
        }

        .details-table th,
        .details-table td {
            padding: 10px 14px;
            border-bottom: 1px solid var(--bs-border-color);
            vertical-align: middle;
            font-size: 0.92rem;
        }

        .details-table th {
            width: 35%;
            font-weight: 600;
            color: var(--bs-body-color);
            background-color: transparent;
            text-transform: none;
            letter-spacing: 0;
            text-align: left;
        }

        .details-table td {
            color: var(--bs-secondary-color);
        }

        .details-table td a {
            color: var(--bs-primary);
        }

        .details-table tr:last-child th,
        .details-table tr:last-child td {
            border-bottom: none;
        }

        /* Note app */
        .note-app-container {
            margin-top: 20px;
            padding: 20px;
            background-color: var(--bs-tertiary-bg);
            border: 1px solid var(--bs-border-color);
            border-radius: 10px;
        }

        /* Popup */
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
            width: 80%;
            max-width: 800px;
            max-height: 90vh;
            overflow-y: auto;
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
    </style>
    <!-- Font Awesome loaded via header.php -->
</head>

<body>

    <div class="container content mt-4">

        <!-- Cage Details Section -->
        <div class="section-card">
            <div class="section-header">
                <i class="fas fa-venus-mars"></i>
                <h5>Breeding Cage <?= htmlspecialchars($breedingcage['cage_id']); ?></h5>
                <div class="action-buttons">
                    <a href="javascript:void(0);" onclick="goBack()" class="btn btn-secondary btn-sm" data-bs-toggle="tooltip" data-bs-placement="top" title="Go Back">
                        <i class="fas fa-arrow-circle-left"></i>
                    </a>
                    <a href="bc_edit.php?id=<?= rawurlencode($breedingcage['cage_id']); ?>" class="btn btn-warning btn-sm" data-bs-toggle="tooltip" data-bs-placement="top" title="Edit Cage">
                        <i class="fas fa-edit"></i>
                    </a>
                    <a href="bc_addn.php?clone=<?= rawurlencode($breedingcage['cage_id']); ?>" class="btn btn-success btn-sm" data-bs-toggle="tooltip" data-bs-placement="top" title="Duplicate Cage">
                        <i class="fas fa-clone"></i>
                    </a>
                    <a href="manage_tasks.php?id=<?= rawurlencode($breedingcage['cage_id']); ?>" class="btn btn-info btn-sm" data-bs-toggle="tooltip" data-bs-placement="top" title="Manage Tasks">
                        <i class="fas fa-tasks"></i>
                    </a>
                    <a href="javascript:void(0);" onclick="showQrCodePopup(<?= htmlspecialchars(json_encode($breedingcage['cage_id'])); ?>)" class="btn btn-primary btn-sm" data-bs-toggle="tooltip" data-bs-placement="top" title="QR Code">
                        <i class="fas fa-qrcode"></i>
                    </a>
                    <a href="javascript:void(0);" onclick="window.print()" class="btn btn-secondary btn-sm" data-bs-toggle="tooltip" data-bs-placement="top" title="Print Cage">
                        <i class="fas fa-print"></i>
                    </a>
                </div>
            </div>

            <!-- Information Completeness Indicator -->
            <div id="completeness-alert" class="alert" style="display: none; margin-bottom: 20px;">
                <strong>Information Completeness:</strong> <span id="completeness-percentage">0%</span>
                <div class="progress mt-2" style="height: 20px;">
                    <div id="completeness-bar" class="progress-bar" role="progressbar" style="width: 0%;" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100">0%</div>
                </div>
                <div id="missing-fields" class="mt-2"></div>
            </div>

            <table class="details-table" id="mouseTable">
                <tr>
                    <th>Cage #</th>
                    <td><?= htmlspecialchars($breedingcage['cage_id']); ?></td>
                </tr>
                <tr>
                    <th>PI Name</th>
                    <td id="pi-data" data-value="<?= !empty($breedingcage['pi_name']) && $breedingcage['pi_name'] !== 'NA' ? '1' : ''; ?>"><?= htmlspecialchars($breedingcage['pi_initials'] . ' [' . $breedingcage['pi_name'] . ']'); ?></td>
                </tr>
                <tr>
                    <th>Room</th>
                    <td><?= htmlspecialchars($breedingcage['room'] ?? ''); ?></td>
                </tr>
                <tr>
                    <th>Rack</th>
                    <td><?= htmlspecialchars($breedingcage['rack'] ?? ''); ?></td>
                </tr>
                <tr>
                    <th>Cross</th>
                    <td id="cross-data" data-value="<?= !empty($breedingcage['cross']) ? '1' : ''; ?>"><?= htmlspecialchars($breedingcage['cross']); ?></td>
                </tr>
                <tr>
                    <th>IACUC</th>
                    <td id="iacuc-data" data-value="<?= !empty($iacucLinks) ? '1' : ''; ?>"><?= $iacucDisplayString; ?></td>
                </tr>
                <tr>
                    <th>User</th>
                    <td id="user-data" data-value="<?= !empty($userIds) ? '1' : ''; ?>"><?= $userDisplayString; ?></td>
                </tr>
                <tr>
                    <th>Male ID</th>
                    <td id="male-id-data" data-value="<?= !empty($breedingcage['male_id']) ? '1' : ''; ?>"><?= htmlspecialchars($breedingcage['male_id']); ?></td>
                </tr>
                <tr>
                    <th>Male Genotype</th>
                    <td><?= htmlspecialchars($breedingcage['male_genotype'] ?? ''); ?></td>
                </tr>
                <tr>
                    <th>Male DOB</th>
                    <td id="male-dob-data" data-value="<?= !empty($breedingcage['male_dob']) ? '1' : ''; ?>"><?= htmlspecialchars($breedingcage['male_dob']); ?></td>
                </tr>
                <tr>
                    <th>Female ID</th>
                    <td id="female-id-data" data-value="<?= !empty($breedingcage['female_id']) ? '1' : ''; ?>"><?= htmlspecialchars($breedingcage['female_id']); ?></td>
                </tr>
                <tr>
                    <th>Female Genotype</th>
                    <td><?= htmlspecialchars($breedingcage['female_genotype'] ?? ''); ?></td>
                </tr>
                <tr>
                    <th>Female DOB</th>
                    <td id="female-dob-data" data-value="<?= !empty($breedingcage['female_dob']) ? '1' : ''; ?>"><?= htmlspecialchars($breedingcage['female_dob']); ?></td>
                </tr>
                <tr>
                    <th>Remarks</th>
                    <td><?= htmlspecialchars($breedingcage['remarks']); ?></td>
                </tr>
            </table>
        </div>

        <!-- Files Section -->
        <div class="section-card">
            <div class="section-header">
                <i class="fas fa-file-alt"></i>
                <h5>Files</h5>
            </div>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>File Name</th>
                            <th style="width: 80px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $hasFiles = false;
                        while ($file = $files->fetch_assoc()) :
                            $hasFiles = true;
                        ?>
                            <tr>
                                <td><?= htmlspecialchars($file['file_name']); ?></td>
                                <td><div class="action-buttons"><a href="<?= htmlspecialchars($file['file_path']); ?>" download="<?= htmlspecialchars($file['file_name']); ?>" class="btn btn-sm btn-primary" title="Download"><i class="fas fa-cloud-download-alt"></i></a></div></td>
                            </tr>
                        <?php endwhile; ?>
                        <?php if (!$hasFiles) : ?>
                            <tr><td colspan="2" class="text-muted text-center">No files uploaded</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Litter Details Section -->
        <div class="section-card">
            <div class="section-header">
                <i class="fas fa-paw"></i>
                <h5>Litter Details</h5>
            </div>
            <?php while ($litter = mysqli_fetch_assoc($litters)) : ?>
                <table class="details-table" style="margin-bottom: 16px;">
                    <tbody>
                        <tr>
                            <th>DOM</th>
                            <td><?= htmlspecialchars($litter['dom'] ?? ''); ?></td>
                        </tr>
                        <tr>
                            <th>Litter DOB</th>
                            <td><?= htmlspecialchars($litter['litter_dob'] ?? ''); ?></td>
                        </tr>
                        <tr>
                            <th>Pups Alive</th>
                            <td><?= htmlspecialchars($litter['pups_alive'] ?? ''); ?></td>
                        </tr>
                        <tr>
                            <th>Pups Dead</th>
                            <td><?= htmlspecialchars($litter['pups_dead'] ?? ''); ?></td>
                        </tr>
                        <tr>
                            <th>Pups Male</th>
                            <td><?= htmlspecialchars($litter['pups_male'] ?? ''); ?></td>
                        </tr>
                        <tr>
                            <th>Pups Female</th>
                            <td><?= htmlspecialchars($litter['pups_female'] ?? ''); ?></td>
                        </tr>
                        <tr>
                            <th>Remarks</th>
                            <td><?= htmlspecialchars($litter['remarks'] ?? ''); ?></td>
                        </tr>
                    </tbody>
                </table>
            <?php endwhile; ?>
        </div>

        <!-- Maintenance Log Section -->
        <div class="section-card">
            <div class="section-header">
                <i class="fas fa-clipboard-list"></i>
                <h5>Maintenance Log</h5>
                <div class="action-buttons">
                    <a href="maintenance.php?from=bc_dash" class="btn btn-warning btn-sm" data-bs-toggle="tooltip" data-bs-placement="top" title="Add Maintenance Record">
                        <i class="fas fa-wrench"></i>
                    </a>
                    <a href="bc_edit.php?id=<?= rawurlencode($breedingcage['cage_id']); ?>" class="btn btn-warning btn-sm" data-bs-toggle="tooltip" data-bs-placement="top" title="Edit Cage">
                        <i class="fas fa-edit"></i>
                    </a>
                </div>
            </div>
            <?php if ($maintenanceLogs->num_rows > 0) : ?>
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>User</th>
                                <th>Comment</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($log = $maintenanceLogs->fetch_assoc()) : ?>
                                <tr>
                                    <td><?= htmlspecialchars($log['timestamp'] ?? ''); ?></td>
                                    <td><?= htmlspecialchars($log['user_name'] ?? 'Unknown'); ?></td>
                                    <td><?= htmlspecialchars($log['comments'] ?? 'No comment'); ?></td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            <?php else : ?>
                <p class="text-muted mb-0">No maintenance records found for this cage.</p>
            <?php endif; ?>
        </div>

        <!-- Notes Section -->
        <div class="note-app-container">
            <?php include 'nt_app.php'; ?>
        </div>
    </div>

    <?php include 'footer.php'; ?>

    <!-- QR Code Modal -->
    <div class="popup-overlay" id="qrOverlay" onclick="closeQrCodePopup()"></div>
    <div class="popup-form" id="qrForm" style="max-width: 400px; text-align: center;">
        <div class="d-flex justify-content-between align-items-center mb-2">
            <h4 id="qrTitle" class="mb-0">QR Code</h4>
            <button type="button" class="btn-close" onclick="closeQrCodePopup()" aria-label="Close"></button>
        </div>
        <img id="qrImage" src="" alt="QR Code" style="margin: 15px 0; max-width: 200px;">
        <br>
        <button type="button" class="btn btn-secondary" onclick="closeQrCodePopup()">Close</button>
    </div>

</body>

</html>