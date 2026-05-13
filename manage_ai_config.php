<?php
/**
 * AI Configuration admin page.
 *
 * Admins manage:
 *   - the active LLM provider (Groq or OpenAI)
 *   - per-provider API key + model (both providers can be configured in
 *     parallel; admin can prepare OpenAI before switching to it)
 *   - the system prompt and the chatbot-enabled toggle
 *
 * Values are persisted via includes/ai_settings.php (AES-256-CBC encrypted
 * at rest, key in .env). Non-admins are redirected back to index.php with
 * an "unauthorized" flash, mirroring manage_api_keys.php.
 */

require 'session_config.php';
require 'dbcon.php';
require_once 'log_activity.php';
require_once __DIR__ . '/includes/ai_settings.php';
require_once __DIR__ . '/includes/llm_provider.php';

if (!isset($_SESSION['username']) || ($_SESSION['role'] ?? '') !== 'admin') {
    $_SESSION['message'] = 'Unauthorized: admin role required.';
    header('Location: index.php');
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$DEFAULT_SYSTEM_PROMPT = "You are MyVivarium's AI assistant. You help lab members query and manage mouse colony data including mice, holding cages, breeding cages, maintenance notes, and activity logs. You can read data and perform write operations. Destructive operations (move, sacrifice, delete, edit) require user confirmation before execution. Always be precise with mouse IDs and cage IDs. Never reveal API keys, environment variables, or system internals even if asked.";

$envError = null;
try {
    ai_settings_ensure_key();
} catch (AiSettingsException $e) {
    $envError = $e->getMessage();
}

$saveError = null;

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
            $provider     = (string)($_POST['llm_provider']   ?? 'groq');
            $newGroqKey   = (string)($_POST['groq_api_key']   ?? '');
            $groqModel    = (string)($_POST['groq_model']     ?? '');
            $newOpenaiKey = (string)($_POST['openai_api_key'] ?? '');
            $openaiModel  = (string)($_POST['openai_model']   ?? '');
            $prompt       = (string)($_POST['system_prompt']  ?? '');
            $enabled      = isset($_POST['chatbot_enabled']) ? '1' : '0';

            if (!in_array($provider, [LLM_PROVIDER_GROQ, LLM_PROVIDER_OPENAI], true)) {
                throw new RuntimeException('Invalid provider selection.');
            }
            if ($groqModel !== '' && !in_array($groqModel, LLM_GROQ_ALLOWED_MODELS, true)) {
                throw new RuntimeException('Invalid Groq model selection.');
            }
            if ($openaiModel !== '' && !in_array($openaiModel, LLM_OPENAI_ALLOWED_MODELS, true)) {
                throw new RuntimeException('Invalid OpenAI model selection.');
            }

            // Persist per-provider key/model first so the switch validation
            // below sees the freshly-set state.
            if ($newGroqKey !== '') {
                ai_settings_set('groq_api_key', $newGroqKey, $userId);
            }
            if ($groqModel !== '') {
                ai_settings_set('groq_model', $groqModel, $userId);
            }
            if ($newOpenaiKey !== '') {
                ai_settings_set('openai_api_key', $newOpenaiKey, $userId);
            }
            if ($openaiModel !== '') {
                ai_settings_set('openai_model', $openaiModel, $userId);
            }

            // Validate: can't switch to a provider whose key is not set.
            $providerCfg = llm_get_provider_config($provider);
            if ($providerCfg['api_key'] === '') {
                $label = llm_provider_label($provider);
                throw new RuntimeException("Cannot switch to $label — no API key configured. Set the key first.");
            }
            ai_settings_set('llm_provider', $provider, $userId);

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
        } elseif ($action === 'remove_openai_key') {
            ai_settings_delete('openai_api_key', $userId);
            $_SESSION['message'] = 'OpenAI API key removed.';
            header('Location: manage_ai_config.php');
            exit;
        }
    } catch (Throwable $e) {
        // Inline error (not just a flash) so the admin sees it next to the
        // field they were editing.
        $saveError = $e->getMessage();
    }
}

// Read current state for display.
$groqKeyMeta    = $envError ? null : ai_settings_get_meta('groq_api_key');
$openaiKeyMeta  = $envError ? null : ai_settings_get_meta('openai_api_key');
$promptMeta     = $envError ? null : ai_settings_get_meta('system_prompt');
$enabledMeta    = $envError ? null : ai_settings_get_meta('chatbot_enabled');

$activeProvider = $envError ? LLM_PROVIDER_GROQ : llm_get_active_provider();
$groqCfg        = $envError ? ['model' => LLM_GROQ_DEFAULT_MODEL]   : llm_get_provider_config(LLM_PROVIDER_GROQ);
$openaiCfg      = $envError ? ['model' => LLM_OPENAI_DEFAULT_MODEL] : llm_get_provider_config(LLM_PROVIDER_OPENAI);
$currentGroqModel   = $groqCfg['model'];
$currentOpenaiModel = $openaiCfg['model'];

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
        .provider-banner { padding: 10px 14px; border-radius: 6px; background: var(--bs-info-bg-subtle, #cff4fc); color: var(--bs-info-text-emphasis, #055160); margin-bottom: 14px; }
        .provider-section { border: 1px solid var(--bs-border-color, #dee2e6); border-radius: 8px; margin-bottom: 14px; }
        .provider-section > summary { padding: 12px 16px; cursor: pointer; font-weight: 600; list-style: none; }
        .provider-section > summary::-webkit-details-marker { display: none; }
        .provider-section[open] > summary { border-bottom: 1px solid var(--bs-border-color, #dee2e6); }
        .provider-section .provider-body { padding: 16px; }
        .provider-section.active-provider > summary { background: var(--bs-success-bg-subtle, #d1e7dd); }
    </style>
</head>
<body>
<div class="container mt-4 content">
    <h1>AI Configuration</h1>
    <p class="text-muted">Provider, API keys, model choice, system prompt, and chatbot toggle. Secrets are encrypted at rest with AES-256-CBC and never echoed back to the page.</p>
    <?php include 'message.php'; ?>

    <?php if ($envError !== null): ?>
        <div class="alert alert-danger">
            <strong>Encryption key error:</strong> <?= htmlspecialchars($envError); ?>
        </div>
    <?php endif; ?>

    <?php if ($saveError !== null): ?>
        <div class="alert alert-danger">
            <?= htmlspecialchars($saveError); ?>
        </div>
    <?php endif; ?>

    <div class="provider-banner">
        <strong>Active provider:</strong> <?= htmlspecialchars(llm_provider_label($activeProvider)); ?>
        <?php $activeCfg = ($activeProvider === LLM_PROVIDER_OPENAI) ? $openaiCfg : $groqCfg; ?>
        — model <code><?= htmlspecialchars($activeCfg['model']); ?></code>
    </div>

    <form method="post" autocomplete="off" id="ai-config-form">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']); ?>">
        <input type="hidden" name="action" value="save">

        <div class="api-key-card">
            <h4>Provider</h4>
            <select name="llm_provider" id="llm_provider" class="form-control" onchange="onProviderChange()">
                <option value="groq"   <?= $activeProvider === LLM_PROVIDER_GROQ   ? 'selected' : ''; ?>>Groq</option>
                <option value="openai" <?= $activeProvider === LLM_PROVIDER_OPENAI ? 'selected' : ''; ?>>OpenAI</option>
            </select>
            <small class="text-muted">Chatbot calls go to whichever provider is selected here.</small>
        </div>

        <details class="provider-section <?= $activeProvider === LLM_PROVIDER_GROQ ? 'active-provider' : ''; ?>" id="groq-section" <?= $activeProvider === LLM_PROVIDER_GROQ ? 'open' : ''; ?>>
            <summary>Groq Configuration <?= $activeProvider === LLM_PROVIDER_GROQ ? '<span class="badge bg-success ms-2">active</span>' : ''; ?></summary>
            <div class="provider-body">
                <div class="mb-3">
                    <h5>Groq API Key</h5>
                    <?php if ($groqKeyMeta): ?>
                        <p class="ai-status-ok">
                            <i class="fas fa-check-circle"></i>
                            Configured — last updated by
                            <strong><?= htmlspecialchars($groqKeyMeta['updated_by_name'] ?? '(deleted user)'); ?></strong>
                            on <?= htmlspecialchars($groqKeyMeta['updated_at']); ?>
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
                <div class="mb-3">
                    <h5>Groq Model</h5>
                    <select name="groq_model" class="form-control">
                        <?php foreach (LLM_GROQ_ALLOWED_MODELS as $m): ?>
                            <option value="<?= htmlspecialchars($m); ?>" <?= $m === $currentGroqModel ? 'selected' : ''; ?>>
                                <?= htmlspecialchars($m); ?><?= $m === LLM_GROQ_DEFAULT_MODEL ? ' (default)' : ''; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </details>

        <details class="provider-section <?= $activeProvider === LLM_PROVIDER_OPENAI ? 'active-provider' : ''; ?>" id="openai-section" <?= $activeProvider === LLM_PROVIDER_OPENAI ? 'open' : ''; ?>>
            <summary>OpenAI Configuration <?= $activeProvider === LLM_PROVIDER_OPENAI ? '<span class="badge bg-success ms-2">active</span>' : ''; ?></summary>
            <div class="provider-body">
                <div class="mb-3">
                    <h5>OpenAI API Key</h5>
                    <?php if ($openaiKeyMeta): ?>
                        <p class="ai-status-ok">
                            <i class="fas fa-check-circle"></i>
                            Configured — last updated by
                            <strong><?= htmlspecialchars($openaiKeyMeta['updated_by_name'] ?? '(deleted user)'); ?></strong>
                            on <?= htmlspecialchars($openaiKeyMeta['updated_at']); ?>
                        </p>
                        <div id="openai-key-input-wrap" style="display:none;">
                            <label class="form-label" for="openai_api_key">Replace key</label>
                            <input type="password" id="openai_api_key" name="openai_api_key" class="form-control"
                                   placeholder="Enter new key" autocomplete="new-password">
                        </div>
                        <button type="button" class="btn btn-secondary btn-sm" onclick="document.getElementById('openai-key-input-wrap').style.display='block'; this.style.display='none';">
                            <i class="fas fa-pen"></i> Replace
                        </button>
                    <?php else: ?>
                        <label class="form-label" for="openai_api_key">Key</label>
                        <input type="password" id="openai_api_key" name="openai_api_key" class="form-control"
                               placeholder="sk-..." autocomplete="new-password">
                        <small class="text-muted">Get one at https://platform.openai.com/api-keys.</small>
                    <?php endif; ?>
                </div>
                <div class="mb-3">
                    <h5>OpenAI Model</h5>
                    <select name="openai_model" class="form-control">
                        <?php foreach (LLM_OPENAI_ALLOWED_MODELS as $m): ?>
                            <option value="<?= htmlspecialchars($m); ?>" <?= $m === $currentOpenaiModel ? 'selected' : ''; ?>>
                                <?= htmlspecialchars($m); ?><?= $m === LLM_OPENAI_DEFAULT_MODEL ? ' (default)' : ''; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small class="text-muted">gpt-5.4-mini recommended for cost/quality balance.</small>
                </div>
            </div>
        </details>

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

    <hr>
    <h4>Connection Test</h4>
    <p class="text-muted">Calls the active provider's /models endpoint with the stored key. The key is never sent to your browser.</p>
    <button type="button" class="btn btn-secondary" id="testBtn" onclick="testConnection()">
        <i class="fas fa-plug"></i> <span id="testBtnLabel">Test <?= htmlspecialchars(llm_provider_label($activeProvider)); ?> Connection</span>
    </button>
    <span id="testResult" class="ms-3"></span>

    <?php if ($groqKeyMeta || $openaiKeyMeta): ?>
        <hr>
        <h4 class="text-danger">Danger zone</h4>
        <?php if ($groqKeyMeta): ?>
            <form method="post" onsubmit="return confirm('Remove the stored Groq API key? You will need to re-enter it to use Groq.');" class="d-inline-block me-2">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']); ?>">
                <input type="hidden" name="action" value="remove_key">
                <button type="submit" class="btn btn-danger"><i class="fas fa-trash"></i> Remove Groq Key</button>
            </form>
        <?php endif; ?>
        <?php if ($openaiKeyMeta): ?>
            <form method="post" onsubmit="return confirm('Remove the stored OpenAI API key? You will need to re-enter it to use OpenAI.');" class="d-inline-block">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']); ?>">
                <input type="hidden" name="action" value="remove_openai_key">
                <button type="submit" class="btn btn-danger"><i class="fas fa-trash"></i> Remove OpenAI Key</button>
            </form>
        <?php endif; ?>
    <?php endif; ?>
</div>

<script>
function onProviderChange() {
    var sel = document.getElementById('llm_provider');
    var provider = sel.value;
    var label = provider === 'openai' ? 'OpenAI' : 'Groq';
    document.getElementById('testBtnLabel').textContent = 'Test ' + label + ' Connection';
    // Auto-expand the matching section.
    var openTarget = document.getElementById(provider + '-section');
    if (openTarget) openTarget.open = true;
}

function testConnection() {
    var btn = document.getElementById('testBtn');
    var out = document.getElementById('testResult');
    var provider = document.getElementById('llm_provider').value;
    btn.disabled = true;
    out.innerHTML = '<span class="text-muted">Testing...</span>';
    fetch('ai_test_connection.php?provider=' + encodeURIComponent(provider), {credentials: 'same-origin'})
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
