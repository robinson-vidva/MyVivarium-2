<?php

/**
 * Add New Holding Cage
 *
 * v2: cage-only form. Mice are now first-class entities and registered via
 * mouse_addn.php (which itself can create a cage inline via Aaron's
 * "+ Add new cage" dropdown UX). This form only manages cage metadata
 * (PI, room, rack, IACUC, users, remarks) — no per-mouse fields, no
 * `holding` table (dropped in the v2 migration).
 *
 * After creating the cage, the user is offered a quick link to add mice
 * directly into the new cage.
 */

require 'session_config.php';
require 'dbcon.php';
require_once 'log_activity.php';

if (!isset($_SESSION['username'])) {
    $currentUrl = urlencode($_SERVER['REQUEST_URI']);
    header("Location: index.php?redirect=$currentUrl");
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Current user (for default user-assignment selection)
$currentUserId = null;
if ($r = $con->prepare("SELECT id FROM users WHERE username = ?")) {
    $r->bind_param("s", $_SESSION['username']);
    $r->execute();
    $row = $r->get_result()->fetch_assoc();
    $currentUserId = $row['id'] ?? null;
    $r->close();
}

$userResult  = $con->query("SELECT id, initials, name FROM users WHERE status = 'approved'");
$piResult    = $con->query("SELECT id, initials, name FROM users WHERE position = 'Principal Investigator' AND status = 'approved'");
$iacucResult = $con->query("SELECT iacuc_id, iacuc_title FROM iacuc");

$piOptions = [];
while ($row = $piResult->fetch_assoc()) $piOptions[] = $row;

// Optional clone-from-cage (cage metadata only, mice are now independent and don't auto-clone)
$cloneData  = null;
$cloneUsers = [];
$cloneIacuc = [];
if (isset($_GET['clone'])) {
    $cloneId = trim($_GET['clone']);
    $cs = $con->prepare("SELECT cage_id, pi_name, remarks, room, rack FROM cages WHERE cage_id = ?");
    $cs->bind_param("s", $cloneId);
    $cs->execute();
    $cr = $cs->get_result();
    if ($cr->num_rows === 1) $cloneData = $cr->fetch_assoc();
    $cs->close();

    if ($cloneData) {
        $cu = $con->prepare("SELECT user_id FROM cage_users WHERE cage_id = ?");
        $cu->bind_param("s", $cloneId); $cu->execute();
        while ($row = $cu->get_result()->fetch_assoc()) $cloneUsers[] = $row['user_id'];
        $cu->close();

        $ci = $con->prepare("SELECT iacuc_id FROM cage_iacuc WHERE cage_id = ?");
        $ci->bind_param("s", $cloneId); $ci->execute();
        while ($row = $ci->get_result()->fetch_assoc()) $cloneIacuc[] = $row['iacuc_id'];
        $ci->close();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die('CSRF token validation failed');
    }

    $cage_id = trim($_POST['cage_id']);
    $pi_name = !empty($_POST['pi_name']) ? trim($_POST['pi_name']) : null;
    $room    = !empty($_POST['room']) ? trim($_POST['room']) : null;
    $rack    = !empty($_POST['rack']) ? trim($_POST['rack']) : null;
    $iacuc   = isset($_POST['iacuc']) ? (array)$_POST['iacuc'] : [];
    $users   = isset($_POST['user'])  ? (array)$_POST['user']  : [];
    $remarks = trim($_POST['remarks'] ?? '');

    $check = $con->prepare("SELECT 1 FROM cages WHERE cage_id = ?");
    $check->bind_param("s", $cage_id);
    $check->execute();
    if ($check->get_result()->num_rows > 0) {
        $_SESSION['message'] = "Cage ID '" . htmlspecialchars($cage_id) . "' already exists.";
        header("Location: hc_addn.php"); exit;
    }
    $check->close();

    mysqli_begin_transaction($con);
    try {
        $ins = $con->prepare("INSERT INTO cages (cage_id, pi_name, quantity, remarks, room, rack) VALUES (?, ?, 0, ?, ?, ?)");
        $ins->bind_param("sisss", $cage_id, $pi_name, $remarks, $room, $rack);
        $ins->execute();
        $ins->close();

        foreach (array_filter($iacuc) as $iac) {
            $s = $con->prepare("INSERT INTO cage_iacuc (cage_id, iacuc_id) VALUES (?, ?)");
            $s->bind_param("ss", $cage_id, $iac); $s->execute(); $s->close();
        }
        foreach (array_filter($users) as $uid) {
            $s = $con->prepare("INSERT INTO cage_users (cage_id, user_id) VALUES (?, ?)");
            $s->bind_param("si", $cage_id, $uid); $s->execute(); $s->close();
        }

        // Notify users newly assigned
        $creatorName = $_SESSION['name'] ?? 'Someone';
        foreach (array_filter($users) as $uid) {
            $uid = (int)$uid;
            if ($uid <= 0) continue;
            $title   = "Added to Cage: $cage_id";
            $message = "$creatorName added you to holding cage $cage_id";
            $link    = "hc_view.php?id=" . urlencode($cage_id);
            $n = $con->prepare("INSERT INTO notifications (user_id, title, message, link, type) VALUES (?, ?, ?, ?, 'system')");
            $n->bind_param("isss", $uid, $title, $message, $link);
            $n->execute(); $n->close();
        }

        mysqli_commit($con);
        log_activity($con, 'create', 'cage', $cage_id, 'Created holding cage');

        $_SESSION['message'] = "Cage '<strong>" . htmlspecialchars($cage_id) . "</strong>' created. "
            . "<a href='mouse_addn.php?cage_id=" . urlencode($cage_id) . "'>Add mice to this cage</a> "
            . "or <a href='hc_view.php?id=" . urlencode($cage_id) . "'>view it</a>.";
    } catch (Exception $e) {
        mysqli_rollback($con);
        $_SESSION['message'] = 'Failed to create cage: ' . htmlspecialchars($e->getMessage());
    }

    header("Location: hc_dash.php");
    exit;
}

require 'header.php';
?>
<!doctype html>
<html lang="en">
<head>
    <title>Add New Holding Cage | <?= htmlspecialchars($labName); ?></title>
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
    <h4>Add New Holding Cage<?php if ($cloneData): ?> <small class="text-muted">(Cloning from <?= htmlspecialchars($_GET['clone']); ?>)</small><?php endif; ?></h4>
    <?php include 'message.php'; ?>
    <p class="text-muted">This creates the cage container only. Mice are independent records — register them via <a href="mouse_addn.php">Add Mouse</a> after.</p>

    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']); ?>">

        <div class="mb-3">
            <label for="cage_id" class="form-label">Cage ID <span class="required-asterisk">*</span></label>
            <input type="text" id="cage_id" name="cage_id" class="form-control" required maxlength="255">
        </div>

        <div class="mb-3">
            <label for="pi_name" class="form-label">PI Name</label>
            <select id="pi_name" name="pi_name" class="form-control">
                <option value="">Select PI</option>
                <?php
                $autoSelect = (count($piOptions) === 1);
                foreach ($piOptions as $row):
                    $selected = '';
                    if ($cloneData) {
                        $selected = ($cloneData['pi_name'] == $row['id']) ? 'selected' : '';
                    } elseif ($autoSelect) {
                        $selected = 'selected';
                    }
                ?>
                    <option value="<?= htmlspecialchars($row['id']); ?>" <?= $selected; ?>>
                        <?= htmlspecialchars($row['initials'] . ' [' . $row['name'] . ']'); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="row">
            <div class="col-md-6 mb-3">
                <label for="room" class="form-label">Room</label>
                <input type="text" id="room" name="room" class="form-control" value="<?= htmlspecialchars($cloneData['room'] ?? ''); ?>">
            </div>
            <div class="col-md-6 mb-3">
                <label for="rack" class="form-label">Rack</label>
                <input type="text" id="rack" name="rack" class="form-control" value="<?= htmlspecialchars($cloneData['rack'] ?? ''); ?>">
            </div>
        </div>

        <div class="mb-3">
            <label for="iacuc" class="form-label">IACUC</label>
            <select id="iacuc" name="iacuc[]" class="form-control" multiple>
                <?php while ($iac = $iacucResult->fetch_assoc()): ?>
                    <option value="<?= htmlspecialchars($iac['iacuc_id']); ?>"
                        <?= in_array($iac['iacuc_id'], $cloneIacuc, true) ? 'selected' : ''; ?>>
                        <?= htmlspecialchars($iac['iacuc_id'] . ' | ' . $iac['iacuc_title']); ?>
                    </option>
                <?php endwhile; ?>
            </select>
        </div>

        <div class="mb-3">
            <label for="user" class="form-label">Users</label>
            <select id="user" name="user[]" class="form-control" multiple>
                <?php while ($u = $userResult->fetch_assoc()):
                    if ($cloneData) {
                        $sel = in_array($u['id'], $cloneUsers, true) ? 'selected' : '';
                    } else {
                        $sel = ($currentUserId && $u['id'] == $currentUserId) ? 'selected' : '';
                    }
                ?>
                    <option value="<?= htmlspecialchars($u['id']); ?>" <?= $sel; ?>>
                        <?= htmlspecialchars($u['initials'] . ' [' . $u['name'] . ']'); ?>
                    </option>
                <?php endwhile; ?>
            </select>
        </div>

        <div class="mb-3">
            <label for="remarks" class="form-label">Remarks</label>
            <textarea id="remarks" name="remarks" class="form-control" rows="3"><?= htmlspecialchars($cloneData['remarks'] ?? ''); ?></textarea>
        </div>

        <button type="submit" class="btn btn-primary">Create Cage</button>
        <a href="hc_dash.php" class="btn btn-secondary">Cancel</a>
    </form>
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
