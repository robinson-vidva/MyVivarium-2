<?php

/**
 * Admin: Import V1 data
 *
 * Accepts a JSON file produced by V1's database/export_for_v2.php (the
 * exporter ships in this repo under database/export_for_v2.php — copy it
 * into the V1 repo to run there). Transforms V1 rows into the V2
 * mouse-as-entity model, then inserts everything in a single transaction.
 *
 * Same data flow as database/import_from_v1.sql but driven by JSON, so it
 * works without shell-execing mysql and without a temp source schema.
 *
 * Admin-only. CSRF-protected. Aborts cleanly if the V2 database isn't
 * empty enough for an import (tables non-empty → user must reset first).
 */

require 'session_config.php';
require 'dbcon.php';
require_once 'log_activity.php';

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

$report = null;
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die('CSRF token validation failed');
    }
    if (!isset($_FILES['v1_export']) || $_FILES['v1_export']['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'No file uploaded, or upload failed (check upload_max_filesize and post_max_size in php.ini).';
    } else {
        $raw = file_get_contents($_FILES['v1_export']['tmp_name']);
        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['tables']) || !is_array($data['tables'])) {
            $errors[] = 'Uploaded file is not a valid V1 export JSON.';
        } else {
            $report = run_import($con, $data, $errors, $_SESSION['user_id'] ?? null);
        }
    }
}

require 'header.php';

/**
 * Insert a single row, named columns, INSERT IGNORE.
 */
function insert_row(mysqli $con, string $table, array $columns, array $row): void
{
    $cols = '`' . implode('`,`', $columns) . '`';
    $marks = implode(',', array_fill(0, count($columns), '?'));
    $stmt = $con->prepare("INSERT IGNORE INTO `$table` ($cols) VALUES ($marks)");
    if (!$stmt) throw new Exception("prepare $table: " . $con->error);

    $types  = '';
    $values = [];
    foreach ($columns as $c) {
        $v = $row[$c] ?? null;
        if ($v === null) {
            $types .= 's';
            $values[] = null;
        } elseif (is_int($v)) {
            $types .= 'i';
            $values[] = $v;
        } else {
            $types .= 's';
            $values[] = (string)$v;
        }
    }
    $stmt->bind_param($types, ...$values);
    if (!$stmt->execute()) throw new Exception("insert $table: " . $stmt->error);
    $stmt->close();
}

function copy_table(mysqli $con, array $tables, string $name, array $columns, array &$counts): void
{
    $rows = $tables[$name] ?? [];
    foreach ($rows as $row) insert_row($con, $name, $columns, $row);
    $counts[$name] = count($rows);
}

/**
 * Apply the JSON payload. Returns a report (counts per logical step).
 * Throws on hard error so the caller can roll back.
 */
function run_import(mysqli $con, array $payload, array &$errors, ?int $actorUserId): ?array
{
    $tables = $payload['tables'];

    // Pre-flight: make sure the destination is empty enough for an import.
    $check = $con->query("SELECT
        (SELECT COUNT(*) FROM mice) +
        (SELECT COUNT(*) FROM cages) +
        (SELECT COUNT(*) FROM breeding) +
        (SELECT COUNT(*) FROM users WHERE id > 1) AS n");
    $row = $check->fetch_assoc();
    if ((int)$row['n'] > 0) {
        $errors[] = 'Destination database already contains data (mice/cages/breeding/users beyond seed). Reset first via `php database/install.php --reset`, then retry.';
        return null;
    }

    $con->query("SET FOREIGN_KEY_CHECKS = 0");
    $con->begin_transaction();
    $counts = [];
    try {
        // 1. Reference data
        copy_table($con, $tables, 'users',
            ['id','name','username','position','role','password','status',
             'reset_token','reset_token_expiration','login_attempts','account_locked',
             'email_verified','email_token','initials'],
            $counts);

        copy_table($con, $tables, 'iacuc', ['iacuc_id','iacuc_title','file_url'], $counts);
        copy_table($con, $tables, 'strains', ['id','str_id','str_name','str_aka','str_url','str_rrid','str_notes'], $counts);
        copy_table($con, $tables, 'settings', ['name','value'], $counts);

        // 2. Cages + junctions
        $cageRows = $tables['cages'] ?? [];
        foreach ($cageRows as $row) {
            insert_row($con, 'cages',
                ['cage_id','pi_name','quantity','remarks','status','room','rack','created_at'],
                [
                    'cage_id'    => $row['cage_id'] ?? null,
                    'pi_name'    => $row['pi_name'] ?? null,
                    'quantity'   => $row['quantity'] ?? null,
                    'remarks'    => $row['remarks'] ?? null,
                    'status'     => $row['status']  ?? 'active',
                    'room'       => $row['room']    ?? null,
                    'rack'       => $row['rack']    ?? null,
                    'created_at' => $row['created_at'] ?? null,
                ]);
        }
        $counts['cages'] = count($cageRows);

        copy_table($con, $tables, 'cage_iacuc', ['cage_id','iacuc_id'], $counts);
        copy_table($con, $tables, 'cage_users', ['cage_id','user_id'], $counts);

        // 3a. Mice from V1 holding (synthesized mouse_id)
        $importedMice = 0;
        foreach (($tables['holding'] ?? []) as $h) {
            if (empty($h['cage_id'])) continue;
            $mouseId = 'H' . $h['cage_id'] . '_' . $h['id'];
            $note = !empty($h['parent_cg'])
                ? "Imported from v1 holding; v1 parent_cg: {$h['parent_cg']}"
                : 'Imported from v1 holding';
            insert_row($con, 'mice',
                ['mouse_id','sex','dob','current_cage_id','strain','genotype','status','notes'],
                [
                    'mouse_id'        => $mouseId,
                    'sex'             => $h['sex'] ?: 'unknown',
                    'dob'             => $h['dob'] ?? null,
                    'current_cage_id' => $h['cage_id'],
                    'strain'          => $h['strain']   ?? null,
                    'genotype'        => $h['genotype'] ?? null,
                    'status'          => 'alive',
                    'notes'           => $note,
                ]);
            $importedMice++;
        }
        $counts['mice_from_holding'] = $importedMice;

        // 3b. Mice from V1 mice table (preserve mouse_id)
        $importedV1Mice = 0;
        foreach (($tables['mice'] ?? []) as $m) {
            if (empty($m['mouse_id'])) continue;
            insert_row($con, 'mice',
                ['mouse_id','sex','dob','current_cage_id','genotype','status','notes'],
                [
                    'mouse_id'        => $m['mouse_id'],
                    'sex'             => 'unknown',
                    'dob'             => null,
                    'current_cage_id' => $m['cage_id'] ?? null,
                    'genotype'        => $m['genotype'] ?? null,
                    'status'          => 'alive',
                    'notes'           => 'Imported from v1 mice. ' . ($m['notes'] ?? ''),
                ]);
            $importedV1Mice++;
        }
        $counts['mice_from_v1_mice'] = $importedV1Mice;

        // 3c. Synthesize parent mice from V1 breeding rows
        $existingMouseIds = [];
        $r = $con->query("SELECT mouse_id FROM mice");
        while ($row = $r->fetch_assoc()) $existingMouseIds[$row['mouse_id']] = true;
        $r->close();

        $synthMales = 0;
        $synthFemales = 0;
        foreach (($tables['breeding'] ?? []) as $b) {
            $mid = trim((string)($b['male_id'] ?? ''));
            if ($mid !== '' && !isset($existingMouseIds[$mid])) {
                insert_row($con, 'mice',
                    ['mouse_id','sex','dob','current_cage_id','genotype','status','notes'],
                    [
                        'mouse_id'        => $mid,
                        'sex'             => 'male',
                        'dob'             => $b['male_dob'] ?? null,
                        'current_cage_id' => null,
                        'genotype'        => $b['male_genotype'] ?? null,
                        'status'          => 'archived',
                        'notes'           => 'Synthesized from v1 breeding parent; v1 source: ' . ($b['male_parent_cage'] ?? 'unknown'),
                    ]);
                $existingMouseIds[$mid] = true;
                $synthMales++;
            }
            $fid = trim((string)($b['female_id'] ?? ''));
            if ($fid !== '' && !isset($existingMouseIds[$fid])) {
                insert_row($con, 'mice',
                    ['mouse_id','sex','dob','current_cage_id','genotype','status','notes'],
                    [
                        'mouse_id'        => $fid,
                        'sex'             => 'female',
                        'dob'             => $b['female_dob'] ?? null,
                        'current_cage_id' => null,
                        'genotype'        => $b['female_genotype'] ?? null,
                        'status'          => 'archived',
                        'notes'           => 'Synthesized from v1 breeding parent; v1 source: ' . ($b['female_parent_cage'] ?? 'unknown'),
                    ]);
                $existingMouseIds[$fid] = true;
                $synthFemales++;
            }
        }
        $counts['mice_synthesized_from_breeding'] = $synthMales + $synthFemales;

        // 4. Seed mouse_cage_history with one open interval per cage-resident mouse.
        $seedHistory = $con->prepare("
            INSERT INTO mouse_cage_history (mouse_id, cage_id, moved_in_at, reason)
            SELECT m.mouse_id, m.current_cage_id, m.created_at, 'v1 import: initial cage assignment'
              FROM mice m WHERE m.current_cage_id IS NOT NULL
        ");
        if (!$seedHistory->execute()) throw new Exception("seed history: " . $seedHistory->error);
        $counts['history_open_intervals'] = $seedHistory->affected_rows;
        $seedHistory->close();

        // 5. Breeding rows — slim columns, NULL out parent IDs that don't resolve to a mouse
        $breedingRows = $tables['breeding'] ?? [];
        foreach ($breedingRows as $b) {
            $male   = trim((string)($b['male_id'] ?? ''));
            $female = trim((string)($b['female_id'] ?? ''));
            if ($male !== '' && !isset($existingMouseIds[$male]))   $male   = null;
            if ($female !== '' && !isset($existingMouseIds[$female])) $female = null;
            if ($male === '')   $male   = null;
            if ($female === '') $female = null;

            insert_row($con, 'breeding',
                ['id','cage_id','cross','male_id','female_id'],
                [
                    'id'        => $b['id'] ?? null,
                    'cage_id'   => $b['cage_id'] ?? null,
                    'cross'     => $b['cross'] ?? null,
                    'male_id'   => $male,
                    'female_id' => $female,
                ]);
        }
        $counts['breeding'] = count($breedingRows);

        // 6. Tail tables
        copy_table($con, $tables, 'litters',
            ['id','cage_id','dom','litter_dob','pups_alive','pups_dead','pups_male','pups_female','remarks'], $counts);
        copy_table($con, $tables, 'files',
            ['id','file_name','file_path','uploaded_at','cage_id'], $counts);
        copy_table($con, $tables, 'notes',
            ['id','cage_id','note_text','created_at','user_id'], $counts);
        copy_table($con, $tables, 'tasks',
            ['id','title','description','assigned_by','assigned_to','status','completion_date',
             'cage_id','creation_date','updated_at'], $counts);
        copy_table($con, $tables, 'maintenance',
            ['id','cage_id','user_id','comments','timestamp'], $counts);
        copy_table($con, $tables, 'reminders',
            ['id','title','description','assigned_by','assigned_to','recurrence_type',
             'day_of_week','day_of_month','time_of_day','status','cage_id',
             'creation_date','updated_at','last_task_created'], $counts);
        copy_table($con, $tables, 'notifications',
            ['id','user_id','title','message','link','type','is_read','created_at'], $counts);
        copy_table($con, $tables, 'outbox',
            ['id','recipient','subject','body','status','created_at','scheduled_at','sent_at','error_message','task_id'], $counts);
        copy_table($con, $tables, 'activity_log',
            ['id','user_id','action','entity_type','entity_id','details','ip_address','created_at'], $counts);

        $con->commit();
        $con->query("SET FOREIGN_KEY_CHECKS = 1");

        log_activity($con, 'import', 'v1_data', null,
            'V1 export imported: ' . json_encode($counts));

        return $counts;
    } catch (Exception $e) {
        $con->rollback();
        $con->query("SET FOREIGN_KEY_CHECKS = 1");
        $errors[] = 'Import failed and was rolled back: ' . $e->getMessage();
        return null;
    }
}
?>

<!doctype html>
<html lang="en">
<head>
    <title>Import from Previous Version | <?= htmlspecialchars($labName); ?></title>
    <style>
        .container { max-width: 800px; padding: 20px; margin: auto; background: var(--bs-tertiary-bg); border-radius: 8px; margin-top: 20px; }
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
        <button type="submit" class="btn btn-primary">Import</button>
        <a href="home.php" class="btn btn-secondary">Cancel</a>
    </form>

    <p class="text-muted small mt-4 mb-0">
        Heads up: the import requires a fresh database. If this lab already
        has data in it, ask your system administrator to reset it before
        re-running this step.
    </p>
</div>
<br>
<?php include 'footer.php'; ?>
</body>
</html>
