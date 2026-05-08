<?php

/**
 * Add New Mouse
 *
 * Creates a single mouse as a first-class entity. The cage field is a Select2
 * dropdown over active cages with an "+ Add new cage" option that opens an
 * inline modal (Aaron's UX) — that modal POSTs to hc_addn.php's handler via
 * AJAX, then auto-selects the new cage in the parent form.
 *
 * Required: mouse_id, sex, dob. Cage is required for an "alive" mouse but
 * may be left blank for a planned/incoming mouse — confirm dialog covers that
 * edge case.
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

$prefillCageId = isset($_GET['cage_id']) ? trim($_GET['cage_id']) : '';

// Cages, strains for dropdowns
$cageRows = [];
$cageQuery = "SELECT cage_id FROM cages WHERE status = 'active' ORDER BY cage_id";
if ($r = $con->query($cageQuery)) {
    while ($row = $r->fetch_assoc()) $cageRows[] = $row['cage_id'];
}

$strainRows = [];
$strainQuery = "SELECT str_id, str_name FROM strains ORDER BY str_id";
if ($r = $con->query($strainQuery)) {
    while ($row = $r->fetch_assoc()) $strainRows[] = $row;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die('CSRF token validation failed');
    }

    $mouse_id   = trim($_POST['mouse_id'] ?? '');
    $sex        = strtolower(trim($_POST['sex'] ?? 'unknown'));
    $dob        = !empty($_POST['dob']) ? trim($_POST['dob']) : null;
    $cage_id    = !empty($_POST['cage_id']) ? trim($_POST['cage_id']) : null;
    $strain     = !empty($_POST['strain']) ? trim($_POST['strain']) : null;
    $genotype   = !empty($_POST['genotype']) ? trim($_POST['genotype']) : null;
    $ear_code   = !empty($_POST['ear_code']) ? trim($_POST['ear_code']) : null;
    $sire_id    = !empty($_POST['sire_id']) ? trim($_POST['sire_id']) : null;
    $dam_id     = !empty($_POST['dam_id']) ? trim($_POST['dam_id']) : null;
    $sire_ext   = !empty($_POST['sire_external_ref']) ? trim($_POST['sire_external_ref']) : null;
    $dam_ext    = !empty($_POST['dam_external_ref']) ? trim($_POST['dam_external_ref']) : null;
    $notes      = !empty($_POST['notes']) ? trim($_POST['notes']) : null;
    $created_by = $_SESSION['user_id'] ?? null;

    if (!in_array($sex, ['male', 'female', 'unknown'], true)) $sex = 'unknown';

    $errors = [];
    if ($mouse_id === '') $errors[] = 'Mouse ID is required.';
    if ($sex === 'unknown') $errors[] = 'Sex is required (male/female).';
    if (!$dob) $errors[] = 'DOB is required.';

    if (!$errors) {
        $check = $con->prepare("SELECT 1 FROM mice WHERE mouse_id = ?");
        $check->bind_param("s", $mouse_id);
        $check->execute();
        if ($check->get_result()->num_rows > 0) {
            $errors[] = "Mouse ID '" . htmlspecialchars($mouse_id) . "' already exists. Pick a different one.";
        }
        $check->close();
    }

    if (!$errors) {
        mysqli_begin_transaction($con);
        try {
            $insert = $con->prepare("
                INSERT INTO mice
                  (mouse_id, sex, dob, current_cage_id, strain, genotype, ear_code,
                   sire_id, dam_id, sire_external_ref, dam_external_ref, status, notes, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'alive', ?, ?)
            ");
            $insert->bind_param(
                "sssssssssssss" . "i",
                $mouse_id, $sex, $dob, $cage_id, $strain, $genotype, $ear_code,
                $sire_id, $dam_id, $sire_ext, $dam_ext, $notes, $created_by
            );
            $insert->execute();
            $insert->close();

            if ($cage_id) {
                $hist = $con->prepare("
                    INSERT INTO mouse_cage_history (mouse_id, cage_id, reason, moved_by)
                    VALUES (?, ?, 'initial registration', ?)
                ");
                $hist->bind_param("ssi", $mouse_id, $cage_id, $created_by);
                $hist->execute();
                $hist->close();
            }

            mysqli_commit($con);
            log_activity($con, 'create', 'mouse', $mouse_id,
                "Registered mouse" . ($cage_id ? " in cage $cage_id" : " (no cage assigned)"));

            $_SESSION['message'] = "Mouse '<strong>" . htmlspecialchars($mouse_id) . "</strong>' registered successfully. <a href='mouse_view.php?id=" . urlencode($mouse_id) . "'>View mouse</a>";
            header("Location: mouse_dash.php");
            exit;
        } catch (Exception $e) {
            mysqli_rollback($con);
            $errors[] = 'Failed to register mouse: ' . $e->getMessage();
        }
    }

    $_SESSION['message'] = implode('<br>', array_map('htmlspecialchars', $errors));
}

require 'header.php';
?>
<!doctype html>
<html lang="en">
<head>
    <title>Add Mouse | <?= htmlspecialchars($labName); ?></title>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.1.0-beta.1/js/select2.min.js"></script>
    <style>
        .container { max-width: 900px; background-color: var(--bs-tertiary-bg); padding: 20px; border-radius: 8px; margin: auto; }
        .form-label { font-weight: bold; }
        .required-asterisk { color: red; }
        .select2-container .select2-selection--single { height: 38px; }
        .select2-container--default .select2-selection--single .select2-selection__rendered { line-height: 38px; padding-left: 12px; }
        .select2-container--default .select2-selection--single .select2-selection__arrow { height: 38px; }
        .parent-block { background-color: var(--bs-body-bg); padding: 12px; border-radius: 6px; margin-bottom: 14px; }
    </style>
</head>
<body>
    <div class="container mt-4 content">
        <h4>Register New Mouse</h4>
        <?php include 'message.php'; ?>
        <p class="text-muted">Required fields are marked <span class="required-asterisk">*</span>. Cage may be left blank for incoming/planned mice.</p>

        <form method="POST" id="mouse-form">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']); ?>">

            <div class="mb-3">
                <label for="mouse_id" class="form-label">Mouse ID <span class="required-asterisk">*</span></label>
                <input type="text" class="form-control" id="mouse_id" name="mouse_id" required maxlength="255"
                       placeholder="e.g. ts52_f1_lec or M-00042">
                <small class="text-muted">User-supplied, globally unique, but you can rename it later.</small>
            </div>

            <div class="row">
                <div class="col-md-6 mb-3">
                    <label for="sex" class="form-label">Sex <span class="required-asterisk">*</span></label>
                    <select class="form-control" id="sex" name="sex" required>
                        <option value="">Select Sex</option>
                        <option value="male">Male</option>
                        <option value="female">Female</option>
                    </select>
                </div>
                <div class="col-md-6 mb-3">
                    <label for="dob" class="form-label">DOB <span class="required-asterisk">*</span></label>
                    <input type="date" class="form-control" id="dob" name="dob" required min="1900-01-01">
                </div>
            </div>

            <div class="mb-3">
                <label for="cage_id" class="form-label">Cage</label>
                <select class="form-control" id="cage_id" name="cage_id">
                    <option value="">— No cage (incoming/planned) —</option>
                    <option value="__add_new__" data-add-new="1">+ Add new cage…</option>
                    <?php foreach ($cageRows as $cid): ?>
                        <option value="<?= htmlspecialchars($cid); ?>" <?= ($cid === $prefillCageId ? 'selected' : ''); ?>>
                            <?= htmlspecialchars($cid); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <small class="text-muted">Pick an existing active cage, or choose "+ Add new cage" to create one without leaving this form.</small>
            </div>

            <div class="row">
                <div class="col-md-6 mb-3">
                    <label for="strain" class="form-label">Strain</label>
                    <select class="form-control" id="strain" name="strain">
                        <option value="">— None —</option>
                        <?php foreach ($strainRows as $s): ?>
                            <option value="<?= htmlspecialchars($s['str_id']); ?>">
                                <?= htmlspecialchars($s['str_id'] . ' | ' . $s['str_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6 mb-3">
                    <label for="ear_code" class="form-label">Ear Code</label>
                    <input type="text" class="form-control" id="ear_code" name="ear_code" maxlength="64"
                           placeholder="e.g. RR, LL, R1L2">
                </div>
            </div>

            <div class="mb-3">
                <label for="genotype" class="form-label">Genotype</label>
                <input type="text" class="form-control" id="genotype" name="genotype" maxlength="255">
            </div>

            <div class="parent-block">
                <h6>Sire (Father)</h6>
                <div class="mb-2">
                    <label for="sire_id" class="form-label">Existing Mouse</label>
                    <select class="form-control parent-picker" id="sire_id" name="sire_id" data-sex-filter="male">
                        <option value="">— Not in system / Unknown —</option>
                    </select>
                </div>
                <div>
                    <label for="sire_external_ref" class="form-label">Or external reference (founder, outside lab, etc.)</label>
                    <input type="text" class="form-control" id="sire_external_ref" name="sire_external_ref" maxlength="255"
                           placeholder="e.g. Jackson 000664 founder, or '2023 transfer from Smith Lab'">
                </div>
            </div>

            <div class="parent-block">
                <h6>Dam (Mother)</h6>
                <div class="mb-2">
                    <label for="dam_id" class="form-label">Existing Mouse</label>
                    <select class="form-control parent-picker" id="dam_id" name="dam_id" data-sex-filter="female">
                        <option value="">— Not in system / Unknown —</option>
                    </select>
                </div>
                <div>
                    <label for="dam_external_ref" class="form-label">Or external reference</label>
                    <input type="text" class="form-control" id="dam_external_ref" name="dam_external_ref" maxlength="255">
                </div>
            </div>

            <div class="mb-3">
                <label for="notes" class="form-label">Notes</label>
                <textarea class="form-control" id="notes" name="notes" rows="3"></textarea>
            </div>

            <button type="submit" class="btn btn-primary">Register Mouse</button>
            <a href="mouse_dash.php" class="btn btn-secondary">Cancel</a>
        </form>
    </div>

    <!-- Inline "Add New Cage" Modal -->
    <div class="modal fade" id="addCageModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title">Add New Cage</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body">
            <div class="mb-3">
              <label for="newCageId" class="form-label">Cage ID <span class="required-asterisk">*</span></label>
              <input type="text" id="newCageId" class="form-control" maxlength="255">
            </div>
            <div class="mb-3">
              <label for="newCageRoom" class="form-label">Room</label>
              <input type="text" id="newCageRoom" class="form-control" maxlength="255">
            </div>
            <div class="mb-3">
              <label for="newCageRack" class="form-label">Rack</label>
              <input type="text" id="newCageRack" class="form-control" maxlength="255">
            </div>
            <div id="addCageError" class="text-danger small"></div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="button" id="addCageSubmit" class="btn btn-primary">Create Cage</button>
          </div>
        </div>
      </div>
    </div>

    <br>
    <?php include 'footer.php'; ?>

    <script>
    $(document).ready(function () {
        $('#sex, #strain').select2({ placeholder: '— select —', allowClear: true, width: '100%' });

        // Cage dropdown: handle "+ Add new cage" option
        $('#cage_id').select2({ placeholder: 'Select cage', allowClear: true, width: '100%',
            templateResult: function (data) {
                if (data.id === '__add_new__') {
                    return $('<span><i class="fas fa-plus-circle text-primary"></i> <strong>Add new cage…</strong></span>');
                }
                return data.text;
            }
        });
        $('#cage_id').on('select2:select', function (e) {
            if (e.params.data.id === '__add_new__') {
                $(this).val('').trigger('change');
                var modal = new bootstrap.Modal(document.getElementById('addCageModal'));
                modal.show();
            }
        });

        // Cap DOB to today
        var today = new Date().toISOString().split('T')[0];
        document.getElementById('dob').setAttribute('max', today);

        // Parent pickers — Select2 with AJAX search by mouse_id, sex-filtered
        $('.parent-picker').each(function () {
            var $el = $(this);
            var sex = $el.data('sex-filter');
            $el.select2({
                placeholder: 'Search by Mouse ID…',
                allowClear: true,
                width: '100%',
                minimumInputLength: 1,
                ajax: {
                    url: 'mouse_fetch_data.php',
                    dataType: 'json',
                    delay: 250,
                    data: function (params) {
                        return { search: params.term, sex: sex, mode: 'parent_search' };
                    },
                    processResults: function (data) {
                        return { results: (data.results || []).map(function (r) {
                            return { id: r.mouse_id, text: r.mouse_id + (r.dob ? ' (DOB ' + r.dob + ')' : '') };
                        }) };
                    }
                }
            });
        });

        // Inline cage creation
        $('#addCageSubmit').on('click', function () {
            var btn = $(this);
            var newId = $('#newCageId').val().trim();
            var room = $('#newCageRoom').val().trim();
            var rack = $('#newCageRack').val().trim();
            $('#addCageError').text('');
            if (!newId) { $('#addCageError').text('Cage ID is required.'); return; }
            btn.prop('disabled', true).text('Creating…');
            $.ajax({
                url: 'mouse_fetch_data.php',
                method: 'POST',
                dataType: 'json',
                data: {
                    mode: 'create_cage',
                    csrf_token: <?= json_encode($_SESSION['csrf_token']); ?>,
                    cage_id: newId, room: room, rack: rack
                }
            }).done(function (resp) {
                if (resp.ok) {
                    var newOption = new Option(newId, newId, true, true);
                    $('#cage_id').append(newOption).trigger('change');
                    bootstrap.Modal.getInstance(document.getElementById('addCageModal')).hide();
                    $('#newCageId, #newCageRoom, #newCageRack').val('');
                } else {
                    $('#addCageError').text(resp.error || 'Failed to create cage.');
                }
            }).fail(function () {
                $('#addCageError').text('Network error creating cage.');
            }).always(function () {
                btn.prop('disabled', false).text('Create Cage');
            });
        });
    });
    </script>
</body>
</html>
