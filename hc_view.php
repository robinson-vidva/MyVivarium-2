<?php

/**
 * View Holding Cage
 * 
 * This script displays detailed information about a specific holding cage, including related files and notes. 
 * It also provides options to view, edit, print the cage information, and generate a QR code for the cage.
 * 
 */

require 'session_config.php';
require 'dbcon.php';

// Check if the user is not logged in
if (!isset($_SESSION['username'])) {
    $currentUrl = urlencode($_SERVER['REQUEST_URI']);
    header("Location: index.php?redirect=$currentUrl");
    exit;
}

// Generate CSRF token if not already present
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Disable error display in production (errors logged to server logs)
error_reporting(E_ALL);
ini_set('display_errors', 0);

// Get lab URL
$labQuery = "SELECT value FROM settings WHERE name = 'url' LIMIT 1";
$labResult = mysqli_query($con, $labQuery);
$url = "";
if ($row = mysqli_fetch_assoc($labResult)) {
    $url = $row['value'];
}

if (isset($_GET['id'])) {
    $id = mysqli_real_escape_string($con, $_GET['id']);

    $query = "SELECT h.*, pi.initials AS pi_initials, pi.name AS pi_name, s.*, c.quantity, c.remarks, c.room, c.rack
              FROM holding h
              LEFT JOIN cages c ON h.cage_id = c.cage_id
              LEFT JOIN users pi ON c.pi_name = pi.id
              LEFT JOIN strains s ON h.strain = s.str_id
              WHERE h.cage_id = ?";
    $stmt = $con->prepare($query);
    $stmt->bind_param("s", $id);
    $stmt->execute();
    $result = $stmt->get_result();

    $query2 = "SELECT * FROM files WHERE cage_id = ?";
    $stmt2 = $con->prepare($query2);
    $stmt2->bind_param("s", $id);
    $stmt2->execute();
    $files = $stmt2->get_result();

    if (mysqli_num_rows($result) === 1) {
        $holdingcage = mysqli_fetch_assoc($result);

        if (is_null($holdingcage['str_name']) || empty($holdingcage['str_name'])) {
            $holdingcage['str_id'] = 'NA';
            $holdingcage['str_name'] = 'Unknown Strain';
            $holdingcage['str_url'] = '#';
        }

        if (is_null($holdingcage['pi_name'])) {
            $queryBasic = "SELECT * FROM holding WHERE `cage_id` = ?";
            $stmtBasic = $con->prepare($queryBasic);
            $stmtBasic->bind_param("s", $id);
            $stmtBasic->execute();
            $resultBasic = $stmtBasic->get_result();
            if (mysqli_num_rows($resultBasic) === 1) {
                $holdingcage = mysqli_fetch_assoc($resultBasic);
                $holdingcage['pi_initials'] = 'NA';
                $holdingcage['pi_name'] = 'NA';
            } else {
                $_SESSION['message'] = 'Error fetching the cage details.';
                header("Location: hc_dash.php");
                exit();
            }
        }

        $iacucQuery = "SELECT GROUP_CONCAT(iacuc_id SEPARATOR ', ') AS iacuc_ids FROM cage_iacuc WHERE cage_id = ?";
        $stmtIacuc = $con->prepare($iacucQuery);
        $stmtIacuc->bind_param("s", $id);
        $stmtIacuc->execute();
        $iacucResult = $stmtIacuc->get_result();
        $iacucRow = mysqli_fetch_assoc($iacucResult);
        $iacucCodes = [];
        if (!empty($iacucRow['iacuc_ids'])) {
            $iacucCodes = explode(',', $iacucRow['iacuc_ids']);
        }
        $stmtIacuc->close();

        $iacucLinks = [];
        foreach ($iacucCodes as $iacucCode) {
            $iacucCode = trim($iacucCode);
            $iacucQuery = "SELECT file_url FROM iacuc WHERE iacuc_id = ?";
            $stmtIacucFile = $con->prepare($iacucQuery);
            $stmtIacucFile->bind_param("s", $iacucCode);
            $stmtIacucFile->execute();
            $iacucResult = $stmtIacucFile->get_result();
            if ($iacucResult && mysqli_num_rows($iacucResult) === 1) {
                $iacucRow = mysqli_fetch_assoc($iacucResult);
                if (!empty($iacucRow['file_url'])) {
                    $iacucLinks[] = "<a href='" . htmlspecialchars($iacucRow['file_url']) . "' target='_blank'>" . htmlspecialchars($iacucCode) . "</a>";
                } else {
                    $iacucLinks[] = htmlspecialchars($iacucCode);
                }
            } else {
                $iacucLinks[] = htmlspecialchars($iacucCode);
            }
            $stmtIacucFile->close();
        }

        $iacucDisplayString = implode(', ', $iacucLinks);

        $userQuery = "SELECT user_id FROM cage_users WHERE cage_id = ?";
        $stmtUser = $con->prepare($userQuery);
        $stmtUser->bind_param("s", $id);
        $stmtUser->execute();
        $userResult = $stmtUser->get_result();
        $userIds = [];
        while ($userRow = mysqli_fetch_assoc($userResult)) {
            $userIds[] = $userRow['user_id'];
        }

        $userDetails = getUserDetailsByIds($con, $userIds);

        $userDisplay = [];
        foreach ($userIds as $userId) {
            if (isset($userDetails[$userId])) {
                $userDisplay[] = $userDetails[$userId];
            } else {
                $userDisplay[] = htmlspecialchars($userId);
            }
        }
        $userDisplayString = implode(', ', $userDisplay);
        $stmtUser->close();

        $mouseQuery = "SELECT * FROM mice WHERE cage_id = ?";
        $stmtMouse = $con->prepare($mouseQuery);
        $stmtMouse->bind_param("s", $id);
        $stmtMouse->execute();
        $mouseResult = $stmtMouse->get_result();
        $mice = mysqli_fetch_all($mouseResult, MYSQLI_ASSOC);

        // Calculate age
        if (!empty($holdingcage['dob'])) {
            $dob = new DateTime($holdingcage['dob']);
            $now = new DateTime();
            $ageInterval = $dob->diff($now);

            $ageComponents = [];
            if ($ageInterval->y > 0) {
                $years = $ageInterval->y;
                $unit = ($years == 1) ? 'Year' : 'Years';
                $ageComponents[] = $years . ' ' . $unit;
            }
            if ($ageInterval->m > 0) {
                $months = $ageInterval->m;
                $unit = ($months == 1) ? 'Month' : 'Months';
                $ageComponents[] = $months . ' ' . $unit;
            }
            if ($ageInterval->d > 0) {
                $days = $ageInterval->d;
                $unit = ($days == 1) ? 'Day' : 'Days';
                $ageComponents[] = $days . ' ' . $unit;
            }
            if (empty($ageComponents)) {
                // If the age is less than a day
                $ageComponents[] = '0 Days';
            }
            $ageString = implode(' ', $ageComponents);
        } else {
            $ageString = 'Unknown';
        }

    } else {
        $_SESSION['message'] = 'Invalid ID.';
        header("Location: hc_dash.php");
        exit();
    }
} else {
    $_SESSION['message'] = 'ID parameter is missing.';
    header("Location: hc_dash.php");
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

require 'header.php';
?>

<!doctype html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Holding Cage | <?php echo htmlspecialchars($labName); ?></title>

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

        /* Mouse section heading */
        .mouse-heading {
            font-size: 1rem;
            font-weight: 600;
            margin: 20px 0 10px;
            color: var(--bs-body-color);
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

    <script>
        function showQrCodePopup(cageId) {
            var baseUrl = <?php echo json_encode($url); ?>;
            var pageUrl = 'https://' + baseUrl + '/hc_view.php?id=' + encodeURIComponent(cageId);
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

        function goBack() {
            const urlParams = new URLSearchParams(window.location.search);
            const page = urlParams.get('page') || 1;
            const search = urlParams.get('search') || '';
            window.location.href = 'hc_dash.php?page=' + page + '&search=' + encodeURIComponent(search);
        }

        function viewStrainDetails(id, name, aka, url, rrid, notes) {
            document.getElementById('view_strain_id').textContent = id;
            document.getElementById('view_strain_name').textContent = name;
            document.getElementById('view_strain_aka').textContent = aka;
            document.getElementById('view_strain_url').textContent = url;
            document.getElementById('view_strain_rrid').textContent = rrid;
            var notesEl = document.getElementById('view_strain_notes');
            notesEl.textContent = '';
            notes.split('\n').forEach(function(line, i) {
                if (i > 0) notesEl.appendChild(document.createElement('br'));
                notesEl.appendChild(document.createTextNode(line));
            });
            document.getElementById('viewPopupOverlay').style.display = 'block';
            document.getElementById('viewPopupForm').style.display = 'block';
            document.getElementById('view_strain_url').href = url;
        }

        function closeViewForm() {
            document.getElementById('viewPopupOverlay').style.display = 'none';
            document.getElementById('viewPopupForm').style.display = 'none';
        }

        function openTransferModal(dbId, mouseId) {
            document.getElementById('transfer_mouse_db_id').value = dbId;
            document.getElementById('transfer_mouse_id_display').textContent = mouseId;
            document.getElementById('transferOverlay').style.display = 'block';
            document.getElementById('transferForm').style.display = 'block';
        }

        function closeTransferModal() {
            document.getElementById('transferOverlay').style.display = 'none';
            document.getElementById('transferForm').style.display = 'none';
        }
    </script>
    <!-- Font Awesome loaded via header.php -->
</head>

<body>
    <div class="container content mt-4">

        <!-- Cage Details Section -->
        <div class="section-card">
            <div class="section-header">
                <i class="fas fa-home"></i>
                <h5>Holding Cage <?= htmlspecialchars($holdingcage['cage_id']); ?></h5>
                <div class="action-buttons">
                    <a href="javascript:void(0);" onclick="goBack()" class="btn btn-primary btn-sm" data-bs-toggle="tooltip" data-bs-placement="top" title="Go Back">
                        <i class="fas fa-arrow-circle-left"></i>
                    </a>
                    <a href="hc_edit.php?id=<?= rawurlencode($holdingcage['cage_id']); ?>" class="btn btn-warning btn-sm" data-bs-toggle="tooltip" data-bs-placement="top" title="Edit Cage">
                        <i class="fas fa-edit"></i>
                    </a>
                    <a href="hc_addn.php?clone=<?= rawurlencode($holdingcage['cage_id']); ?>" class="btn btn-info btn-sm" data-bs-toggle="tooltip" data-bs-placement="top" title="Duplicate Cage">
                        <i class="fas fa-clone"></i>
                    </a>
                    <a href="manage_tasks.php?id=<?= rawurlencode($holdingcage['cage_id']); ?>" class="btn btn-secondary btn-sm" data-bs-toggle="tooltip" data-bs-placement="top" title="Manage Tasks">
                        <i class="fas fa-tasks"></i>
                    </a>
                    <a href="javascript:void(0);" onclick="showQrCodePopup(<?= htmlspecialchars(json_encode($holdingcage['cage_id'])); ?>)" class="btn btn-success btn-sm" data-bs-toggle="tooltip" data-bs-placement="top" title="QR Code">
                        <i class="fas fa-qrcode"></i>
                    </a>
                    <a href="javascript:void(0);" onclick="window.print()" class="btn btn-primary btn-sm" data-bs-toggle="tooltip" data-bs-placement="top" title="Print Cage">
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

            <table class="details-table">
                <tr>
                    <th>Cage #</th>
                    <td><?= htmlspecialchars($holdingcage['cage_id']); ?></td>
                </tr>
                <tr>
                    <th>PI Name</th>
                    <td id="pi-data" data-value="<?= !empty($holdingcage['pi_name']) && $holdingcage['pi_name'] !== 'NA' ? '1' : ''; ?>"><?= htmlspecialchars($holdingcage['pi_initials'] . ' [' . $holdingcage['pi_name'] . ']'); ?></td>
                </tr>
                <tr>
                    <th>Room</th>
                    <td><?= htmlspecialchars($holdingcage['room'] ?? ''); ?></td>
                </tr>
                <tr>
                    <th>Rack</th>
                    <td><?= htmlspecialchars($holdingcage['rack'] ?? ''); ?></td>
                </tr>
                <tr>
                    <th>Strain</th>
                    <td id="strain-data" data-value="<?= !empty($holdingcage['str_id']) && $holdingcage['str_id'] !== 'NA' ? '1' : ''; ?>">
                        <a href="javascript:void(0);" onclick="viewStrainDetails(<?= htmlspecialchars(json_encode($holdingcage['str_id'] ?? 'NA')) ?>, <?= htmlspecialchars(json_encode($holdingcage['str_name'] ?? 'Unknown Name')) ?>, <?= htmlspecialchars(json_encode($holdingcage['str_aka'] ?? '')) ?>, <?= htmlspecialchars(json_encode($holdingcage['str_url'] ?? '#')) ?>, <?= htmlspecialchars(json_encode($holdingcage['str_rrid'] ?? '')) ?>, <?= htmlspecialchars(json_encode($holdingcage['str_notes'] ?? '')) ?>)">
                            <?= htmlspecialchars($holdingcage['str_id'] ?? 'NA'); ?> | <?= htmlspecialchars($holdingcage['str_name'] ?? 'Unknown Name'); ?>
                        </a>
                    </td>
                </tr>
                <tr>
                    <th>IACUC</th>
                    <td id="iacuc-data" data-value="<?= !empty($iacucCodes) ? '1' : ''; ?>"><?= $iacucDisplayString; ?></td>
                </tr>
                <tr>
                    <th>User</th>
                    <td id="user-data" data-value="<?= !empty($userIds) ? '1' : ''; ?>"><?= $userDisplayString; ?></td>
                </tr>
                <tr>
                    <th>Qty</th>
                    <td><?= htmlspecialchars($holdingcage['quantity']); ?></td>
                </tr>
                <tr>
                    <th>DOB</th>
                    <td id="dob-data" data-value="<?= !empty($holdingcage['dob']) ? '1' : ''; ?>"><?= htmlspecialchars($holdingcage['dob']); ?></td>
                </tr>
                <tr>
                    <th>Age</th>
                    <td><?= htmlspecialchars($ageString); ?></td>
                </tr>
                <tr>
                    <th>Sex</th>
                    <td id="sex-data" data-value="<?= !empty($holdingcage['sex']) ? '1' : ''; ?>"><?= htmlspecialchars(ucfirst($holdingcage['sex'])); ?></td>
                </tr>
                <tr>
                    <th>Parent Cage</th>
                    <td id="parent-data" data-value="<?= !empty($holdingcage['parent_cg']) ? '1' : ''; ?>"><?= htmlspecialchars($holdingcage['parent_cg']); ?></td>
                </tr>
                <tr>
                    <th>Genotype</th>
                    <td><?= htmlspecialchars($holdingcage['genotype'] ?? ''); ?></td>
                </tr>
                <tr>
                    <th>Remarks</th>
                    <td><?= htmlspecialchars($holdingcage['remarks']); ?></td>
                </tr>
            </table>
        </div>

        <!-- Mice Section -->
        <?php if (!empty($mice)) : ?>
            <div class="section-card">
                <div class="section-header">
                    <i class="fas fa-paw"></i>
                    <h5>Mice (<?= count($mice); ?>)</h5>
                </div>
                <?php foreach ($mice as $index => $mouse) : ?>
                    <h6 class="mouse-heading">Mouse #<?= $index + 1; ?></h6>
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Mouse ID</th>
                                    <th>Genotype</th>
                                    <th>Notes</th>
                                    <th style="width: 80px;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td><?= htmlspecialchars($mouse['mouse_id']); ?></td>
                                    <td><?= htmlspecialchars($mouse['genotype']); ?></td>
                                    <td><?= htmlspecialchars($mouse['notes']); ?></td>
                                    <td>
                                        <div class="action-buttons">
                                            <button class="btn btn-sm btn-info" onclick="openTransferModal(<?= (int)$mouse['id']; ?>, <?= htmlspecialchars(json_encode($mouse['mouse_id'])); ?>)" title="Transfer Mouse">
                                                <i class="fas fa-exchange-alt"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

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
                        while ($file = $files->fetch_assoc()) {
                            $hasFiles = true;
                            $file_path = htmlspecialchars($file['file_path']);
                            $file_name = htmlspecialchars($file['file_name']);
                            echo "<tr>";
                            echo "<td>$file_name</td>";
                            echo "<td><div class='action-buttons'><a href='$file_path' download='$file_name' class='btn btn-sm btn-primary' title='Download'><i class='fas fa-cloud-download-alt'></i></a></div></td>";
                            echo "</tr>";
                        }
                        if (!$hasFiles) {
                            echo "<tr><td colspan='2' class='text-muted text-center'>No files uploaded</td></tr>";
                        }
                        ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Maintenance Log Section -->
        <div class="section-card">
            <div class="section-header">
                <i class="fas fa-clipboard-list"></i>
                <h5>Maintenance Log</h5>
                <div class="action-buttons">
                    <a href="maintenance.php?from=hc_dash" class="btn btn-warning btn-sm" data-bs-toggle="tooltip" data-bs-placement="top" title="Add Maintenance Record">
                        <i class="fas fa-wrench"></i>
                    </a>
                    <a href="hc_edit.php?id=<?= rawurlencode($holdingcage['cage_id']); ?>" class="btn btn-warning btn-sm" data-bs-toggle="tooltip" data-bs-placement="top" title="Edit Cage">
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

    <br>
    <?php include 'footer.php'; ?>

    <!-- Popup form for viewing strain details -->
    <div class="popup-overlay" id="viewPopupOverlay" onclick="closeViewForm()"></div>
    <div class="popup-form" id="viewPopupForm">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h4 id="viewFormTitle" class="mb-0">Strain Details</h4>
            <button type="button" class="btn-close" onclick="closeViewForm()" aria-label="Close"></button>
        </div>
        <div class="form-group">
            <strong for="view_strain_id">Strain ID:</strong>
            <p id="view_strain_id"></p>
        </div>
        <div class="form-group">
            <strong for="view_strain_name">Strain Name:</strong>
            <p id="view_strain_name"></p>
        </div>
        <div class="form-group">
            <strong for="view_strain_aka">Common Names:</strong>
            <p id="view_strain_aka"></p>
        </div>
        <div class="form-group">
            <strong for="view_strain_url">Strain URL:</strong>
            <p><a href="#" id="view_strain_url" target="_blank"></a></p>
        </div>
        <div class="form-group">
            <strong for="view_strain_rrid">Strain RRID:</strong>
            <p id="view_strain_rrid"></p>
        </div>
        <div class="form-group">
            <strong for="view_strain_notes">Notes:</strong>
            <p id="view_strain_notes"></p>
        </div>
        <div class="form-buttons">
            <button type="button" class="btn btn-secondary" onclick="closeViewForm()">Close</button>
        </div>
    </div>

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

    <!-- Mouse Transfer Modal -->
    <div class="popup-overlay" id="transferOverlay" onclick="closeTransferModal()"></div>
    <div class="popup-form" id="transferForm" style="max-width: 500px;">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h4 class="mb-0">Transfer Mouse</h4>
            <button type="button" class="btn-close" onclick="closeTransferModal()" aria-label="Close"></button>
        </div>
        <form method="POST" action="mouse_transfer.php">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
            <input type="hidden" id="transfer_mouse_db_id" name="mouse_db_id">
            <input type="hidden" name="source_cage_id" value="<?= htmlspecialchars($holdingcage['cage_id']); ?>">
            <div class="mb-3">
                <label class="form-label"><strong>Mouse ID:</strong></label>
                <p id="transfer_mouse_id_display"></p>
            </div>
            <div class="mb-3">
                <label for="target_cage_id" class="form-label"><strong>Transfer to Cage:</strong></label>
                <?php
                $targetCageQuery = "SELECT cage_id FROM cages WHERE status = 'active' AND cage_id != ? ORDER BY cage_id";
                $targetStmt = $con->prepare($targetCageQuery);
                $targetStmt->bind_param("s", $id);
                $targetStmt->execute();
                $targetResult = $targetStmt->get_result();
                $targetCages = [];
                while ($targetRow = $targetResult->fetch_assoc()) {
                    $targetCages[] = $targetRow['cage_id'];
                }
                $targetStmt->close();
                ?>
                <?php if (!empty($targetCages)) : ?>
                    <select class="form-control" id="target_cage_id" name="target_cage_id" required>
                        <option value="">Select Target Cage</option>
                        <?php foreach ($targetCages as $cageId) : ?>
                            <option value="<?= htmlspecialchars($cageId); ?>"><?= htmlspecialchars($cageId); ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php else : ?>
                    <div class="alert alert-warning mb-0">No other active cages available for transfer.</div>
                <?php endif; ?>
            </div>
            <div class="d-flex gap-2">
                <?php if (!empty($targetCages)) : ?>
                    <button type="submit" class="btn btn-primary">Transfer</button>
                <?php endif; ?>
                <button type="button" class="btn btn-secondary" onclick="closeTransferModal()">Cancel</button>
            </div>
        </form>
    </div>

    <script>
        // Information Completeness Calculation
        document.addEventListener('DOMContentLoaded', function() {
            const fields = {
                critical: ['dob', 'sex'],
                important: ['pi', 'strain', 'iacuc', 'user'],
                useful: ['parent']
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
                    const label = fieldId.charAt(0).toUpperCase() + fieldId.slice(1);
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
                    const label = 'Parent Cage';
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
</body>

</html>