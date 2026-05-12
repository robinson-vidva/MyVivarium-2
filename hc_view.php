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

    // If this cage_id belongs to a breeding cage, hand off to bc_view.
    // Lets every "view cage" link point at hc_view safely, regardless of
    // which kind of cage the mouse is actually in.
    $bcCheck = $con->prepare("SELECT 1 FROM breeding WHERE cage_id = ? LIMIT 1");
    $bcCheck->bind_param("s", $id);
    $bcCheck->execute();
    if ($bcCheck->get_result()->num_rows > 0) {
        $bcCheck->close();
        header("Location: bc_view.php?id=" . urlencode($id));
        exit;
    }
    $bcCheck->close();

    // v2: cage is the canonical container; mice are independent. We pull cage
    // metadata directly from `cages` and aggregate mouse-level facts (strain,
    // sex, dob) from the mice currently in the cage.
    $query = "SELECT c.cage_id,
                     pi.initials AS pi_initials, pi.name AS pi_name,
                     c.quantity, c.remarks, c.room, c.rack,
                     (SELECT COUNT(*) FROM mice mi WHERE mi.current_cage_id = c.cage_id AND mi.status = 'alive') AS live_count,
                     (SELECT MIN(mi.dob) FROM mice mi WHERE mi.current_cage_id = c.cage_id AND mi.status = 'alive') AS dob,
                     (SELECT GROUP_CONCAT(DISTINCT mi.sex SEPARATOR ', ') FROM mice mi WHERE mi.current_cage_id = c.cage_id AND mi.status = 'alive') AS sex,
                     (SELECT GROUP_CONCAT(DISTINCT mi.strain SEPARATOR ', ') FROM mice mi WHERE mi.current_cage_id = c.cage_id AND mi.status = 'alive' AND mi.strain IS NOT NULL) AS strain_ids,
                     s.str_id, s.str_name, s.str_aka, s.str_url, s.str_rrid, s.str_notes
              FROM cages c
              LEFT JOIN users pi   ON c.pi_name = pi.id
              LEFT JOIN strains s  ON s.str_id = (SELECT mi.strain FROM mice mi WHERE mi.current_cage_id = c.cage_id AND mi.strain IS NOT NULL LIMIT 1)
              WHERE c.cage_id = ?";
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
            // No PI assigned — that's fine in v2, just display NA placeholders.
            $holdingcage['pi_initials'] = 'NA';
            $holdingcage['pi_name'] = 'NA';
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

        // v2: fetch mice as first-class entities currently assigned to this cage
        $mouseQuery = "SELECT mouse_id, sex, dob, genotype, ear_code, status, notes
                         FROM mice WHERE current_cage_id = ?
                         ORDER BY status = 'alive' DESC, mouse_id ASC";
        $stmtMouse = $con->prepare($mouseQuery);
        $stmtMouse->bind_param("s", $id);
        $stmtMouse->execute();
        $mouseResult = $stmtMouse->get_result();
        $mice = mysqli_fetch_all($mouseResult, MYSQLI_ASSOC);
        $stmtMouse->close();

        // Fetch target cages for mouse transfer
        $targetCageQuery = "SELECT cage_id FROM cages WHERE status = 'active' AND cage_id != ? ORDER BY cage_id";
        $targetStmt = $con->prepare($targetCageQuery);
        $targetCages = [];
        if ($targetStmt) {
            $targetStmt->bind_param("s", $id);
            $targetStmt->execute();
            $targetResult = $targetStmt->get_result();
            while ($targetRow = $targetResult->fetch_assoc()) {
                $targetCages[] = $targetRow['cage_id'];
            }
            $targetStmt->close();
        }

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
    // Bail out early on an empty cage_users list — otherwise the `IN ()`
    // clause and the zero-length bind_param both throw fatals. v2 cages
    // can legitimately have no users (e.g. one created via the inline
    // "+ Add new cage" modal from mouse_addn).
    if (empty($userIds)) {
        return [];
    }
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

        .section-header > i {
            color: var(--bs-primary);
            width: 22px;
            text-align: center;
        }

        .section-header h5 {
            margin: 0;
        }

        .section-header .action-buttons {
            margin-left: auto;
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
        }

        /* Mobile layout for view pages */
        @media (max-width: 576px) {
            /* Stack title and buttons */
            .section-header {
                flex-wrap: wrap;
            }
            .section-header h5 {
                flex: 1 1 100%;
                margin-bottom: 8px;
            }
            .section-header .action-buttons {
                margin-left: 0;
                width: 100%;
                justify-content: flex-start;
            }

            /* Make details-table match the card style of data-label tables */
            .details-table th,
            .details-table td {
                display: block;
                width: 100% !important;
                padding: 4px 14px;
                border-bottom: none;
            }
            .details-table th {
                padding-top: 10px;
                padding-bottom: 0;
                font-size: 0.75rem;
                text-transform: uppercase;
                letter-spacing: 0.05em;
                color: var(--bs-secondary-color);
                font-weight: 700;
            }
            .details-table td {
                padding-bottom: 10px;
                border-bottom: 1px solid var(--bs-border-color);
                color: var(--bs-body-color);
            }
            .details-table tr:last-child td {
                border-bottom: none;
            }
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
        }

        .details-table th {
            width: 35%;
            font-weight: 600;
            color: var(--bs-body-color);
            background-color: var(--bs-tertiary-bg);
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

        // openTransferModal lives further down the page near the actual
        // modal element — these v1-era stubs (with a different signature
        // and pointing at DOM ids that no longer exist) are removed so
        // they can't shadow the live function.
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
                    <a href="javascript:void(0);" onclick="goBack()" class="btn btn-secondary btn-sm" data-bs-toggle="tooltip" data-bs-placement="top" title="Go Back">
                        <i class="fas fa-arrow-circle-left"></i>
                    </a>
                    <a href="hc_edit.php?id=<?= rawurlencode($holdingcage['cage_id']); ?>" class="btn btn-warning btn-sm" data-bs-toggle="tooltip" data-bs-placement="top" title="Edit Cage">
                        <i class="fas fa-edit"></i>
                    </a>
                    <a href="hc_addn.php?clone=<?= rawurlencode($holdingcage['cage_id']); ?>" class="btn btn-success btn-sm" data-bs-toggle="tooltip" data-bs-placement="top" title="Duplicate Cage">
                        <i class="fas fa-clone"></i>
                    </a>
                    <a href="manage_tasks.php?id=<?= rawurlencode($holdingcage['cage_id']); ?>" class="btn btn-info btn-sm" data-bs-toggle="tooltip" data-bs-placement="top" title="Manage Tasks">
                        <i class="fas fa-tasks"></i>
                    </a>
                    <a href="javascript:void(0);" onclick="showQrCodePopup(<?= htmlspecialchars(json_encode($holdingcage['cage_id'])); ?>)" class="btn btn-primary btn-sm" data-bs-toggle="tooltip" data-bs-placement="top" title="QR Code">
                        <i class="fas fa-qrcode"></i>
                    </a>
                    <a href="javascript:void(0);" onclick="window.print()" class="btn btn-secondary btn-sm" data-bs-toggle="tooltip" data-bs-placement="top" title="Print Cage">
                        <i class="fas fa-print"></i>
                    </a>
                </div>
            </div>

            <!--
                v1 had a per-cage "Information Completeness" widget that
                graded each cage on dob/sex/strain/parent_cg as if those
                were cage-level. In v2 those facts live on the mouse
                entity, so this widget's premise is gone — removed. Per-
                mouse completeness can be reintroduced on mouse_view if
                we want something similar in v2.
            -->


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
                    <td>
                        <?php if (!empty($holdingcage['str_id']) && $holdingcage['str_id'] !== 'NA'): ?>
                            <a href="javascript:void(0);" onclick="viewStrainDetails(<?= htmlspecialchars(json_encode($holdingcage['str_id'])) ?>, <?= htmlspecialchars(json_encode($holdingcage['str_name'] ?? 'Unknown Name')) ?>, <?= htmlspecialchars(json_encode($holdingcage['str_aka'] ?? '')) ?>, <?= htmlspecialchars(json_encode($holdingcage['str_url'] ?? '#')) ?>, <?= htmlspecialchars(json_encode($holdingcage['str_rrid'] ?? '')) ?>, <?= htmlspecialchars(json_encode($holdingcage['str_notes'] ?? '')) ?>)">
                                <?= htmlspecialchars($holdingcage['str_id']); ?> | <?= htmlspecialchars($holdingcage['str_name'] ?? 'Unknown Name'); ?>
                            </a>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
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
                <!-- v2: parent_cg and genotype were cage-level in v1; in v2
                     they're per-mouse (sire/dam_id, mice.genotype). The Mice
                     section below renders that data. -->
                <tr>
                    <th>Remarks</th>
                    <td><?= htmlspecialchars($holdingcage['remarks']); ?></td>
                </tr>
            </table>
        </div>

        <!-- Mice Section (v2: first-class entities currently in this cage) -->
        <div class="section-card">
            <div class="section-header">
                <i class="fas fa-paw"></i>
                <h5>Mice (<?= count($mice); ?>)</h5>
                <div class="action-buttons">
                    <a href="mouse_addn.php?cage_id=<?= rawurlencode($holdingcage['cage_id']); ?>" class="btn btn-primary btn-sm" title="Register Mouse in this Cage">
                        <i class="fas fa-plus"></i>
                    </a>
                </div>
            </div>
            <?php if (!empty($mice)) : ?>
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Mouse ID</th>
                                <th>Sex</th>
                                <th>DOB</th>
                                <th>Genotype</th>
                                <th>Status</th>
                                <th style="width: 130px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($mice as $mouse):
                                $statusBadge = [
                                    'alive'           => 'bg-success',
                                    'sacrificed'      => 'bg-secondary',
                                    'archived'        => 'bg-dark',
                                    'transferred_out' => 'bg-warning text-dark',
                                ][$mouse['status']] ?? 'bg-light text-dark';
                            ?>
                                <tr>
                                    <td data-label="Mouse ID"><a href="mouse_view.php?id=<?= rawurlencode($mouse['mouse_id']); ?>"><strong><?= htmlspecialchars($mouse['mouse_id']); ?></strong></a></td>
                                    <td data-label="Sex"><?= htmlspecialchars(ucfirst($mouse['sex'])); ?></td>
                                    <td data-label="DOB"><?= htmlspecialchars($mouse['dob'] ?? '—'); ?></td>
                                    <td data-label="Genotype"><?= htmlspecialchars($mouse['genotype'] ?? ''); ?></td>
                                    <td data-label="Status"><span class="badge <?= $statusBadge; ?>"><?= htmlspecialchars($mouse['status']); ?></span></td>
                                    <td data-label="Actions">
                                        <div class="action-buttons">
                                            <a href="mouse_view.php?id=<?= rawurlencode($mouse['mouse_id']); ?>" class="btn btn-sm btn-info" title="View"><i class="fas fa-eye"></i></a>
                                            <a href="mouse_edit.php?id=<?= rawurlencode($mouse['mouse_id']); ?>" class="btn btn-sm btn-warning" title="Edit"><i class="fas fa-edit"></i></a>
                                            <?php if ($mouse['status'] === 'alive'): ?>
                                                <button type="button" class="btn btn-sm btn-secondary" onclick="openTransferModal(<?= htmlspecialchars(json_encode($mouse['mouse_id'])); ?>)" title="Transfer to another cage"><i class="fas fa-exchange-alt"></i></button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else : ?>
                <p class="text-muted mb-0">No mice in this cage. <a href="mouse_addn.php?cage_id=<?= rawurlencode($holdingcage['cage_id']); ?>">Register the first one</a>.</p>
            <?php endif; ?>
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
                        while ($file = $files->fetch_assoc()) {
                            $hasFiles = true;
                            $file_path = htmlspecialchars($file['file_path']);
                            $file_name = htmlspecialchars($file['file_name']);
                            echo "<tr>";
                            echo "<td data-label='File Name'>$file_name</td>";
                            echo "<td data-label='Actions'><div class='action-buttons'><a href='$file_path' download='$file_name' class='btn btn-sm btn-primary' title='Download'><i class='fas fa-cloud-download-alt'></i></a></div></td>";
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
                                    <td data-label="Date"><?= htmlspecialchars($log['timestamp'] ?? ''); ?></td>
                                    <td data-label="User"><?= htmlspecialchars($log['user_name'] ?? 'Unknown'); ?></td>
                                    <td data-label="Comment"><?= htmlspecialchars($log['comments'] ?? 'No comment'); ?></td>
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
        <div class="mb-3">
            <strong>Strain ID:</strong>
            <p id="view_strain_id" class="mb-0"></p>
        </div>
        <div class="mb-3">
            <strong>Strain Name:</strong>
            <p id="view_strain_name" class="mb-0"></p>
        </div>
        <div class="mb-3">
            <strong>Common Names:</strong>
            <p id="view_strain_aka" class="mb-0"></p>
        </div>
        <div class="mb-3">
            <strong>Strain URL:</strong>
            <p class="mb-0"><a href="#" id="view_strain_url" target="_blank"></a></p>
        </div>
        <div class="mb-3">
            <strong>Strain RRID:</strong>
            <p id="view_strain_rrid" class="mb-0"></p>
        </div>
        <div class="mb-3">
            <strong>Notes:</strong>
            <p id="view_strain_notes" class="mb-0"></p>
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

    <!-- Transfer Mouse Modal — posts to mouse_move.php so the cage-history log stays consistent. -->
    <div class="modal fade" id="transferModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog">
        <div class="modal-content">
          <form method="POST" action="mouse_move.php">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']); ?>">
            <input type="hidden" name="redirect_to" value="hc_view.php?id=<?= rawurlencode($holdingcage['cage_id']); ?>">
            <input type="hidden" id="transfer_mouse_id" name="mouse_id" value="">
            <div class="modal-header">
              <h5 class="modal-title">Transfer mouse <span id="transfer_mouse_id_display" class="text-muted"></span></h5>
              <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
              <div class="mb-3">
                <label class="form-label">Target Cage</label>
                <?php if (!empty($targetCages)): ?>
                  <select class="form-control" name="target_cage_id" required>
                    <option value="">Select cage…</option>
                    <option value="__none__">— No cage (remove from cage) —</option>
                    <?php foreach ($targetCages as $cageId): ?>
                      <option value="<?= htmlspecialchars($cageId); ?>"><?= htmlspecialchars($cageId); ?></option>
                    <?php endforeach; ?>
                  </select>
                <?php else: ?>
                  <div class="alert alert-warning mb-0">No other active cages available — create a holding or breeding cage first.</div>
                <?php endif; ?>
              </div>
              <div class="mb-3">
                <label class="form-label">Reason <small class="text-muted">(optional)</small></label>
                <input type="text" name="reason" class="form-control" placeholder="e.g. weaning, surgery, breeding setup">
              </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
              <?php if (!empty($targetCages)): ?>
                <button type="submit" class="btn btn-primary">Transfer</button>
              <?php endif; ?>
            </div>
          </form>
        </div>
      </div>
    </div>

    <script>
    function openTransferModal(mouseId) {
        document.getElementById('transfer_mouse_id').value = mouseId;
        document.getElementById('transfer_mouse_id_display').textContent = mouseId;
        new bootstrap.Modal(document.getElementById('transferModal')).show();
    }
    </script>


    <!-- Completeness widget script removed with its HTML (v1 cage-level
         grading no longer applies to v2's mouse-level model). -->
</body>

</html>