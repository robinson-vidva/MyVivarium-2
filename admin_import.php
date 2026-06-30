<?php

/**
 * Admin: Import V1 data
 *
 * Accepts a JSON file produced by V1's "Export for V2 Migration" admin
 * page (the V1 side lives in the V1 repo, not here — see V1's
 * `export_for_v2.php`). Transforms V1 rows into the V2 mouse-as-entity
 * model, then inserts everything in a single transaction.
 *
 * Driven entirely by the uploaded JSON, so it works without shell access to
 * mysql and absorbs the schema differences between a stock V1 database and V2
 * (missing columns default, missing tables are skipped). The transform engine
 * lives in includes/v1_import.php.
 *
 * Admin-only. CSRF-protected. Aborts cleanly if the V2 database isn't
 * empty enough for an import (tables non-empty → user must reset first).
 */

require 'session_config.php';
require 'dbcon.php';
require_once 'log_activity.php';
require_once __DIR__ . '/includes/v1_import.php';

if (!isset($_SESSION['username'])) {
    $currentUrl = urlencode($_SERVER['REQUEST_URI']);
    header("Location: index.php?redirect=$currentUrl");
    exit;
}
if (($_SESSION['role'] ?? '') !== 'admin') {
    $_SESSION['message'] = 'Admin only.';
    header('Location: home.php'); exit;
}
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$report          = null;
$errors          = [];
$needsConfirm    = false;   // destination has data; show "are you sure" UI
$dbSummary       = null;    // counts shown to admin in the warning
$confirmedReset  = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die('CSRF token validation failed');
    }
    $confirmedReset = (($_POST['confirm_overwrite'] ?? '') === '1');

    if (!isset($_FILES['v1_export']) || $_FILES['v1_export']['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'No file uploaded, or upload failed (check upload_max_filesize and post_max_size in php.ini).';
    } else {
        $raw = file_get_contents($_FILES['v1_export']['tmp_name']);
        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['tables']) || !is_array($data['tables'])) {
            $errors[] = 'Uploaded file is not a valid V1 export JSON.';
        } else {
            $dbSummary = describe_destination($con);
            if ($dbSummary['has_data'] && !$confirmedReset) {
                // Stop and ask. Don't touch the DB.
                $needsConfirm = true;
            } else {
                if ($dbSummary['has_data'] && $confirmedReset) {
                    // Wipe before importing. Logged so the action is visible
                    // in the audit trail.
                    log_activity($con, 'reset', 'database', null,
                        'Admin wiped existing data before V1 import: ' . json_encode($dbSummary));
                    reset_destination($con);
                }
                $report = run_import($con, $data, $errors, $_SESSION['user_id'] ?? null);
            }
        }
    }
}

require 'header.php';
?>

<!doctype html>
<html lang="en">
<head>
    <title>Import from Previous Version | <?= htmlspecialchars($labName); ?></title>
    <style>
        .container { max-width: 900px; padding: 20px; margin: auto; background: var(--bs-tertiary-bg); border-radius: 8px; margin-top: 20px; }
        .file-input { padding: 8px; }
        pre { background: var(--bs-body-bg); padding: 12px; border-radius: 6px; max-height: 320px; overflow: auto; }
    </style>
</head>
<body>
<div class="container content">
    <h4>Import data from previous version</h4>
    <p class="text-muted">
        Upload an export file from the previous version of MyVivarium and
        we'll bring its data into this lab — users, cages, mice, breeding,
        litters, files, notes, tasks, and history. The system handles the
        translation between versions internally.
    </p>

    <?php if ($errors): ?>
        <div class="alert alert-danger">
            <?php foreach ($errors as $e) echo '<div>' . htmlspecialchars($e) . '</div>'; ?>
        </div>
    <?php endif; ?>

    <?php if ($report): ?>
        <div class="alert alert-success">
            <strong>Import complete.</strong>
            <pre><?= htmlspecialchars(json_encode($report, JSON_PRETTY_PRINT)); ?></pre>
            <a href="mouse_dash.php" class="btn btn-primary btn-sm">Open Mice Dashboard</a>
        </div>
    <?php endif; ?>

    <?php if ($needsConfirm && $dbSummary): ?>
        <div class="alert alert-warning">
            <h6 class="mb-2"><i class="fas fa-exclamation-triangle"></i> Existing data in this lab</h6>
            <p class="mb-2">This lab already contains data. Importing will <strong>erase everything</strong> below and replace it with what's in the upload:</p>
            <ul class="mb-3">
                <li><strong><?= $dbSummary['mice']; ?></strong> mice</li>
                <li><strong><?= $dbSummary['cages']; ?></strong> cages</li>
                <li><strong><?= $dbSummary['breeding']; ?></strong> breeding cages</li>
                <li><strong><?= $dbSummary['litters']; ?></strong> litters</li>
                <li><strong><?= $dbSummary['users_total']; ?></strong> user accounts</li>
            </ul>
            <p class="mb-0 small">If you didn't intend to overwrite, click Cancel. Otherwise re-pick the same export file below and tick the confirmation box.</p>
        </div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']); ?>">
        <div class="mb-3">
            <label for="v1_export" class="form-label">Export file (.json)</label>
            <input type="file" id="v1_export" name="v1_export" class="form-control file-input" accept=".json,application/json" required>
            <small class="text-muted d-block mt-1">
                Ask your previous-version admin to use the
                <strong>Export for migration</strong> option there and send
                you the resulting <code>.json</code> file.
            </small>
        </div>

        <?php if ($needsConfirm): ?>
            <div class="form-check mb-3">
                <input type="checkbox" id="confirm_overwrite" name="confirm_overwrite" value="1" class="form-check-input" required>
                <label for="confirm_overwrite" class="form-check-label">
                    Yes, erase the existing data in this lab and replace it with the uploaded export.
                </label>
            </div>
            <button type="submit" class="btn btn-danger">
                <i class="fas fa-exclamation-triangle"></i> Erase &amp; Import
            </button>
        <?php else: ?>
            <button type="submit" class="btn btn-primary">Import</button>
        <?php endif; ?>

        <a href="home.php" class="btn btn-secondary">Cancel</a>
    </form>
</div>
<br>
<?php include 'footer.php'; ?>
</body>
</html>
