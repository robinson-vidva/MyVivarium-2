<?php

/**
 * Edit Holding Cage (v2)
 *
 * Cage-only editor. The v1 form bundled cage metadata + per-cage mouse rows
 * (`holding`) + per-cage `mice` rows in one mega-form. In v2 mice are
 * independent entities — they're managed from mouse_view.php / mouse_edit.php
 * and can be added via mouse_addn.php (or via "+ Add Mouse" on hc_view.php).
 *
 * What this form covers: cage_id (rename), PI, room/rack, IACUC, assigned
 * users, remarks, file uploads. Cage-id rename relies on `cages.cage_id`'s
 * ON UPDATE CASCADE so every dependent FK (mice.current_cage_id,
 * mouse_cage_history.cage_id, breeding.cage_id, files, etc.) follows.
 */

require 'session_config.php';
require 'dbcon.php';
require_once 'log_activity.php';

error_reporting(E_ALL);
ini_set('display_errors', 0);

if (!isset($_SESSION['username'])) {
    $currentUrl = urlencode($_SERVER['REQUEST_URI']);
    header("Location: index.php?redirect=$currentUrl");
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function getCurrentUrlParams() {
    $page   = (int)($_GET['page'] ?? 1);
    $search = urlencode($_GET['search'] ?? '');
    return "page=$page&search=$search";
}

$id = trim($_GET['id'] ?? $_POST['cage_id'] ?? '');
if ($id === '') {
    $_SESSION['message'] = 'Missing cage ID.';
    header('Location: hc_dash.php'); exit;
}

// Fetch cage
$stmt = $con->prepare("SELECT cage_id, pi_name, remarks, room, rack, created_at FROM cages WHERE cage_id = ?");
$stmt->bind_param("s", $id);
$stmt->execute();
$res = $stmt->get_result();
if ($res->num_rows !== 1) {
    $_SESSION['message'] = 'Cage not found.';
    header('Location: hc_dash.php'); exit;
}
$cage = $res->fetch_assoc();
$stmt->close();

// Currently-assigned users
$cu = $con->prepare("SELECT user_id FROM cage_users WHERE cage_id = ?");
$cu->bind_param("s", $id);
$cu->execute();
$selectedUsers = array_column($cu->get_result()->fetch_all(MYSQLI_ASSOC), 'user_id');
$cu->close();

// Authz: admin OR assigned user
$currentUserId = $_SESSION['user_id'] ?? 0;
$userRole      = $_SESSION['role'] ?? '';
if ($userRole !== 'admin' && !in_array($currentUserId, $selectedUsers)) {
    $_SESSION['message'] = 'Access denied. Only admins or assigned users can edit this cage.';
    header("Location: hc_dash.php?" . getCurrentUrlParams());
    exit;
}

// IACUC currently linked
$ci = $con->prepare("SELECT iacuc_id FROM cage_iacuc WHERE cage_id = ?");
$ci->bind_param("s", $id);
$ci->execute();
$selectedIacucs = array_column($ci->get_result()->fetch_all(MYSQLI_ASSOC), 'iacuc_id');
$ci->close();

// Files (display only — uploads handled by separate page)
$fs = $con->prepare("SELECT id, file_name, file_path FROM files WHERE cage_id = ?");
$fs->bind_param("s", $id);
$fs->execute();
$files = $fs->get_result()->fetch_all(MYSQLI_ASSOC);
$fs->close();

// Dropdown options for the form
$userResult  = $con->query("SELECT id, initials, name FROM users WHERE status = 'approved'");
$piResult    = $con->query("SELECT id, initials, name FROM users WHERE position = 'Principal Investigator' AND status = 'approved'");
$iacucResult = $con->query("SELECT iacuc_id, iacuc_title FROM iacuc");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die('CSRF token validation failed');
    }

    $new_cage_id = trim($_POST['new_cage_id'] ?? $id);
    $pi_name = !empty($_POST['pi_name']) ? (int)$_POST['pi_name'] : null;
    $room    = !empty($_POST['room'])    ? trim($_POST['room'])   : null;
    $rack    = !empty($_POST['rack'])    ? trim($_POST['rack'])   : null;
    $iacuc   = isset($_POST['iacuc'])    ? (array)$_POST['iacuc'] : [];
    $users   = isset($_POST['user'])     ? (array)$_POST['user']  : [];
    $remarks = trim($_POST['remarks'] ?? '');

    // Cage-id rename
    $cageIdChanged = false;
    if ($new_cage_id !== $id) {
        $check = $con->prepare("SELECT 1 FROM cages WHERE cage_id = ?");
        $check->bind_param("s", $new_cage_id);
        $check->execute();
        if ($check->get_result()->num_rows > 0) {
            $check->close();
            $_SESSION['message'] = "Cage ID '" . htmlspecialchars($new_cage_id) . "' already exists.";
            header("Location: hc_edit.php?id=" . urlencode($id));
            exit;
        }
        $check->close();

        $r = $con->prepare("UPDATE cages SET cage_id = ? WHERE cage_id = ?");
        $r->bind_param("ss", $new_cage_id, $id);
        $r->execute(); $r->close();
        $cageIdChanged = true;
        $oldId = $id;
        $id = $new_cage_id;
    }

    // Update cage metadata
    $u = $con->prepare("UPDATE cages SET pi_name = ?, remarks = ?, room = ?, rack = ? WHERE cage_id = ?");
    $u->bind_param("issss", $pi_name, $remarks, $room, $rack, $id);
    $u->execute(); $u->close();

    // Replace cage_users
    $previousUsers = $selectedUsers;
    $du = $con->prepare("DELETE FROM cage_users WHERE cage_id = ?");
    $du->bind_param("s", $id); $du->execute(); $du->close();
    if ($users) {
        $iu = $con->prepare("INSERT INTO cage_users (cage_id, user_id) VALUES (?, ?)");
        foreach (array_filter($users) as $uid) {
            $uidInt = (int)$uid;
            $iu->bind_param("si", $id, $uidInt);
            $iu->execute();
        }
        $iu->close();
    }

    // Replace cage_iacuc
    $diq = $con->prepare("DELETE FROM cage_iacuc WHERE cage_id = ?");
    $diq->bind_param("s", $id); $diq->execute(); $diq->close();
    if ($iacuc) {
        $iiq = $con->prepare("INSERT INTO cage_iacuc (cage_id, iacuc_id) VALUES (?, ?)");
        foreach (array_filter($iacuc) as $iac) {
            $iiq->bind_param("ss", $id, $iac);
            $iiq->execute();
        }
        $iiq->close();
    }

    // Notifications
    try {
        $editorName = $_SESSION['name'] ?? 'Someone';
        $newSet  = array_map('intval', $users);
        $oldSet  = array_map('intval', $previousUsers);
        $added   = array_diff($newSet, $oldSet);
        $removed = array_diff($oldSet, $newSet);

        $sendNote = function ($uid, $title, $msg, $link) use ($con) {
            $n = $con->prepare("INSERT INTO notifications (user_id, title, message, link, type) VALUES (?, ?, ?, ?, 'system')");
            $n->bind_param("isss", $uid, $title, $msg, $link);
            $n->execute(); $n->close();
        };
        foreach ($added as $uid) {
            if ($uid > 0) $sendNote($uid, "Added to Cage: $id", "$editorName added you to holding cage $id", "hc_view.php?id=" . urlencode($id));
        }
        foreach ($removed as $uid) {
            if ($uid > 0) $sendNote($uid, "Removed from Cage: $id", "$editorName removed you from holding cage $id", "hc_dash.php");
        }
    } catch (Exception $e) {
        error_log("Notification error: " . $e->getMessage());
    }

    log_activity($con, 'update', 'cage', $id,
        ($cageIdChanged ? "Renamed from $oldId. " : '') . 'Cage metadata updated');
    $_SESSION['message'] = ($cageIdChanged ? "Cage renamed from '$oldId' to '$id'. " : '') . 'Cage updated.';

    header("Location: hc_view.php?id=" . urlencode($id));
    exit;
}

require 'header.php';
?>
<!doctype html>
<html lang="en">
<head>
    <title>Edit Holding Cage | <?= htmlspecialchars($labName); ?></title>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.1.0-beta.1/js/select2.min.js"></script>
    <style>
        .container { max-width: 900px; background-color: var(--bs-tertiary-bg); padding: 20px; border-radius: 8px; margin: auto; }
        .form-label { font-weight: bold; }
        .required-asterisk { color: red; }
        .select2-container .select2-selection--single { height: 38px; }
        .select2-container--default .select2-selection--single .select2-selection__rendered { line-height: 38px; padding-left: 12px; }
    </style>
</head>
<body>
<div class="container mt-4 content">
    <h4>Edit Holding Cage <small class="text-muted"><?= htmlspecialchars($cage['cage_id']); ?></small></h4>
    <?php include 'message.php'; ?>
    <p class="text-muted">Mice in this cage are first-class records — manage them from <a href="hc_view.php?id=<?= rawurlencode($cage['cage_id']); ?>">the cage view</a> (each row links to mouse_view).</p>

    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']); ?>">
        <input type="hidden" name="cage_id"    value="<?= htmlspecialchars($cage['cage_id']); ?>">

        <div class="mb-3">
            <label for="new_cage_id" class="form-label">Cage ID <span class="required-asterisk">*</span></label>
            <input type="text" id="new_cage_id" name="new_cage_id" class="form-control" required maxlength="255"
                   value="<?= htmlspecialchars($cage['cage_id']); ?>">
            <small class="text-muted">Renaming propagates automatically to mice, history, breeding, files, etc.</small>
        </div>

        <div class="mb-3">
            <label for="pi_name" class="form-label">PI Name</label>
            <select id="pi_name" name="pi_name" class="form-control">
                <option value="">— None —</option>
                <?php while ($row = $piResult->fetch_assoc()): ?>
                    <option value="<?= htmlspecialchars($row['id']); ?>" <?= $cage['pi_name'] == $row['id'] ? 'selected' : ''; ?>>
                        <?= htmlspecialchars($row['initials'] . ' [' . $row['name'] . ']'); ?>
                    </option>
                <?php endwhile; ?>
            </select>
        </div>

        <div class="row">
            <div class="col-md-6 mb-3">
                <label for="room" class="form-label">Room</label>
                <input type="text" id="room" name="room" class="form-control" value="<?= htmlspecialchars($cage['room'] ?? ''); ?>">
            </div>
            <div class="col-md-6 mb-3">
                <label for="rack" class="form-label">Rack</label>
                <input type="text" id="rack" name="rack" class="form-control" value="<?= htmlspecialchars($cage['rack'] ?? ''); ?>">
            </div>
        </div>

        <div class="mb-3">
            <label for="iacuc" class="form-label">IACUC</label>
            <select id="iacuc" name="iacuc[]" class="form-control" multiple>
                <?php while ($iac = $iacucResult->fetch_assoc()): ?>
                    <option value="<?= htmlspecialchars($iac['iacuc_id']); ?>"
                        <?= in_array($iac['iacuc_id'], $selectedIacucs, true) ? 'selected' : ''; ?>>
                        <?= htmlspecialchars($iac['iacuc_id'] . ' | ' . $iac['iacuc_title']); ?>
                    </option>
                <?php endwhile; ?>
            </select>
        </div>

        <div class="mb-3">
            <label for="user" class="form-label">Users</label>
            <select id="user" name="user[]" class="form-control" multiple>
                <?php while ($u = $userResult->fetch_assoc()):
                    $sel = in_array($u['id'], $selectedUsers) ? 'selected' : '';
                ?>
                    <option value="<?= htmlspecialchars($u['id']); ?>" <?= $sel; ?>>
                        <?= htmlspecialchars($u['initials'] . ' [' . $u['name'] . ']'); ?>
                    </option>
                <?php endwhile; ?>
            </select>
        </div>

        <div class="mb-3">
            <label for="remarks" class="form-label">Remarks</label>
            <textarea id="remarks" name="remarks" class="form-control" rows="3"><?= htmlspecialchars($cage['remarks'] ?? ''); ?></textarea>
        </div>

        <button type="submit" class="btn btn-primary">Save</button>
        <a href="hc_view.php?id=<?= rawurlencode($cage['cage_id']); ?>" class="btn btn-secondary">Cancel</a>
    </form>

    <?php if ($files): ?>
    <hr>
    <h5>Attached Files</h5>
    <ul>
        <?php foreach ($files as $f): ?>
            <li><a href="<?= htmlspecialchars($f['file_path']); ?>" download="<?= htmlspecialchars($f['file_name']); ?>"><?= htmlspecialchars($f['file_name']); ?></a></li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
</div>
<br>
<?php include 'footer.php'; ?>
<script>
$(document).ready(function () {
    $('#pi_name').select2({ placeholder: 'Select PI', allowClear: true, width: '100%' });
    $('#iacuc').select2({ placeholder: 'Select IACUC', allowClear: true, width: '100%' });
    $('#user').select2({ placeholder: 'Select user(s)', allowClear: true, width: '100%' });
});
</script>
</body>
</html>
