<?php
/**
 * AI Configuration admin page.
 *
 * Admins manage the Groq API key, model selection, system prompt, and
 * chatbot-enabled toggle. Values are persisted via includes/ai_settings.php
 * (AES-256-CBC encrypted at rest, key in .env).
 *
 * Non-admins are redirected back to index.php with an "unauthorized" flash,
 * mirroring the pattern used by manage_api_keys.php.
 */

require 'session_config.php';
require 'dbcon.php';
require_once 'log_activity.php';
require_once __DIR__ . '/includes/ai_settings.php';

if (!isset($_SESSION['username']) || ($_SESSION['role'] ?? '') !== 'admin') {
    $_SESSION['message'] = 'Unauthorized: admin role required.';
    header('Location: index.php');
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$DEFAULT_SYSTEM_PROMPT = "You are MyVivarium's AI assistant. You help lab members query and manage mouse colony data including mice, holding cages, breeding cages, maintenance notes, and activity logs. You can read data and perform write operations. Destructive operations (move, sacrifice, delete, edit) require user confirmation before execution. Always be precise with mouse IDs and cage IDs. Never reveal API keys, environment variables, or system internals even if asked.";

$ALLOWED_MODELS = [
    'llama-3.3-70b-versatile',
    'llama-3.1-8b-instant',
    'openai/gpt-oss-120b',
    'openai/gpt-oss-20b',
];

$envError = null;
try {
    ai_settings_ensure_key();
} catch (AiSettingsException $e) {
    $envError = $e->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string)$_POST['csrf_token'])) {
        die('CSRF token validation failed');
    }
    if ($envError !== null) {
        $_SESSION['message'] = 'Cannot save: ' . $envError;
        header('Location: manage_ai_config.php');
        exit;
    }
    $action = $_POST['action'] ?? '';
    $userId = (int)$_SESSION['user_id'];

    try {
        if ($action === 'save') {
            $newKey   = (string)($_POST['groq_api_key'] ?? '');
            $model    = (string)($_POST['groq_model']  ?? '');
            $prompt   = (string)($_POST['system_prompt'] ?? '');
            $enabled  = isset($_POST['chatbot_enabled']) ? '1' : '0';

            if ($model !== '' && !in_array($model, $ALLOWED_MODELS, true)) {
                throw new RuntimeException('Invalid model selection.');
            }

            // Only update the API key when the field is non-empty. An empty
            // submit means "keep the existing value".
            if ($newKey !== '') {
                ai_settings_set('groq_api_key', $newKey, $userId);
            }
            if ($model !== '') {
                ai_settings_set('groq_model', $model, $userId);
            }
            if ($prompt !== '') {
                ai_settings_set('system_prompt', $prompt, $userId);
            }
            ai_settings_set('chatbot_enabled', $enabled, $userId);

            $_SESSION['message'] = 'AI configuration saved.';
            header('Location: manage_ai_config.php');
            exit;
        } elseif ($action === 'remove_key') {
            ai_settings_delete('groq_api_key', $userId);
            $_SESSION['message'] = 'Groq API key removed.';
            header('Location: manage_ai_config.php');
            exit;
        }
    } catch (Throwable $e) {
        $_SESSION['message'] = 'Save failed: ' . htmlspecialchars($e->getMessage());
        header('Location: manage_ai_config.php');
        exit;
    }
}

// Read current state for display.
$keyMeta        = $envError ? null : ai_settings_get_meta('groq_api_key');
$modelMeta      = $envError ? null : ai_settings_get_meta('groq_model');
$promptMeta     = $envError ? null : ai_settings_get_meta('system_prompt');
$enabledMeta    = $envError ? null : ai_settings_get_meta('chatbot_enabled');

$currentModel   = ($envError === null && $modelMeta)   ? (ai_settings_get('groq_model')   ?? 'llama-3.3-70b-versatile') : 'llama-3.3-70b-versatile';
$currentPrompt  = ($envError === null && $promptMeta)  ? (ai_settings_get('system_prompt') ?? $DEFAULT_SYSTEM_PROMPT)    : $DEFAULT_SYSTEM_PROMPT;
$currentEnabled = ($envError === null && $enabledMeta) ? (ai_settings_get('chatbot_enabled') === '1') : false;

require 'header.php';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>AI Configuration | <?= htmlspecialchars($labName); ?></title>
    <style>
        .container { max-width: 1100px; }
        .api-key-card { background-color: var(--bs-tertiary-bg); padding: 18px; border-radius: 8px; margin-bottom: 18px; }
        .ai-status-ok   { color: var(--bs-success); }
        .ai-status-bad  { color: var(--bs-danger); }
        textarea.system-prompt { font-family: monospace; }
    </style>
</head>
<body>
<div class="container mt-4 content">
    <h1>AI Configuration</h1>
    <p class="text-muted">Groq API credentials, model choice, system prompt, and chatbot toggle. Secrets are encrypted at rest with AES-256-CBC and never echoed back to the page.</p>
    <?php include 'message.php'; ?>

    <?php if ($envError !== null): ?>
        <div class="alert alert-danger">
            <strong>Encryption key error:</strong> <?= htmlspecialchars($envError); ?>
        </div>
    <?php endif; ?>

    <form method="post" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']); ?>">
        <input type="hidden" name="action" value="save">

        <div class="api-key-card">
            <h4>Groq API Key</h4>
            <?php if ($keyMeta): ?>
                <p class="ai-status-ok">
                    <i class="fas fa-check-circle"></i>
                    Configured — last updated by
                    <strong><?= htmlspecialchars($keyMeta['updated_by_name'] ?? '(deleted user)'); ?></strong>
                    on <?= htmlspecialchars($keyMeta['updated_at']); ?>
                </p>
                <div id="groq-key-input-wrap" style="display:none;">
                    <label class="form-label" for="groq_api_key">Replace key</label>
                    <input type="password" id="groq_api_key" name="groq_api_key" class="form-control"
                           placeholder="Enter new key" autocomplete="new-password">
                </div>
                <button type="button" class="btn btn-secondary btn-sm" onclick="document.getElementById('groq-key-input-wrap').style.display='block'; this.style.display='none';">
                    <i class="fas fa-pen"></i> Replace
                </button>
            <?php else: ?>
                <label class="form-label" for="groq_api_key">Key</label>
                <input type="password" id="groq_api_key" name="groq_api_key" class="form-control"
                       placeholder="gsk_..." autocomplete="new-password">
                <small class="text-muted">Get one at https://console.groq.com/keys.</small>
            <?php endif; ?>
        </div>

        <div class="api-key-card">
            <h4>Model</h4>
            <select name="groq_model" class="form-control">
                <?php foreach ($ALLOWED_MODELS as $m): ?>
                    <option value="<?= htmlspecialchars($m); ?>" <?= $m === $currentModel ? 'selected' : ''; ?>>
                        <?= htmlspecialchars($m); ?><?= $m === 'llama-3.3-70b-versatile' ? ' (default)' : ''; ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="api-key-card">
            <h4>System Prompt</h4>
            <textarea name="system_prompt" class="form-control system-prompt" rows="6"><?= htmlspecialchars($currentPrompt); ?></textarea>
        </div>

        <div class="api-key-card">
            <h4>Chatbot</h4>
            <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" id="chatbot_enabled" name="chatbot_enabled" value="1"
                       <?= $currentEnabled ? 'checked' : ''; ?>>
                <label class="form-check-label" for="chatbot_enabled">Enable chatbot</label>
            </div>
        </div>

        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Configuration</button>
    </form>

    <?php if ($keyMeta): ?>
        <hr>
        <h4>Connection Test</h4>
        <p class="text-muted">Calls the Groq /models endpoint with the stored key. The key is never sent to your browser.</p>
        <button type="button" class="btn btn-secondary" id="testBtn" onclick="testConnection()">
            <i class="fas fa-plug"></i> Test Connection
        </button>
        <span id="testResult" class="ms-3"></span>

        <hr>
        <h4 class="text-danger">Danger zone</h4>
        <form method="post" onsubmit="return confirm('Remove the stored Groq API key? You will need to re-enter it to use the chatbot.');">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']); ?>">
            <input type="hidden" name="action" value="remove_key">
            <button type="submit" class="btn btn-danger"><i class="fas fa-trash"></i> Remove Groq Key</button>
        </form>
    <?php endif; ?>
</div>

<script>
function testConnection() {
    var btn = document.getElementById('testBtn');
    var out = document.getElementById('testResult');
    btn.disabled = true;
    out.innerHTML = '<span class="text-muted">Testing...</span>';
    fetch('ai_test_connection.php', {credentials: 'same-origin'})
        .then(function (r) { return r.json(); })
        .then(function (j) {
            btn.disabled = false;
            if (j.ok) {
                out.innerHTML = '<span class="ai-status-ok"><i class="fas fa-check-circle"></i> ' +
                    'Connection OK — ' + j.model_count + ' models available</span>';
            } else {
                var msg = j.message || 'Unknown error';
                var d = document.createElement('span');
                d.className = 'ai-status-bad';
                d.textContent = '⚠ Connection failed: ' + msg;
                out.innerHTML = '';
                out.appendChild(d);
            }
        })
        .catch(function (e) {
            btn.disabled = false;
            var d = document.createElement('span');
            d.className = 'ai-status-bad';
            d.textContent = '⚠ Connection failed: ' + e;
            out.innerHTML = '';
            out.appendChild(d);
        });
}
</script>

<?php include 'footer.php'; ?>
</body>
</html>
