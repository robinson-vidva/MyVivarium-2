<?php

/**
 * Unified Printable Cage Cards
 *
 * This script generates a printable view of cage cards for both holding and breeding cages.
 * It accepts mixed cage IDs, determines each cage's type by checking which table it exists in,
 * then renders the appropriate card format. Cards are arranged in a 2x2 grid layout (5in x 3in each)
 * on letter landscape paper, with automatic page breaks every 4 cages.
 */

// Start a new session or resume the existing session
require 'session_config.php';

// Include the database connection file
require 'dbcon.php';

// Check if the user is logged in
if (!isset($_SESSION['username'])) {
    $currentUrl = urlencode($_SERVER['REQUEST_URI']);
    header("Location: index.php?redirect=$currentUrl");
    exit;
}

// Query to get lab data (URL) from the settings table
$labQuery = "SELECT value FROM settings WHERE name = 'url' LIMIT 1";
$labResult = mysqli_query($con, $labQuery);
$url = "";
if ($row = mysqli_fetch_assoc($labResult)) {
    $url = $row['value'];
}

// Helper function to get user initials by cage ID
function getUserInitialsByCageId($con, $cageId)
{
    $query = "SELECT u.initials
              FROM users u
              INNER JOIN cage_users cu ON u.id = cu.user_id
              WHERE cu.cage_id = ?";
    $stmt = $con->prepare($query);
    $stmt->bind_param("s", $cageId);
    $stmt->execute();
    $result = $stmt->get_result();
    $userInitials = [];
    while ($row = $result->fetch_assoc()) {
        $userInitials[] = htmlspecialchars($row['initials']);
    }
    $stmt->close();
    return $userInitials;
}

// Helper function to get IACUC IDs by cage ID
function getIacucIdsByCageId($con, $cageId)
{
    $query = "SELECT i.iacuc_id FROM cage_iacuc ci
              LEFT JOIN iacuc i ON ci.iacuc_id = i.iacuc_id
              WHERE ci.cage_id = ?";
    $stmt = $con->prepare($query);
    $stmt->bind_param("s", $cageId);
    $stmt->execute();
    $result = $stmt->get_result();
    $iacucIds = [];
    while ($row = $result->fetch_assoc()) {
        $iacucIds[] = $row['iacuc_id'];
    }
    $stmt->close();
    return implode(', ', $iacucIds);
}

// Check if the ID parameter is set in the URL
if (!isset($_GET['id'])) {
    $_SESSION['message'] = 'ID parameter is missing.';
    header("Location: slct_crd.php");
    exit();
}

$ids = explode(',', $_GET['id']);
$cages = []; // Array of ['type' => 'holding'|'breeding', 'data' => [...]]

foreach ($ids as $id) {
    $id = trim($id);

    // Check if it's a holding cage
    $hcQuery = "SELECT h.*, pi.name AS pi_name, c.quantity as qty, c.room, c.rack, h.dob, h.sex, h.parent_cg, s.str_name
                FROM holding h
                LEFT JOIN cages c ON h.cage_id = c.cage_id
                LEFT JOIN users pi ON c.pi_name = pi.id
                LEFT JOIN strains s ON h.strain = s.str_id
                WHERE h.cage_id = ?";
    $stmt = $con->prepare($hcQuery);
    $stmt->bind_param("s", $id);
    $stmt->execute();
    $hcResult = $stmt->get_result();

    if ($hcResult->num_rows === 1) {
        $cage = $hcResult->fetch_assoc();
        $stmt->close();

        // Fetch mouse data for this cage, limit to first 5 records
        $mouseQuery = "SELECT mouse_id, genotype FROM mice WHERE cage_id = ? LIMIT 5";
        $stmtMouse = $con->prepare($mouseQuery);
        $stmtMouse->bind_param("s", $id);
        $stmtMouse->execute();
        $mouseResult = $stmtMouse->get_result();
        $cage['mice'] = mysqli_fetch_all($mouseResult, MYSQLI_ASSOC);
        $stmtMouse->close();

        // Fetch IACUC data
        $cage['iacuc'] = getIacucIdsByCageId($con, $id);
        if (empty($cage['iacuc'])) $cage['iacuc'] = 'N/A';

        // Fetch user initials
        $cage['user_initials'] = implode(', ', getUserInitialsByCageId($con, $id));

        $cages[] = ['type' => 'holding', 'data' => $cage];
        continue;
    }
    $stmt->close();

    // Check if it's a breeding cage
    $bcQuery = "SELECT b.*, c.remarks AS remarks, c.room, c.rack, pi.name AS pi_name
                FROM breeding b
                LEFT JOIN cages c ON b.cage_id = c.cage_id
                LEFT JOIN users pi ON c.pi_name = pi.id
                WHERE b.cage_id = ?";
    $stmt = $con->prepare($bcQuery);
    $stmt->bind_param("s", $id);
    $stmt->execute();
    $bcResult = $stmt->get_result();

    if ($bcResult->num_rows === 1) {
        $cage = $bcResult->fetch_assoc();
        $stmt->close();

        // Fetch the latest 5 litter records
        $litterQuery = "SELECT * FROM litters WHERE cage_id = ? ORDER BY dom DESC LIMIT 5";
        $stmtLitter = $con->prepare($litterQuery);
        $stmtLitter->bind_param("s", $id);
        $stmtLitter->execute();
        $litterResult = $stmtLitter->get_result();
        $cage['litters'] = [];
        while ($litter = $litterResult->fetch_assoc()) {
            $cage['litters'][] = $litter;
        }
        $stmtLitter->close();

        // Fetch user initials and IACUC
        $cage['user_initials'] = implode(', ', getUserInitialsByCageId($con, $id));
        $cage['iacuc'] = getIacucIdsByCageId($con, $id);

        $cages[] = ['type' => 'breeding', 'data' => $cage];
        continue;
    }
    $stmt->close();

    // If cage not found in either table
    $_SESSION['message'] = "Invalid ID: " . htmlspecialchars($id);
    header("Location: slct_crd.php");
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <title>Printable 2x2 Card Table</title>
    <style>
        @page {
            size: letter landscape;
            margin: 0;
            padding: 0;
        }

        @media print {
            body {
                margin: 0;
                color: #000;
            }

            .page-break {
                page-break-after: always;
            }
        }

        body,
        html {
            margin: 0;
            padding: 0;
            width: 100%;
            height: 100%;
            box-sizing: border-box;
        }

        span {
            font-size: 8pt;
            padding: 0px;
            line-height: 1;
            display: inline-block;
        }

        table {
            box-sizing: border-box;
            border-collapse: collapse;
            margin: 0;
            padding: 0;
            border-spacing: 0;
        }

        table.cageA tr td,
        table.cageB tr td {
            border: 1px solid black;
            box-sizing: border-box;
            border-collapse: collapse;
            margin: 0;
            padding: 0;
            border-spacing: 0;
        }

        table.cageB tr:first-child td {
            border-top: none;
        }
    </style>
</head>

<body>
    <div id="printArea">
    <?php
    $totalCages = count($cages);
    $totalPages = ceil($totalCages / 4);
    for ($pageNum = 0; $pageNum < $totalPages; $pageNum++) :
        $pageStart = $pageNum * 4;
        $pageCages = array_slice($cages, $pageStart, 4);
    ?>
    <div style="display: grid; place-items: center; width: 100%; height: 100%;<?= $pageNum < $totalPages - 1 ? '' : '' ?>"<?= $pageNum < $totalPages - 1 ? ' class="page-break"' : '' ?>>
    <table style="width: 10in; height: 6in; border-collapse: collapse; border: 1px dashed #D3D3D3;">
        <?php foreach ($pageCages as $index => $cageEntry) :
            $type = $cageEntry['type'];
            $cage = $cageEntry['data'];
        ?>

            <?php if ($index % 2 === 0) : ?>
                <tr style="height: 3in; border: 1px dashed #D3D3D3; vertical-align:top;">
            <?php endif; ?>

                <td style="width: 5in; border: 1px dashed #D3D3D3;">

                    <?php if ($type === 'holding') : ?>
                        <!-- HOLDING CAGE CARD -->
                        <table border="1" style="width: 5in; height: 1.5in;" class="cageA">
                            <tr>
                                <td colspan="3" style="width: 100%; text-align:center;">
                                    <span style="font-weight: bold; font-size: 10pt; text-transform: uppercase; padding:3px;">
                                        Holding Cage - # <?= $cage["cage_id"] ?> </span>
                                </td>
                            </tr>
                            <tr>
                                <td style="width:40%;">
                                    <span style="font-weight: bold; padding:3px; text-transform: uppercase;">PI Name:</span>
                                    <span><?= htmlspecialchars($cage["pi_name"]); ?></span>
                                </td>
                                <td style="width:40%;">
                                    <span style="font-weight: bold; padding:3px; text-transform: uppercase;">Strain:</span>
                                    <span><?= htmlspecialchars($cage["strain"]); ?></span>
                                </td>
                                <td rowspan="5" style="width:20%; text-align:center;">
                                    <img src="<?php echo "https://api.qrserver.com/v1/create-qr-code/?size=75x75&data=https://" . $url . "/hc_view.php?id=" . $cage["cage_id"] . "&choe=UTF-8"; ?>" alt="QR Code">
                                </td>
                            </tr>
                            <tr>
                                <td style="width:40%;">
                                    <span style="font-weight: bold; padding:3px; text-transform: uppercase;">IACUC:</span>
                                    <span><?= htmlspecialchars($cage["iacuc"]); ?></span>
                                </td>
                                <td style="width:40%;">
                                    <span style="font-weight: bold; padding:3px; text-transform: uppercase;">User:</span>
                                    <span><?= htmlspecialchars($cage['user_initials']); ?></span>
                                </td>
                            </tr>
                            <tr>
                                <td style="width:40%;">
                                    <span style="font-weight: bold; padding:3px; text-transform: uppercase;">Qty:</span>
                                    <span><?= htmlspecialchars($cage["qty"]); ?></span>
                                </td>
                                <td style="width:40%;">
                                    <span style="font-weight: bold; padding:3px; text-transform: uppercase;">DOB:</span>
                                    <span><?= htmlspecialchars($cage["dob"]); ?></span>
                                </td>
                            </tr>
                            <tr>
                                <td style="width:40%;">
                                    <span style="font-weight: bold; padding:3px; text-transform: uppercase;">Sex:</span>
                                    <span><?= htmlspecialchars(ucfirst($cage["sex"] ?? '')); ?></span>
                                </td>
                                <td style="width:40%;">
                                    <span style="font-weight: bold; padding:3px; text-transform: uppercase;">Parent Cage:</span>
                                    <span><?= htmlspecialchars($cage["parent_cg"] ?? ''); ?></span>
                                </td>
                            </tr>
                            <tr style="border-bottom: none;">
                                <td style="width:40%;">
                                    <span style="font-weight: bold; padding:3px; text-transform: uppercase;">Room:</span>
                                    <span><?= htmlspecialchars($cage["room"] ?? ''); ?></span>
                                </td>
                                <td style="width:40%;">
                                    <span style="font-weight: bold; padding:3px; text-transform: uppercase;">Rack:</span>
                                    <span><?= htmlspecialchars($cage["rack"] ?? ''); ?></span>
                                </td>
                            </tr>
                        </table>
                        <table border="1" style="width: 5in; height: 1.5in; border-top: none;" class="cageB">
                            <tr>
                                <td style="width:40%;">
                                    <span style="font-weight: bold; padding:3px; text-transform: uppercase; border-top: none; text-align:center;">Mouse ID</span>
                                </td>
                                <td style="width:60%;">
                                    <span style="font-weight: bold; padding:3px; text-transform: uppercase; border-top: none; text-align:center;">Genotype</span>
                                </td>
                            </tr>
                            <?php foreach (range(1, 5) as $i) : ?>
                                <tr>
                                    <td style="width:40%; padding:3px;">
                                        <span><?= isset($cage['mice'][$i - 1]['mouse_id']) ? htmlspecialchars($cage['mice'][$i - 1]['mouse_id']) : '' ?></span>
                                    </td>
                                    <td style="width:60%; padding:3px;">
                                        <span><?= isset($cage['mice'][$i - 1]['genotype']) ? htmlspecialchars($cage['mice'][$i - 1]['genotype']) : '' ?></span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </table>

                    <?php else : ?>
                        <!-- BREEDING CAGE CARD -->
                        <table border="1" style="width: 5in; height: 1.5in;" class="cageA">
                            <tr>
                                <td colspan="3" style="width: 100%; text-align:center;">
                                    <span style="font-weight: bold; font-size: 10pt; text-transform: uppercase; padding:3px;">
                                        Breeding Cage - # <?= $cage["cage_id"] ?> </span>
                                </td>
                            </tr>
                            <tr>
                                <td style="width:40%;">
                                    <span style="font-weight: bold; padding:3px; text-transform: uppercase;">PI Name:</span>
                                    <span><?= htmlspecialchars($cage["pi_name"]); ?></span>
                                </td>
                                <td style="width:40%;">
                                    <span style="font-weight: bold; padding:3px; text-transform: uppercase;">Cross:</span>
                                    <span><?= htmlspecialchars($cage["cross"] ?? '') ?></span>
                                </td>
                                <td rowspan="5" style="width:20%; text-align:center;">
                                    <img src="<?php echo "https://api.qrserver.com/v1/create-qr-code/?size=75x75&data=https://" . $url . "/bc_view.php?id=" . $cage["cage_id"] . "&choe=UTF-8"; ?>" alt="QR Code">
                                </td>
                            </tr>
                            <tr>
                                <td style="width:40%;">
                                    <span style="font-weight: bold; padding:3px; text-transform: uppercase;">IACUC:</span>
                                    <span><?= htmlspecialchars($cage['iacuc']); ?></span>
                                </td>
                                <td style="width:40%;">
                                    <span style="font-weight: bold; padding:3px; text-transform: uppercase;">User:</span>
                                    <span><?= $cage['user_initials']; ?></span>
                                </td>
                            </tr>
                            <tr>
                                <td style="width:40%;">
                                    <span style="font-weight: bold; padding:3px; text-transform: uppercase;">Room:</span>
                                    <span><?= htmlspecialchars($cage["room"] ?? '') ?></span>
                                </td>
                                <td style="width:40%;">
                                    <span style="font-weight: bold; padding:3px; text-transform: uppercase;">Rack:</span>
                                    <span><?= htmlspecialchars($cage["rack"] ?? '') ?></span>
                                </td>
                            </tr>
                            <tr>
                                <td style="width:40%;">
                                    <span style="font-weight: bold; padding:3px; text-transform: uppercase;">Male ID:</span>
                                    <span><?= htmlspecialchars($cage["male_id"] ?? '') ?></span>
                                </td>
                                <td style="width:40%;">
                                    <span style="font-weight: bold; padding:3px; text-transform: uppercase;">Male DOB:</span>
                                    <span><?= htmlspecialchars($cage["male_dob"] ?? '') ?></span>
                                </td>
                            </tr>
                            <tr style="border-bottom: none;">
                                <td style="width:40%;">
                                    <span style="font-weight: bold; padding:3px; text-transform: uppercase;">Female ID:</span>
                                    <span><?= htmlspecialchars($cage["female_id"] ?? '') ?></span>
                                </td>
                                <td style="width:40%;">
                                    <span style="font-weight: bold; padding:3px; text-transform: uppercase;">Female DOB:</span>
                                    <span><?= htmlspecialchars($cage["female_dob"] ?? '') ?></span>
                                </td>
                            </tr>
                        </table>
                        <table border="1" style="width: 5in; height: 1.5in; border-top: none;" class="cageB">
                            <tr>
                                <td style="width:25%;">
                                    <span style="font-weight: bold; padding:3px; text-transform: uppercase; border-top: none; text-align: center;">DOM</span>
                                </td>
                                <td style="width:25%;">
                                    <span style="font-weight: bold; padding:3px; text-transform: uppercase; border-top: none; text-align: center;">Litter DOB</span>
                                </td>
                                <td style="width:12.5%;">
                                    <span style="font-weight: bold; padding:3px; text-transform: uppercase; border-top: none; text-align: center;">Pups Alive</span>
                                </td>
                                <td style="width:12.5%;">
                                    <span style="font-weight: bold; padding:3px; text-transform: uppercase; border-top: none; text-align: center;">Pups Dead</span>
                                </td>
                                <td style="width:12.5%;">
                                    <span style="font-weight: bold; padding:3px; text-transform: uppercase; border-top: none; text-align: center;">Pups Male</span>
                                </td>
                                <td style="width:12.5%;">
                                    <span style="font-weight: bold; padding:3px; text-transform: uppercase; border-top: none; text-align: center;">Pups Female</span>
                                </td>
                            </tr>
                            <?php for ($i = 0; $i < 5; $i++) : ?>
                                <tr>
                                    <td style="width:25%; padding:3px;">
                                        <span><?= isset($cage['litters'][$i]['dom']) ? $cage['litters'][$i]['dom'] : '' ?></span>
                                    </td>
                                    <td style="width:25%; padding:3px;">
                                        <span><?= isset($cage['litters'][$i]['litter_dob']) ? $cage['litters'][$i]['litter_dob'] : '' ?></span>
                                    </td>
                                    <td style="width:12.5%; padding:3px; text-align:center;">
                                        <span><?= isset($cage['litters'][$i]['pups_alive']) ? $cage['litters'][$i]['pups_alive'] : '' ?></span>
                                    </td>
                                    <td style="width:12.5%; padding:3px; text-align:center;">
                                        <span><?= isset($cage['litters'][$i]['pups_dead']) ? $cage['litters'][$i]['pups_dead'] : '' ?></span>
                                    </td>
                                    <td style="width:12.5%; padding:3px; text-align:center;">
                                        <span><?= isset($cage['litters'][$i]['pups_male']) ? $cage['litters'][$i]['pups_male'] : '' ?></span>
                                    </td>
                                    <td style="width:12.5%; padding:3px; text-align:center;">
                                        <span><?= isset($cage['litters'][$i]['pups_female']) ? $cage['litters'][$i]['pups_female'] : '' ?></span>
                                    </td>
                                </tr>
                            <?php endfor; ?>
                        </table>
                    <?php endif; ?>

                </td>

            <?php if ($index % 2 === 1 || $index === count($pageCages) - 1) : ?>
                </tr>
            <?php endif; ?>

        <?php endforeach; ?>
    </table>
    </div>
    <?php endfor; ?>
    </div>
</body>

</html>
