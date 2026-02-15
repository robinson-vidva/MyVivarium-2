<?php

/**
 * Cage Lineage View
 *
 * Displays parent-child cage relationships as a visual tree.
 * Uses the parent_cg column in the holding table to trace lineage.
 * Supports viewing lineage both "up" (ancestors) and "down" (descendants).
 */

require 'session_config.php';
require 'dbcon.php';

// Check if the user is logged in
if (!isset($_SESSION['username'])) {
    $currentUrl = urlencode($_SERVER['REQUEST_URI']);
    header("Location: index.php?redirect=$currentUrl");
    exit;
}

/**
 * Determine cage type (HC or BC) by checking holding and breeding tables.
 */
function getCageType($con, $cageId) {
    $hcStmt = $con->prepare("SELECT 1 FROM holding WHERE cage_id = ?");
    $hcStmt->bind_param("s", $cageId);
    $hcStmt->execute();
    if ($hcStmt->get_result()->num_rows > 0) {
        $hcStmt->close();
        return 'HC';
    }
    $hcStmt->close();

    $bcStmt = $con->prepare("SELECT 1 FROM breeding WHERE cage_id = ?");
    $bcStmt->bind_param("s", $cageId);
    $bcStmt->execute();
    if ($bcStmt->get_result()->num_rows > 0) {
        $bcStmt->close();
        return 'BC';
    }
    $bcStmt->close();

    return 'Unknown';
}

/**
 * Get cage info (strain name and status) for a given cage ID.
 */
function getCageInfo($con, $cageId) {
    // Try holding table first
    $stmt = $con->prepare("SELECT h.cage_id, h.strain, h.parent_cg, s.str_name, c.status
                           FROM holding h
                           LEFT JOIN cages c ON h.cage_id = c.cage_id
                           LEFT JOIN strains s ON h.strain = s.str_id
                           WHERE h.cage_id = ?");
    $stmt->bind_param("s", $cageId);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $stmt->close();
        return $row;
    }
    $stmt->close();

    // Try breeding table (breeding has 'cross' instead of 'strain', and no parent_cg)
    $stmt2 = $con->prepare("SELECT b.cage_id, b.`cross`, c.status
                            FROM breeding b
                            LEFT JOIN cages c ON b.cage_id = c.cage_id
                            WHERE b.cage_id = ?");
    $stmt2->bind_param("s", $cageId);
    $stmt2->execute();
    $result2 = $stmt2->get_result();
    if ($row2 = $result2->fetch_assoc()) {
        $stmt2->close();
        $row2['strain'] = null;
        $row2['str_name'] = $row2['cross'] ?? null;  // Show cross info as the label
        $row2['parent_cg'] = null;
        unset($row2['cross']);
        return $row2;
    }
    $stmt2->close();

    return null;
}

/**
 * Recursively build descendant tree for a given cage ID.
 */
function buildDescendantTree($con, $cageId, $depth = 0, $maxDepth = 10) {
    if ($depth > $maxDepth) return [];

    $children = [];
    $stmt = $con->prepare("SELECT h.cage_id, h.strain, h.parent_cg, s.str_name, c.status
                           FROM holding h
                           LEFT JOIN cages c ON h.cage_id = c.cage_id
                           LEFT JOIN strains s ON h.strain = s.str_id
                           WHERE h.parent_cg = ?");
    $stmt->bind_param("s", $cageId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $row['children'] = buildDescendantTree($con, $row['cage_id'], $depth + 1, $maxDepth);
        $children[] = $row;
    }
    $stmt->close();
    return $children;
}

/**
 * Find all ancestors of a given cage by following parent_cg up.
 */
function findAncestors($con, $cageId, $maxDepth = 20) {
    $ancestors = [];
    $currentId = $cageId;
    $visited = [];

    for ($i = 0; $i < $maxDepth; $i++) {
        // Get parent_cg for current cage from holding table
        $stmt = $con->prepare("SELECT parent_cg FROM holding WHERE cage_id = ?");
        $stmt->bind_param("s", $currentId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();

        if (!$row || empty($row['parent_cg'])) {
            break;
        }

        $parentId = $row['parent_cg'];

        // Prevent infinite loops
        if (in_array($parentId, $visited)) {
            break;
        }
        $visited[] = $parentId;

        $parentInfo = getCageInfo($con, $parentId);
        if ($parentInfo) {
            $ancestors[] = $parentInfo;
        } else {
            // Parent cage exists as reference but not found in tables
            $ancestors[] = [
                'cage_id' => $parentId,
                'strain' => null,
                'str_name' => null,
                'status' => null,
                'parent_cg' => null
            ];
        }

        $currentId = $parentId;
    }

    return array_reverse($ancestors);
}

/**
 * Render a tree node and its children recursively as HTML.
 */
function renderTree($node, $con, $isRoot = false) {
    $type = getCageType($con, $node['cage_id']);
    $viewUrl = ($type === 'HC') ? 'hc_view.php' : (($type === 'BC') ? 'bc_view.php' : '#');
    $status = $node['status'] ?? 'active';
    $statusClass = ($status === 'archived') ? 'archived' : 'active';
    $statusBadge = ($status === 'archived')
        ? '<span class="badge bg-danger">Archived</span>'
        : '<span class="badge bg-success">Active</span>';
    $rootClass = $isRoot ? ' tree-root' : '';

    echo '<div class="tree-node">';
    echo '<div class="tree-node-content ' . htmlspecialchars($statusClass) . htmlspecialchars($rootClass) . '">';
    echo '<a href="' . htmlspecialchars($viewUrl) . '?id=' . rawurlencode($node['cage_id']) . '">' . htmlspecialchars($node['cage_id']) . '</a>';
    echo ' <span class="badge bg-secondary">' . htmlspecialchars($type) . '</span> ';
    echo $statusBadge;
    if (!empty($node['str_name'])) {
        echo ' <small class="text-muted">(' . htmlspecialchars($node['str_name']) . ')</small>';
    }
    echo '</div>';

    if (!empty($node['children'])) {
        foreach ($node['children'] as $child) {
            renderTree($child, $con, false);
        }
    }
    echo '</div>';
}

// Handle search input
$searchCageId = isset($_GET['cage_id']) ? trim($_GET['cage_id']) : '';
$direction = isset($_GET['direction']) ? $_GET['direction'] : 'both';

// Fetch all cage IDs for the dropdown
$allCagesQuery = "SELECT cage_id FROM cages ORDER BY cage_id";
$allCagesResult = mysqli_query($con, $allCagesQuery);
$allCageIds = [];
while ($row = mysqli_fetch_assoc($allCagesResult)) {
    $allCageIds[] = $row['cage_id'];
}

require 'header.php';
?>

<!doctype html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cage Lineage | <?php echo htmlspecialchars($labName); ?></title>

    <!-- Select2 CSS loaded via header.php -->
    <!-- Include Select2 JS -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.1.0-beta.1/js/select2.min.js"></script>

    <style>
        .container {
            max-width: 900px;
            padding: 20px 15px;
            margin: auto;
        }

        .tree-node {
            margin-left: 30px;
            border-left: 2px solid var(--bs-border-color);
            padding-left: 15px;
            margin-bottom: 10px;
        }

        .tree-node-content {
            padding: 8px 15px;
            background: var(--bs-tertiary-bg);
            border-radius: 6px;
            border: 1px solid var(--bs-border-color);
            display: inline-block;
        }

        .tree-node-content.active {
            border-left: 3px solid #28a745;
        }

        .tree-node-content.archived {
            border-left: 3px solid #dc3545;
            opacity: 0.7;
        }

        .tree-root {
            font-weight: bold;
        }

        .lineage-section {
            margin-top: 20px;
            padding: 15px;
            background: var(--bs-body-bg);
            border-radius: 8px;
            border: 1px solid var(--bs-border-color);
        }

        .lineage-section h5 {
            margin-bottom: 15px;
        }

        .search-form {
            margin-bottom: 20px;
        }

        .highlight-cage {
            background-color: #fff3cd !important;
            border: 2px solid #ffc107 !important;
        }

        .select2-container .select2-selection--single {
            height: 38px;
        }

        .select2-container--default .select2-selection--single .select2-selection__rendered {
            line-height: 38px;
        }

        .select2-container--default .select2-selection--single .select2-selection__arrow {
            height: 38px;
        }
    </style>
</head>

<body>
    <div class="container content mt-4">
        <div class="card">
            <div class="card-header">
                <h4>Cage Lineage Viewer</h4>
            </div>
            <div class="card-body">
                <!-- Search Form -->
                <form method="GET" action="cage_lineage.php" class="search-form">
                    <div class="row g-3 align-items-end">
                        <div class="col-md-5">
                            <label for="cage_id" class="form-label"><strong>Cage ID:</strong></label>
                            <select class="form-control" id="cage_id" name="cage_id" required>
                                <option value="">Select a cage</option>
                                <?php foreach ($allCageIds as $cid) : ?>
                                    <option value="<?= htmlspecialchars($cid); ?>" <?= ($searchCageId === $cid) ? 'selected' : ''; ?>><?= htmlspecialchars($cid); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label for="direction" class="form-label"><strong>Direction:</strong></label>
                            <select class="form-control" id="direction" name="direction">
                                <option value="both" <?php echo ($direction === 'both') ? 'selected' : ''; ?>>Both (Ancestors &amp; Descendants)</option>
                                <option value="up" <?php echo ($direction === 'up') ? 'selected' : ''; ?>>Up (Ancestors Only)</option>
                                <option value="down" <?php echo ($direction === 'down') ? 'selected' : ''; ?>>Down (Descendants Only)</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <button type="submit" class="btn btn-primary w-100">View Lineage</button>
                        </div>
                    </div>
                </form>

                <?php if (!empty($searchCageId)) : ?>
                    <?php
                    // Check if the cage exists
                    $cageInfo = getCageInfo($con, $searchCageId);
                    if (!$cageInfo) :
                    ?>
                        <div class="alert alert-warning">
                            Cage ID <strong><?php echo htmlspecialchars($searchCageId); ?></strong> was not found.
                        </div>
                    <?php else : ?>
                        <?php
                        $cageType = getCageType($con, $searchCageId);
                        $cageStatus = $cageInfo['status'] ?? 'active';
                        ?>
                        <div class="alert alert-info">
                            Showing lineage for cage <strong><?php echo htmlspecialchars($searchCageId); ?></strong>
                            <span class="badge bg-secondary"><?php echo htmlspecialchars($cageType); ?></span>
                            <?php if (!empty($cageInfo['str_name'])) : ?>
                                - <?php echo htmlspecialchars($cageInfo['str_name']); ?>
                            <?php endif; ?>
                        </div>

                        <?php if ($direction === 'up' || $direction === 'both') : ?>
                            <div class="lineage-section">
                                <h5><i class="fas fa-arrow-up"></i> Ancestors</h5>
                                <?php
                                $ancestors = findAncestors($con, $searchCageId);
                                if (!empty($ancestors)) :
                                    // Build a nested tree structure from ancestors (root first)
                                    // Render ancestors as a chain: root -> child -> ... -> search cage
                                    $rootAncestor = $ancestors[0];
                                    $rootAncestor['children'] = [];

                                    // Chain ancestors together
                                    $currentNode = &$rootAncestor;
                                    for ($i = 1; $i < count($ancestors); $i++) {
                                        $ancestors[$i]['children'] = [];
                                        $currentNode['children'] = [$ancestors[$i]];
                                        $currentNode = &$currentNode['children'][0];
                                    }
                                    // Add the search cage at the end
                                    $searchNode = $cageInfo;
                                    $searchNode['children'] = [];
                                    $currentNode['children'] = [$searchNode];
                                    unset($currentNode);

                                    renderTree($rootAncestor, $con, true);
                                else :
                                ?>
                                    <p class="text-muted">No ancestors found. This cage is a root cage (no parent_cg set).</p>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <?php if ($direction === 'down' || $direction === 'both') : ?>
                            <div class="lineage-section">
                                <h5><i class="fas fa-arrow-down"></i> Descendants</h5>
                                <?php
                                $descendants = buildDescendantTree($con, $searchCageId);
                                if (!empty($descendants)) :
                                    $rootNode = $cageInfo;
                                    $rootNode['children'] = $descendants;
                                    renderTree($rootNode, $con, true);
                                else :
                                ?>
                                    <p class="text-muted">No descendant cages found.</p>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                    <?php endif; ?>

                <?php else : ?>
                    <!-- Show overview of all parent-child relationships -->
                    <div class="lineage-section">
                        <h5>All Cages with Parent Relationships</h5>
                        <?php
                        $relQuery = "SELECT h.cage_id, h.parent_cg, h.strain, s.str_name, c.status
                                     FROM holding h
                                     LEFT JOIN cages c ON h.cage_id = c.cage_id
                                     LEFT JOIN strains s ON h.strain = s.str_id
                                     WHERE h.parent_cg IS NOT NULL AND h.parent_cg != ''
                                     ORDER BY h.parent_cg, h.cage_id";
                        $relResult = mysqli_query($con, $relQuery);

                        if ($relResult && mysqli_num_rows($relResult) > 0) :
                            // Build adjacency list to find root cages
                            $childCages = [];
                            $allRelations = [];
                            while ($rel = mysqli_fetch_assoc($relResult)) {
                                $allRelations[] = $rel;
                                $childCages[$rel['cage_id']] = $rel['parent_cg'];
                            }

                            // Find root cages (parents that are not children of any other cage in our set)
                            $parentIds = array_unique(array_values($childCages));
                            $rootCages = [];
                            foreach ($parentIds as $pid) {
                                if (!isset($childCages[$pid])) {
                                    $rootCages[] = $pid;
                                }
                            }
                            $rootCages = array_unique($rootCages);

                            if (!empty($rootCages)) :
                                foreach ($rootCages as $rootId) :
                                    $rootInfo = getCageInfo($con, $rootId);
                                    if ($rootInfo) {
                                        $rootInfo['children'] = buildDescendantTree($con, $rootId);
                                        renderTree($rootInfo, $con, true);
                                    } else {
                                        // Root cage info not found, still render descendants
                                        $fakeRoot = [
                                            'cage_id' => $rootId,
                                            'strain' => null,
                                            'str_name' => null,
                                            'status' => null,
                                            'children' => buildDescendantTree($con, $rootId)
                                        ];
                                        renderTree($fakeRoot, $con, true);
                                    }
                                    echo '<hr>';
                                endforeach;
                            else :
                        ?>
                                <p class="text-muted">No root cages found in the lineage data.</p>
                            <?php endif; ?>
                        <?php else : ?>
                            <p class="text-muted">No parent-child cage relationships found. Cages need to have the <em>Parent Cage</em> field set to appear here.</p>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        $(document).ready(function() {
            $('#cage_id').select2({
                placeholder: "Select a cage",
                allowClear: true
            });
        });
    </script>

    <br>
    <?php include 'footer.php'; ?>
</body>

</html>
