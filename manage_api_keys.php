<?php
/**
 * API Keys admin page.
 *
 * Admins can generate, list, and revoke API keys here. The raw key value is
 * shown to the admin exactly once at creation time (and never again — we
 * only persist the sha256 hash). Revoking a key takes effect immediately on
 * the next request hitting the API.
 */

require 'session_config.php';
require 'dbcon.php';
require_once 'log_activity.php';
require_once __DIR__ . '/services/api_keys.php';

if (!isset($_SESSION['username']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: index.php');
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$justCreatedRaw = null;
$justCreatedLabel = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die('CSRF token validation failed');
    }
    $action = $_POST['action'] ?? '';
    if ($action === 'create') {
        $target_user = (int)($_POST['user_id'] ?? 0);
        $label       = trim($_POST['label'] ?? '');
        $scopes      = [];
        if (!empty($_POST['scope_read']))  $scopes[] = 'read';
        if (!empty($_POST['scope_write'])) $scopes[] = 'write';

        if ($target_user <= 0)         $_SESSION['message'] = 'Pick a user for the key.';
        elseif ($label === '')         $_SESSION['message'] = 'Label is required.';
        elseif (count($scopes) === 0)  $_SESSION['message'] = 'Select at least one scope.';
        else {
            try {
                $res = api_key_create($con, $target_user, $label, $scopes);
                $justCreatedRaw   = $res['raw'];
                $justCreatedLabel = $label;
                log_activity($con, 'create', 'api_key', (string)$res['row']['id'],
                    "Created API key for user $target_user (scopes: " . implode(',', $scopes) . ")");
            } catch (Throwable $e) {
                $_SESSION['message'] = 'Failed to create key: ' . htmlspecialchars($e->getMessage());
            }
        }
    } elseif ($action === 'revoke') {
        $key_id = (int)($_POST['key_id'] ?? 0);
        if ($key_id > 0) {
            api_key_revoke($con, $key_id);
            log_activity($con, 'revoke', 'api_key', (string)$key_id, 'Revoked API key');
            $_SESSION['message'] = 'API key revoked.';
        }
        header('Location: manage_api_keys.php');
        exit;
    }
}

$keys  = api_key_list($con);
$users = [];
$ures = $con->query("SELECT id, name, username FROM users WHERE status = 'approved' ORDER BY name");
while ($u = $ures->fetch_assoc()) $users[] = $u;

require 'header.php';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>API Keys | <?= htmlspecialchars($labName); ?></title>
    <style>
        .container { max-width: 1100px; }
        .api-key-card { background-color: var(--bs-tertiary-bg); padding: 18px; border-radius: 8px; margin-bottom: 18px; }
        .api-key-raw { font-family: monospace; background: var(--bs-body-bg); padding: 10px; border-radius: 4px; word-break: break-all; }
        .badge-scope { background-color: #6c757d; color: white; padding: 2px 8px; border-radius: 3px; font-size: 0.85em; margin-right: 4px; }
        .badge-scope.write { background-color: #dc3545; }
        .badge-scope.read  { background-color: #0d6efd; }
    </style>
</head>
<body>
<div class="container mt-4 content">
    <h1>API Keys</h1>
    <p class="text-muted">REST API access tokens. The raw key value is shown once at creation; only the hash is stored. Revoking a key stops it from working immediately.</p>
    <?php include 'message.php'; ?>

    <?php if ($justCreatedRaw !== null): ?>
        <div class="alert alert-warning">
            <strong>New API key created for "<?= htmlspecialchars($justCreatedLabel); ?>".</strong>
            Copy it now — it will <strong>not</strong> be shown again.
            <div class="api-key-raw mt-2" id="rawKeyBox"><?= htmlspecialchars($justCreatedRaw); ?></div>
            <button class="btn btn-sm btn-primary mt-2" type="button" onclick="copyRawKey()">
                <i class="fas fa-copy"></i> Copy to clipboard
            </button>
            <span id="copyMsg" class="ms-2 text-success" style="display:none;">Copied.</span>
        </div>
    <?php endif; ?>

    <div class="api-key-card">
        <h4>Generate a new key</h4>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']); ?>">
            <input type="hidden" name="action" value="create">
            <div class="row">
                <div class="col-md-5 mb-2">
                    <label class="form-label">User</label>
                    <select name="user_id" class="form-control" required>
                        <option value="">— Select user —</option>
                        <?php foreach ($users as $u): ?>
                            <option value="<?= (int)$u['id']; ?>"><?= htmlspecialchars($u['name'] . ' (' . $u['username'] . ')'); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4 mb-2">
                    <label class="form-label">Label</label>
                    <input type="text" name="label" class="form-control" maxlength="255" placeholder="e.g. lab-chatbot prod" required>
                </div>
                <div class="col-md-3 mb-2">
                    <label class="form-label">Scopes</label>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="scope_read" id="scope_read" checked>
                        <label class="form-check-label" for="scope_read">read</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="scope_write" id="scope_write">
                        <label class="form-check-label" for="scope_write">write</label>
                    </div>
                </div>
            </div>
            <button type="submit" class="btn btn-primary"><i class="fas fa-plus"></i> Generate Key</button>
        </form>
    </div>

    <h4>Existing keys</h4>
    <div class="table-responsive">
        <table class="table table-striped">
            <thead>
                <tr>
                    <th>Label</th>
                    <th>User</th>
                    <th>Scopes</th>
                    <th>Created</th>
                    <th>Last Used</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$keys): ?>
                <tr><td colspan="7" class="text-center text-muted py-3">No API keys yet.</td></tr>
            <?php endif; ?>
            <?php foreach ($keys as $k): ?>
                <tr>
                    <td data-label="Label"><?= htmlspecialchars($k['label']); ?></td>
                    <td data-label="User"><?= htmlspecialchars($k['user_name'] . ' (' . $k['user_email'] . ')'); ?></td>
                    <td data-label="Scopes">
                        <?php foreach (api_key_scopes($k) as $sc): ?>
                            <span class="badge-scope <?= htmlspecialchars($sc); ?>"><?= htmlspecialchars($sc); ?></span>
                        <?php endforeach; ?>
                    </td>
                    <td data-label="Created"><?= htmlspecialchars($k['created_at']); ?></td>
                    <td data-label="Last Used"><?= htmlspecialchars($k['last_used_at'] ?? '—'); ?></td>
                    <td data-label="Status">
                        <?php if ($k['revoked_at']): ?>
                            <span class="text-danger">Revoked <?= htmlspecialchars($k['revoked_at']); ?></span>
                        <?php else: ?>
                            <span class="text-success">Active</span>
                        <?php endif; ?>
                    </td>
                    <td data-label="Action">
                        <?php if (!$k['revoked_at']): ?>
                            <form method="post" style="display:inline;" onsubmit="return confirm('Revoke this key? It will stop working immediately.');">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']); ?>">
                                <input type="hidden" name="action" value="revoke">
                                <input type="hidden" name="key_id" value="<?= (int)$k['id']; ?>">
                                <button type="submit" class="btn btn-danger btn-sm" title="Revoke"><i class="fas fa-ban"></i> Revoke</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
function copyRawKey() {
    var el = document.getElementById('rawKeyBox');
    var text = el.innerText.trim();
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(showCopied);
    } else {
        var ta = document.createElement('textarea');
        ta.value = text; document.body.appendChild(ta);
        ta.select(); document.execCommand('copy');
        document.body.removeChild(ta);
        showCopied();
    }
}
function showCopied() {
    var m = document.getElementById('copyMsg');
    m.style.display = 'inline'; setTimeout(function () { m.style.display = 'none'; }, 2000);
}
</script>

<?php include 'footer.php'; ?>
</body>
</html>
