<?php

/**
 * Cage Lineage (v2)
 *
 * The v1 page traced lineage at the cage level via `holding.parent_cg`. In v2
 * lineage is per-mouse (mice.sire_id / dam_id) — strictly more accurate, since
 * a single cage can contain unrelated mice and a single mouse's parents may
 * have lived in different cages over time.
 *
 * This page now derives a cage's "parent cages" by looking up the cages that
 * historically contained the sires/dams of any mouse currently in the target
 * cage. For richer per-mouse lineage (offspring tree, sire/dam links), each
 * mouse_view.php page already shows its own ancestors and offspring.
 */

require 'session_config.php';
require 'dbcon.php';

if (!isset($_SESSION['username'])) {
    $currentUrl = urlencode($_SERVER['REQUEST_URI']);
    header("Location: index.php?redirect=$currentUrl");
    exit;
}

$cageId = trim($_GET['cage_id'] ?? '');

// Resolve "parent cages": for each mouse currently in $cageId, find its
// sire/dam, then look at where those parents were last housed.
$parents = [];
$descendants = [];
$miceInCage = [];

if ($cageId !== '') {
    $stmt = $con->prepare("SELECT mouse_id, sex, dob, sire_id, dam_id FROM mice WHERE current_cage_id = ?");
    $stmt->bind_param("s", $cageId);
    $stmt->execute();
    $miceInCage = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $parentSql = "
        SELECT DISTINCT p.mouse_id, p.sex, p.current_cage_id, p.dob, p.status
          FROM mice m
          JOIN mice p ON p.mouse_id IN (m.sire_id, m.dam_id)
         WHERE m.current_cage_id = ?";
    $ps = $con->prepare($parentSql);
    $ps->bind_param("s", $cageId);
    $ps->execute();
    $parents = $ps->get_result()->fetch_all(MYSQLI_ASSOC);
    $ps->close();

    // Descendant cages: cages that contain offspring of any mouse currently in this cage
    $descSql = "
        SELECT DISTINCT child.current_cage_id AS cage_id, COUNT(*) AS n_offspring
          FROM mice parent
          JOIN mice child ON (child.sire_id = parent.mouse_id OR child.dam_id = parent.mouse_id)
         WHERE parent.current_cage_id = ?
           AND child.current_cage_id IS NOT NULL
           AND child.current_cage_id != parent.current_cage_id
         GROUP BY child.current_cage_id";
    $ds = $con->prepare($descSql);
    $ds->bind_param("s", $cageId);
    $ds->execute();
    $descendants = $ds->get_result()->fetch_all(MYSQLI_ASSOC);
    $ds->close();
}

require 'header.php';
?>
<!doctype html>
<html lang="en">
<head>
    <title>Cage Lineage | <?= htmlspecialchars($labName); ?></title>
    <style>
        .container { max-width: 900px; padding: 20px; margin: auto; background: var(--bs-tertiary-bg); border-radius: 8px; margin-top: 20px; }
        .lineage-section { background: var(--bs-body-bg); padding: 16px; border-radius: 6px; margin-bottom: 16px; }
    </style>
</head>
<body>
<div class="container content">
    <h4>Cage Lineage</h4>
    <p class="text-muted">Lineage is now per-mouse (sire/dam). For full ancestor + offspring trees of an individual mouse, open <strong>Mice → mouse view</strong>. This page derives cage-level lineage from the mice currently inside the cage.</p>

    <form method="GET" class="mb-3">
        <div class="input-group">
            <input type="text" name="cage_id" class="form-control" placeholder="Enter Cage ID" value="<?= htmlspecialchars($cageId); ?>">
            <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Lookup</button>
        </div>
    </form>

    <?php if ($cageId === ''): ?>
        <p class="text-muted">Enter a cage ID above to see its lineage.</p>
    <?php else: ?>
        <div class="lineage-section">
            <h5><i class="fas fa-paw"></i> Mice in <?= htmlspecialchars($cageId); ?> (<?= count($miceInCage); ?>)</h5>
            <?php if (!$miceInCage): ?>
                <p class="text-muted mb-0">No mice currently in this cage.</p>
            <?php else: ?>
                <ul>
                    <?php foreach ($miceInCage as $m): ?>
                        <li><a href="mouse_view.php?id=<?= rawurlencode($m['mouse_id']); ?>"><?= htmlspecialchars($m['mouse_id']); ?></a> — <?= htmlspecialchars(ucfirst($m['sex'])); ?>, DOB <?= htmlspecialchars($m['dob'] ?? '—'); ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>

        <div class="lineage-section">
            <h5><i class="fas fa-arrow-up"></i> Parents (sires & dams of mice in this cage)</h5>
            <?php if (!$parents): ?>
                <p class="text-muted mb-0">No registered parents (founders or external sources).</p>
            <?php else: ?>
                <ul>
                    <?php foreach ($parents as $p): ?>
                        <li>
                            <a href="mouse_view.php?id=<?= rawurlencode($p['mouse_id']); ?>"><?= htmlspecialchars($p['mouse_id']); ?></a>
                            (<?= htmlspecialchars(ucfirst($p['sex'])); ?>)
                            <?php if ($p['current_cage_id']): ?>
                                — last in cage
                                <a href="cage_lineage.php?cage_id=<?= rawurlencode($p['current_cage_id']); ?>"><?= htmlspecialchars($p['current_cage_id']); ?></a>
                            <?php else: ?>
                                — <em><?= htmlspecialchars($p['status']); ?></em>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>

        <div class="lineage-section">
            <h5><i class="fas fa-arrow-down"></i> Descendant Cages</h5>
            <?php if (!$descendants): ?>
                <p class="text-muted mb-0">No descendant cages (no offspring of these mice are housed elsewhere).</p>
            <?php else: ?>
                <ul>
                    <?php foreach ($descendants as $d): ?>
                        <li>
                            <a href="cage_lineage.php?cage_id=<?= rawurlencode($d['cage_id']); ?>"><?= htmlspecialchars($d['cage_id']); ?></a>
                            — <?= (int)$d['n_offspring']; ?> offspring
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
<br>
<?php include 'footer.php'; ?>
</body>
</html>
