<?php

/**
 * View Mouse
 *
 * Single-mouse detail page. Shows the canonical mouse record + full cage
 * move history + lineage links (sire/dam → parent mice; reverse lookup for
 * offspring). Action buttons (move, sacrifice, edit, admin-delete) are
 * status-aware: a sacrificed mouse can't be moved, only an admin sees the
 * hard-delete button, etc.
 */

require 'session_config.php';
require 'dbcon.php';

if (!isset($_SESSION['username'])) {
    $currentUrl = urlencode($_SERVER['REQUEST_URI']);
    header("Location: index.php?redirect=$currentUrl");
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$mouse_id = trim($_GET['id'] ?? '');
if ($mouse_id === '') {
    $_SESSION['message'] = 'Missing mouse ID.';
    header('Location: mouse_dash.php');
    exit;
}

$query = "
    SELECT m.*, s.str_name, s.str_aka, s.str_url, s.str_rrid,
           u.name AS creator_name, u.initials AS creator_initials
      FROM mice m
      LEFT JOIN strains s ON s.str_id = m.strain
      LEFT JOIN users u   ON u.id     = m.created_by
     WHERE m.mouse_id = ?";
$stmt = $con->prepare($query);
$stmt->bind_param("s", $mouse_id);
$stmt->execute();
$res = $stmt->get_result();
if ($res->num_rows !== 1) {
    $_SESSION['message'] = "Mouse not found: " . htmlspecialchars($mouse_id);
    header('Location: mouse_dash.php');
    exit;
}
$mouse = $res->fetch_assoc();
$stmt->close();

// Cage history (most recent first)
$histStmt = $con->prepare("
    SELECT h.*, u.name AS user_name, u.initials AS user_initials
      FROM mouse_cage_history h
      LEFT JOIN users u ON u.id = h.moved_by
     WHERE h.mouse_id = ?
     ORDER BY h.moved_in_at DESC, h.id DESC
");
$histStmt->bind_param("s", $mouse_id);
$histStmt->execute();
$history = $histStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$histStmt->close();

// Offspring (anyone who lists this mouse as sire or dam)
$offSql = "SELECT mouse_id, sex, dob, status, current_cage_id FROM mice WHERE sire_id = ? OR dam_id = ? ORDER BY dob DESC, mouse_id";
$offStmt = $con->prepare($offSql);
$offStmt->bind_param("ss", $mouse_id, $mouse_id);
$offStmt->execute();
$offspring = $offStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$offStmt->close();

// Active cages for the move modal
$cageRows = [];
$cageStmt = $con->query("SELECT cage_id FROM cages WHERE status = 'active' ORDER BY cage_id");
while ($row = $cageStmt->fetch_assoc()) $cageRows[] = $row['cage_id'];

// Age
$ageString = '—';
if (!empty($mouse['dob'])) {
    $d = new DateTime($mouse['dob']);
    $diff = $d->diff(new DateTime('today'));
    $parts = [];
    if ($diff->y) $parts[] = $diff->y . ($diff->y === 1 ? ' year' : ' years');
    if ($diff->m) $parts[] = $diff->m . ($diff->m === 1 ? ' month' : ' months');
    $parts[] = $diff->d . ($diff->d === 1 ? ' day' : ' days');
    $ageString = implode(' ', $parts);
}

$isAdmin = ($_SESSION['role'] ?? '') === 'admin';
$canModify = !in_array($mouse['status'], ['sacrificed', 'archived'], true);

$statusBadge = [
    'alive'           => 'bg-success',
    'sacrificed'      => 'bg-secondary',
    'archived'        => 'bg-dark',
    'transferred_out' => 'bg-warning text-dark',
][$mouse['status']] ?? 'bg-light text-dark';

require 'header.php';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Mouse <?= htmlspecialchars($mouse['mouse_id']); ?> | <?= htmlspecialchars($labName); ?></title>
    <style>
        .container { max-width: 900px; padding: 20px 15px; margin: auto; }
        .section-card {
            background-color: var(--bs-tertiary-bg);
            border: 1px solid var(--bs-border-color);
            border-radius: 10px;
            padding: 24px;
            margin-bottom: 20px;
        }
        .section-header {
            display: flex; align-items: center; gap: 10px;
            margin-bottom: 18px; padding-bottom: 12px;
            border-bottom: 1px solid var(--bs-border-color);
        }
        .section-header h5 { margin: 0; }
        .section-header .action-buttons { margin-left: auto; display: flex; flex-wrap: wrap; gap: 6px; }
        .details-table { width: 100%; border-collapse: collapse; }
        .details-table th, .details-table td { padding: 10px 14px; border-bottom: 1px solid var(--bs-border-color); vertical-align: top; }
        .details-table th { width: 35%; font-weight: 600; text-align: left; }
        .details-table tr:last-child th, .details-table tr:last-child td { border-bottom: none; }
        .timeline { list-style: none; padding-left: 0; }
        .timeline li { padding: 10px 14px; border-left: 3px solid var(--bs-primary); margin-bottom: 8px; background: var(--bs-body-bg); border-radius: 0 6px 6px 0; }
        .timeline li.closed { border-left-color: var(--bs-secondary); }
    </style>
</head>
<body>
<div class="container content mt-4">

    <div class="section-card">
        <div class="section-header">
            <i class="fas fa-paw"></i>
            <h5>Mouse <?= htmlspecialchars($mouse['mouse_id']); ?>
                <span class="badge ms-2 <?= $statusBadge; ?>"><?= htmlspecialchars($mouse['status']); ?></span>
            </h5>
            <div class="action-buttons">
                <a href="mouse_dash.php" class="btn btn-secondary btn-sm" title="Back"><i class="fas fa-arrow-circle-left"></i></a>
                <?php if ($canModify): ?>
                    <a href="mouse_edit.php?id=<?= rawurlencode($mouse['mouse_id']); ?>" class="btn btn-warning btn-sm" title="Edit"><i class="fas fa-edit"></i></a>
                    <button class="btn btn-info btn-sm" onclick="openMoveModal()" title="Move to Cage"><i class="fas fa-exchange-alt"></i></button>
                    <button class="btn btn-dark btn-sm" onclick="openSacrificeModal()" title="Mark Sacrificed"><i class="fas fa-skull"></i></button>
                <?php endif; ?>
                <?php if ($isAdmin): ?>
                    <button class="btn btn-danger btn-sm" onclick="openDeleteModal()" title="Admin: Hard Delete"><i class="fas fa-trash"></i></button>
                <?php endif; ?>
            </div>
        </div>

        <table class="details-table">
            <tr><th>Mouse ID</th><td><?= htmlspecialchars($mouse['mouse_id']); ?></td></tr>
            <tr><th>Sex</th><td><?= htmlspecialchars(ucfirst($mouse['sex'])); ?></td></tr>
            <tr><th>DOB</th><td><?= htmlspecialchars($mouse['dob'] ?? '—'); ?></td></tr>
            <tr><th>Age</th><td><?= htmlspecialchars($ageString); ?></td></tr>
            <tr><th>Current Cage</th>
                <td>
                    <?php if ($mouse['current_cage_id']): ?>
                        <a href="hc_view.php?id=<?= rawurlencode($mouse['current_cage_id']); ?>"><?= htmlspecialchars($mouse['current_cage_id']); ?></a>
                    <?php else: ?>
                        <span class="text-muted">— No cage —</span>
                    <?php endif; ?>
                </td></tr>
            <tr><th>Strain</th>
                <td><?= $mouse['strain']
                    ? htmlspecialchars($mouse['strain'] . ' | ' . ($mouse['str_name'] ?? ''))
                    : '<span class="text-muted">—</span>'; ?></td></tr>
            <tr><th>Genotype</th><td><?= htmlspecialchars($mouse['genotype'] ?? '—'); ?></td></tr>
            <tr><th>Ear Code</th><td><?= htmlspecialchars($mouse['ear_code'] ?? '—'); ?></td></tr>
            <tr><th>Sire (Father)</th>
                <td>
                    <?php if ($mouse['sire_id']): ?>
                        <a href="mouse_view.php?id=<?= rawurlencode($mouse['sire_id']); ?>"><?= htmlspecialchars($mouse['sire_id']); ?></a>
                    <?php elseif ($mouse['sire_external_ref']): ?>
                        <em><?= htmlspecialchars($mouse['sire_external_ref']); ?></em> <small class="text-muted">(external)</small>
                    <?php else: ?>
                        <span class="text-muted">—</span>
                    <?php endif; ?>
                </td></tr>
            <tr><th>Dam (Mother)</th>
                <td>
                    <?php if ($mouse['dam_id']): ?>
                        <a href="mouse_view.php?id=<?= rawurlencode($mouse['dam_id']); ?>"><?= htmlspecialchars($mouse['dam_id']); ?></a>
                    <?php elseif ($mouse['dam_external_ref']): ?>
                        <em><?= htmlspecialchars($mouse['dam_external_ref']); ?></em> <small class="text-muted">(external)</small>
                    <?php else: ?>
                        <span class="text-muted">—</span>
                    <?php endif; ?>
                </td></tr>
            <?php if ($mouse['status'] === 'sacrificed'): ?>
            <tr><th>Sacrificed On</th><td><?= htmlspecialchars($mouse['sacrificed_at'] ?? '—'); ?></td></tr>
            <tr><th>Sacrifice Reason</th><td><?= htmlspecialchars($mouse['sacrifice_reason'] ?? '—'); ?></td></tr>
            <?php endif; ?>
            <tr><th>Notes</th><td><?= nl2br(htmlspecialchars($mouse['notes'] ?? '')); ?></td></tr>
            <tr><th>Created</th>
                <td>
                    <?= htmlspecialchars($mouse['created_at']); ?>
                    <?php if (!empty($mouse['creator_name'])): ?>
                        by <?= htmlspecialchars($mouse['creator_initials'] . ' [' . $mouse['creator_name'] . ']'); ?>
                    <?php endif; ?>
                </td></tr>
            <tr><th>Last Updated</th><td><?= htmlspecialchars($mouse['updated_at']); ?></td></tr>
        </table>
    </div>

    <div class="section-card">
        <div class="section-header"><i class="fas fa-route"></i><h5>Cage History (<?= count($history); ?>)</h5></div>
        <?php if (!$history): ?>
            <p class="text-muted mb-0">No cage history yet.</p>
        <?php else: ?>
            <ul class="timeline">
                <?php foreach ($history as $h): ?>
                    <li class="<?= $h['moved_out_at'] ? 'closed' : ''; ?>">
                        <strong>
                            <?php if ($h['cage_id']): ?>
                                <a href="hc_view.php?id=<?= rawurlencode($h['cage_id']); ?>"><?= htmlspecialchars($h['cage_id']); ?></a>
                            <?php else: ?>
                                <span class="text-muted">(no cage)</span>
                            <?php endif; ?>
                        </strong>
                        — <small>in <?= htmlspecialchars($h['moved_in_at']); ?></small>
                        <?php if ($h['moved_out_at']): ?>
                            <small>· out <?= htmlspecialchars($h['moved_out_at']); ?></small>
                        <?php else: ?>
                            <span class="badge bg-success ms-2">current</span>
                        <?php endif; ?>
                        <?php if ($h['reason']): ?>
                            <div class="small text-muted">Reason: <?= htmlspecialchars($h['reason']); ?></div>
                        <?php endif; ?>
                        <?php if (!empty($h['user_name'])): ?>
                            <div class="small text-muted">By <?= htmlspecialchars($h['user_initials'] . ' [' . $h['user_name'] . ']'); ?></div>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>

    <div class="section-card">
        <div class="section-header"><i class="fas fa-sitemap"></i><h5>Offspring (<?= count($offspring); ?>)</h5></div>
        <?php if (!$offspring): ?>
            <p class="text-muted mb-0">No offspring registered.</p>
        <?php else: ?>
            <table class="table table-sm">
                <thead><tr><th>Mouse ID</th><th>Sex</th><th>DOB</th><th>Status</th><th>Cage</th></tr></thead>
                <tbody>
                <?php foreach ($offspring as $o): ?>
                    <tr>
                        <td><a href="mouse_view.php?id=<?= rawurlencode($o['mouse_id']); ?>"><?= htmlspecialchars($o['mouse_id']); ?></a></td>
                        <td><?= htmlspecialchars(ucfirst($o['sex'])); ?></td>
                        <td><?= htmlspecialchars($o['dob'] ?? '—'); ?></td>
                        <td><?= htmlspecialchars($o['status']); ?></td>
                        <td><?= $o['current_cage_id']
                                ? '<a href="hc_view.php?id=' . rawurlencode($o['current_cage_id']) . '">' . htmlspecialchars($o['current_cage_id']) . '</a>'
                                : '<span class="text-muted">—</span>'; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<!-- Move Cage Modal -->
<div class="modal fade" id="moveModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST" action="mouse_move.php">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']); ?>">
        <input type="hidden" name="mouse_id" value="<?= htmlspecialchars($mouse['mouse_id']); ?>">
        <div class="modal-header"><h5 class="modal-title">Move Mouse</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <div class="mb-3">
                <label class="form-label">Target Cage</label>
                <select class="form-control" name="target_cage_id" required>
                    <option value="">Select cage…</option>
                    <option value="__none__">— No cage (remove from cage) —</option>
                    <?php foreach ($cageRows as $cid): if ($cid === $mouse['current_cage_id']) continue; ?>
                        <option value="<?= htmlspecialchars($cid); ?>"><?= htmlspecialchars($cid); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="mb-3">
                <label class="form-label">Reason</label>
                <input type="text" name="reason" class="form-control" placeholder="e.g. weaning, surgery, breeding setup">
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-primary">Move</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Sacrifice Modal -->
<div class="modal fade" id="sacModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST" action="mouse_sacrifice.php">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']); ?>">
        <input type="hidden" name="mouse_id" value="<?= htmlspecialchars($mouse['mouse_id']); ?>">
        <div class="modal-header"><h5 class="modal-title">Mark as Sacrificed</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <div class="mb-3">
                <label class="form-label">Date of Sacrifice</label>
                <input type="date" name="sacrificed_at" class="form-control" required value="<?= date('Y-m-d'); ?>" max="<?= date('Y-m-d'); ?>">
            </div>
            <div class="mb-3">
                <label class="form-label">Reason</label>
                <input type="text" name="reason" class="form-control" placeholder="e.g. study endpoint, health concern">
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-dark">Confirm Sacrifice</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php if ($isAdmin): ?>
<!-- Admin Hard Delete Modal -->
<div class="modal fade" id="delModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST" action="mouse_drop.php">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']); ?>">
        <input type="hidden" name="mouse_id" value="<?= htmlspecialchars($mouse['mouse_id']); ?>">
        <div class="modal-header bg-danger text-white"><h5 class="modal-title">Permanent Delete (Admin)</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <div class="alert alert-danger">
                <strong>This is irreversible.</strong> The mouse, its cage history, and any offspring's parent FK to it will be removed.
                Prefer marking sacrificed/archived for end-of-life. Hard delete is for accidental entries or duplicate cleanup.
            </div>
            <div class="mb-3">
                <label class="form-label">Type the Mouse ID to confirm: <code><?= htmlspecialchars($mouse['mouse_id']); ?></code></label>
                <input type="text" name="confirm_mouse_id" class="form-control" required>
            </div>
            <div class="mb-3">
                <label class="form-label">Reason (recorded in audit log)</label>
                <input type="text" name="reason" class="form-control" required minlength="3">
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-danger">Permanently Delete</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<script>
function openMoveModal()      { new bootstrap.Modal(document.getElementById('moveModal')).show(); }
function openSacrificeModal() { new bootstrap.Modal(document.getElementById('sacModal')).show(); }
function openDeleteModal()    { new bootstrap.Modal(document.getElementById('delModal')).show(); }
</script>

<?php include 'footer.php'; ?>
</body>
</html>
