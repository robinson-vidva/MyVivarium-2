<?php

/**
 * Edit Mouse
 *
 * Editing the canonical mouse record. mouse_id can be renamed; ON UPDATE
 * CASCADE on every FK pointing into mice (sire/dam self-FK, breeding,
 * mouse_cage_history) means the rename propagates automatically.
 *
 * Cage moves are NOT done from this form — they go through mouse_move.php so
 * the cage history log stays consistent. Sacrifice/archive likewise has its
 * own endpoint.
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

$mouse_id = trim($_GET['id'] ?? $_POST['original_mouse_id'] ?? '');
if ($mouse_id === '') {
    $_SESSION['message'] = 'Missing mouse ID.';
    header('Location: mouse_dash.php');
    exit;
}

$stmt = $con->prepare("SELECT * FROM mice WHERE mouse_id = ?");
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

$strainRows = [];
$rs = $con->query("SELECT str_id, str_name FROM strains ORDER BY str_id");
while ($r = $rs->fetch_assoc()) $strainRows[] = $r;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die('CSRF token validation failed');
    }

    $new_id   = trim($_POST['mouse_id'] ?? '');
    $sex      = strtolower(trim($_POST['sex'] ?? 'unknown'));
    $dob      = !empty($_POST['dob']) ? trim($_POST['dob']) : null;
    $strain   = !empty($_POST['strain']) ? trim($_POST['strain']) : null;
    $genotype = !empty($_POST['genotype']) ? trim($_POST['genotype']) : null;
    $ear_code = !empty($_POST['ear_code']) ? trim($_POST['ear_code']) : null;
    $sire_id  = !empty($_POST['sire_id']) ? trim($_POST['sire_id']) : null;
    $dam_id   = !empty($_POST['dam_id']) ? trim($_POST['dam_id']) : null;
    $sire_ext      = !empty($_POST['sire_external_ref']) ? trim($_POST['sire_external_ref']) : null;
    $dam_ext       = !empty($_POST['dam_external_ref']) ? trim($_POST['dam_external_ref']) : null;
    $source_cage   = !empty($_POST['source_cage_label']) ? trim($_POST['source_cage_label']) : null;
    $notes         = !empty($_POST['notes']) ? trim($_POST['notes']) : null;

    if (!in_array($sex, ['male','female','unknown'], true)) $sex = 'unknown';

    $errors = [];
    if ($new_id === '') $errors[] = 'Mouse ID is required.';
    if ($sex === 'unknown') $errors[] = 'Sex is required.';
    if (!$dob) $errors[] = 'DOB is required.';
    if ($sire_id && $sire_id === $new_id) $errors[] = 'A mouse cannot be its own sire.';
    if ($dam_id  && $dam_id  === $new_id) $errors[] = 'A mouse cannot be its own dam.';

    if (!$errors && $new_id !== $mouse_id) {
        $check = $con->prepare("SELECT 1 FROM mice WHERE mouse_id = ?");
        $check->bind_param("s", $new_id);
        $check->execute();
        if ($check->get_result()->num_rows > 0) {
            $errors[] = "Mouse ID '" . htmlspecialchars($new_id) . "' already in use.";
        }
        $check->close();
    }

    if (!$errors) {
        try {
            $upd = $con->prepare("
                UPDATE mice SET
                  mouse_id = ?, sex = ?, dob = ?, strain = ?, genotype = ?, ear_code = ?,
                  sire_id = ?, dam_id = ?, sire_external_ref = ?, dam_external_ref = ?,
                  source_cage_label = ?, notes = ?
                WHERE mouse_id = ?
            ");
            $upd->bind_param(
                "sssssssssssss",
                $new_id, $sex, $dob, $strain, $genotype, $ear_code,
                $sire_id, $dam_id, $sire_ext, $dam_ext,
                $source_cage, $notes, $mouse_id
            );
            $upd->execute();
            $upd->close();

            log_activity($con, 'update', 'mouse', $new_id,
                $new_id !== $mouse_id ? "Renamed from $mouse_id" : 'Edited fields');

            $_SESSION['message'] = "Mouse updated.";
            header("Location: mouse_view.php?id=" . urlencode($new_id));
            exit;
        } catch (Exception $e) {
            $errors[] = 'Update failed: ' . $e->getMessage();
        }
    }

    $_SESSION['message'] = implode('<br>', array_map('htmlspecialchars', $errors));
    // re-render with submitted values
    $mouse = array_merge($mouse, [
        'mouse_id' => $new_id, 'sex' => $sex, 'dob' => $dob, 'strain' => $strain,
        'genotype' => $genotype, 'ear_code' => $ear_code,
        'sire_id' => $sire_id, 'dam_id' => $dam_id,
        'sire_external_ref' => $sire_ext, 'dam_external_ref' => $dam_ext,
        'source_cage_label' => $source_cage,
        'notes' => $notes,
    ]);
}

require 'header.php';
?>
<!doctype html>
<html lang="en">
<head>
    <title>Edit Mouse | <?= htmlspecialchars($labName); ?></title>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.1.0-beta.1/js/select2.min.js"></script>
    <style>
        .container { max-width: 900px; background: var(--bs-tertiary-bg); padding: 20px; border-radius: 8px; margin: auto; }
        .form-label { font-weight: bold; }
        .required-asterisk { color: red; }
        .parent-block { background: var(--bs-body-bg); padding: 12px; border-radius: 6px; margin-bottom: 14px; }
        .select2-container .select2-selection--single { height: 38px; }
        .select2-container--default .select2-selection--single .select2-selection__rendered { line-height: 38px; padding-left: 12px; }
    </style>
</head>
<body>
<div class="container mt-4 content">
    <h4>Edit Mouse <small class="text-muted">(<?= htmlspecialchars($mouse['mouse_id']); ?>)</small></h4>
    <?php include 'message.php'; ?>
    <p class="text-muted">To change cage, use the Move button on the mouse view page (so the move appears in the history log). To mark sacrificed, use the Sacrifice button.</p>

    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']); ?>">
        <input type="hidden" name="original_mouse_id" value="<?= htmlspecialchars($mouse['mouse_id']); ?>">

        <div class="mb-3">
            <label for="mouse_id" class="form-label">Mouse ID <span class="required-asterisk">*</span></label>
            <input type="text" id="mouse_id" name="mouse_id" class="form-control" required maxlength="255" value="<?= htmlspecialchars($mouse['mouse_id']); ?>">
            <small class="text-muted">Renaming this updates every reference automatically (sire/dam, breeding, history).</small>
        </div>

        <div class="row">
            <div class="col-md-6 mb-3">
                <label for="sex" class="form-label">Sex <span class="required-asterisk">*</span></label>
                <select id="sex" name="sex" class="form-control" required>
                    <option value="">— select —</option>
                    <option value="male"   <?= $mouse['sex'] === 'male'   ? 'selected' : ''; ?>>Male</option>
                    <option value="female" <?= $mouse['sex'] === 'female' ? 'selected' : ''; ?>>Female</option>
                </select>
            </div>
            <div class="col-md-6 mb-3">
                <label for="dob" class="form-label">DOB <span class="required-asterisk">*</span></label>
                <input type="date" id="dob" name="dob" class="form-control" required value="<?= htmlspecialchars($mouse['dob'] ?? ''); ?>">
            </div>
        </div>

        <div class="row">
            <div class="col-md-6 mb-3">
                <label for="strain" class="form-label">Strain</label>
                <select id="strain" name="strain" class="form-control">
                    <option value="">— None —</option>
                    <?php foreach ($strainRows as $s): ?>
                        <option value="<?= htmlspecialchars($s['str_id']); ?>" <?= $s['str_id'] === $mouse['strain'] ? 'selected' : ''; ?>>
                            <?= htmlspecialchars($s['str_id'] . ' | ' . $s['str_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-6 mb-3">
                <label for="ear_code" class="form-label">Ear Code</label>
                <input type="text" id="ear_code" name="ear_code" class="form-control" maxlength="64" value="<?= htmlspecialchars($mouse['ear_code'] ?? ''); ?>">
            </div>
        </div>

        <div class="mb-3">
            <label for="genotype" class="form-label">Genotype</label>
            <input type="text" id="genotype" name="genotype" class="form-control" maxlength="255" value="<?= htmlspecialchars($mouse['genotype'] ?? ''); ?>">
        </div>

        <div class="parent-block">
            <h6>Sire (Father)</h6>
            <div class="mb-2">
                <label for="sire_id" class="form-label">Existing Mouse</label>
                <select id="sire_id" name="sire_id" class="form-control parent-picker" data-sex-filter="male">
                    <?php if (!empty($mouse['sire_id'])): ?>
                        <option value="<?= htmlspecialchars($mouse['sire_id']); ?>" selected><?= htmlspecialchars($mouse['sire_id']); ?></option>
                    <?php else: ?>
                        <option value="">— None —</option>
                    <?php endif; ?>
                </select>
            </div>
            <div>
                <label for="sire_external_ref" class="form-label">External reference</label>
                <input type="text" id="sire_external_ref" name="sire_external_ref" class="form-control" maxlength="255" value="<?= htmlspecialchars($mouse['sire_external_ref'] ?? ''); ?>">
            </div>
        </div>

        <div class="parent-block">
            <h6>Dam (Mother)</h6>
            <div class="mb-2">
                <label for="dam_id" class="form-label">Existing Mouse</label>
                <select id="dam_id" name="dam_id" class="form-control parent-picker" data-sex-filter="female">
                    <?php if (!empty($mouse['dam_id'])): ?>
                        <option value="<?= htmlspecialchars($mouse['dam_id']); ?>" selected><?= htmlspecialchars($mouse['dam_id']); ?></option>
                    <?php else: ?>
                        <option value="">— None —</option>
                    <?php endif; ?>
                </select>
            </div>
            <div>
                <label for="dam_external_ref" class="form-label">External reference</label>
                <input type="text" id="dam_external_ref" name="dam_external_ref" class="form-control" maxlength="255" value="<?= htmlspecialchars($mouse['dam_external_ref'] ?? ''); ?>">
            </div>
        </div>

        <div class="mb-3">
            <label for="source_cage_label" class="form-label">Source Cage <small class="text-muted">(optional)</small></label>
            <input type="text" id="source_cage_label" name="source_cage_label" class="form-control" maxlength="255"
                   value="<?= htmlspecialchars($mouse['source_cage_label'] ?? ''); ?>"
                   placeholder="e.g. MDp16-2 — the cage this mouse came from">
            <small class="text-muted">Free-text label for the cage this mouse originated from. Used as a breadcrumb when specific Sire/Dam aren't known (typical for V1-imported mice).</small>
        </div>

        <div class="mb-3">
            <label for="notes" class="form-label">Notes</label>
            <textarea id="notes" name="notes" class="form-control" rows="3"><?= htmlspecialchars($mouse['notes'] ?? ''); ?></textarea>
        </div>

        <button type="submit" class="btn btn-primary">Save</button>
        <a href="mouse_view.php?id=<?= rawurlencode($mouse['mouse_id']); ?>" class="btn btn-secondary">Cancel</a>
    </form>
</div>
<br>
<?php include 'footer.php'; ?>

<script>
$(document).ready(function () {
    $('#sex, #strain').select2({ allowClear: true, width: '100%' });
    var today = new Date().toISOString().split('T')[0];
    document.getElementById('dob').setAttribute('max', today);

    $('.parent-picker').each(function () {
        var $el = $(this);
        var sex = $el.data('sex-filter');
        $el.select2({
            placeholder: 'Search by Mouse ID…',
            allowClear: true,
            width: '100%',
            minimumInputLength: 1,
            ajax: {
                url: 'mouse_fetch_data.php', dataType: 'json', delay: 250,
                data: function (params) { return { search: params.term, sex: sex, mode: 'parent_search' }; },
                processResults: function (data) {
                    return { results: (data.results || []).map(function (r) {
                        return { id: r.mouse_id, text: r.mouse_id + (r.dob ? ' (DOB ' + r.dob + ')' : '') };
                    }) };
                }
            }
        });
    });
});
</script>
</body>
</html>
