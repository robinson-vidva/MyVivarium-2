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
require_once __DIR__ . '/includes/ai_rate_limit.php';

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

            // Custom provider fields. We persist every field that the admin
            // submits, regardless of which preset is currently selected, so
            // switching presets back and forth never loses configuration.
            $customPreset      = (string)($_POST['custom_preset']       ?? '');
            $customResourceUrl = (string)($_POST['custom_resource_url'] ?? '');
            $customDeployment  = (string)($_POST['custom_deployment']   ?? '');
            $customApiVersion  = (string)($_POST['custom_api_version']  ?? '');
            $customBaseUrl     = (string)($_POST['custom_base_url']     ?? '');
            // Model dropdown may be the literal "__other" sentinel meaning
            // "use the custom text field below". Collapse that here.
            $customModelSelect = (string)($_POST['custom_model']        ?? '');
            $customModelOther  = (string)($_POST['custom_model_other']  ?? '');
            $customModel       = ($customModelSelect === '__other')
                ? trim($customModelOther)
                : trim($customModelSelect);
            $customTokenField  = (string)($_POST['custom_token_field']  ?? '');
            $newCustomKey      = (string)($_POST['custom_api_key']      ?? '');

            if (!in_array($provider, [LLM_PROVIDER_GROQ, LLM_PROVIDER_OPENAI, LLM_PROVIDER_CUSTOM], true)) {
                throw new RuntimeException('Invalid provider selection.');
            }
            if ($groqModel !== '' && !in_array($groqModel, LLM_GROQ_ALLOWED_MODELS, true)) {
                throw new RuntimeException('Invalid Groq model selection.');
            }
            if ($openaiModel !== '' && !in_array($openaiModel, LLM_OPENAI_ALLOWED_MODELS, true)) {
                throw new RuntimeException('Invalid OpenAI model selection.');
            }
            if ($customPreset !== '' && !in_array($customPreset, LLM_CUSTOM_ALLOWED_PRESETS, true)) {
                throw new RuntimeException('Invalid custom preset selection.');
            }
            if ($customTokenField !== '' && !in_array($customTokenField, ['max_tokens', 'max_completion_tokens'], true)) {
                throw new RuntimeException('Invalid token field selection.');
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

            // Custom-provider fields. Persist every populated field across
            // all presets so switching presets doesn't drop config. Empty
            // strings are skipped (we don't wipe a stored value with blank
            // form input — the admin can use the Remove button instead).
            if ($customPreset      !== '') ai_settings_set('custom_preset',       $customPreset,      $userId);
            if ($customResourceUrl !== '') ai_settings_set('custom_resource_url', $customResourceUrl, $userId);
            if ($customDeployment  !== '') ai_settings_set('custom_deployment',   $customDeployment,  $userId);
            if ($customApiVersion  !== '') ai_settings_set('custom_api_version',  $customApiVersion,  $userId);
            if ($customBaseUrl     !== '') ai_settings_set('custom_base_url',     $customBaseUrl,     $userId);
            if ($customModel       !== '') ai_settings_set('custom_model',        $customModel,       $userId);
            if ($customTokenField  !== '') ai_settings_set('custom_token_field',  $customTokenField,  $userId);
            if ($newCustomKey      !== '') ai_settings_set('custom_api_key',      $newCustomKey,      $userId);

            // Validate: can't switch to a provider whose key is not set, and
            // for "custom" the selected preset's required fields must all
            // be populated. We re-read the config so the freshly-saved
            // values participate.
            $providerCfg = llm_get_provider_config($provider);
            if ($provider === LLM_PROVIDER_CUSTOM) {
                if (!empty($providerCfg['config_errors'])) {
                    $missing = $providerCfg['config_errors'];
                    // Show a friendly message — refuse the switch but keep
                    // the saved per-field values so the admin can finish
                    // configuration and try again.
                    throw new RuntimeException(
                        'Cannot switch to Custom — ' . implode(', ', $missing) .
                        ' is required. Configure the custom provider first, then switch.'
                    );
                }
            }
            if ($providerCfg['api_key'] === '') {
                $label = llm_provider_label($provider);
                throw new RuntimeException("Cannot switch to $label — no API key configured. Set the key first.");
            }
            ai_settings_set('llm_provider', $provider, $userId);

            if ($prompt !== '') {
                ai_settings_set('system_prompt', $prompt, $userId);
            }
            ai_settings_set('chatbot_enabled', $enabled, $userId);

            // Rate-limit values — clamp to documented min/max.
            if (isset($_POST['ai_rate_limit_messages_per_minute'])) {
                $v = (int)$_POST['ai_rate_limit_messages_per_minute'];
                if ($v < AI_RATE_LIMIT_PER_MINUTE_MIN) $v = AI_RATE_LIMIT_PER_MINUTE_MIN;
                if ($v > AI_RATE_LIMIT_PER_MINUTE_MAX) $v = AI_RATE_LIMIT_PER_MINUTE_MAX;
                ai_settings_set('ai_rate_limit_messages_per_minute', (string)$v, $userId);
            }
            if (isset($_POST['ai_rate_limit_messages_per_day'])) {
                $v = (int)$_POST['ai_rate_limit_messages_per_day'];
                if ($v < AI_RATE_LIMIT_PER_DAY_MIN) $v = AI_RATE_LIMIT_PER_DAY_MIN;
                if ($v > AI_RATE_LIMIT_PER_DAY_MAX) $v = AI_RATE_LIMIT_PER_DAY_MAX;
                ai_settings_set('ai_rate_limit_messages_per_day', (string)$v, $userId);
            }

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
        } elseif ($action === 'remove_custom_key') {
            ai_settings_delete('custom_api_key', $userId);
            $_SESSION['message'] = 'Custom API key removed.';
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
$customKeyMeta  = $envError ? null : ai_settings_get_meta('custom_api_key');
$promptMeta     = $envError ? null : ai_settings_get_meta('system_prompt');
$enabledMeta    = $envError ? null : ai_settings_get_meta('chatbot_enabled');

$activeProvider = $envError ? LLM_PROVIDER_GROQ : llm_get_active_provider();
$groqCfg        = $envError ? ['model' => LLM_GROQ_DEFAULT_MODEL]   : llm_get_provider_config(LLM_PROVIDER_GROQ);
$openaiCfg      = $envError ? ['model' => LLM_OPENAI_DEFAULT_MODEL] : llm_get_provider_config(LLM_PROVIDER_OPENAI);
$customCfg      = $envError ? ['preset' => '', 'model' => '', 'preset_label' => '', 'config_errors' => []] : llm_get_provider_config(LLM_PROVIDER_CUSTOM);
$currentGroqModel   = $groqCfg['model'];
$currentOpenaiModel = $openaiCfg['model'];

// Pull individual custom-provider field values for the form. The custom
// config object exposes derived fields (request_url, headers, etc.); we
// also need the raw underlying settings for re-rendering the input boxes
// when the page re-renders after a save error.
$cs = function (string $k) use ($envError): string {
    if ($envError !== null) return '';
    try {
        $v = ai_settings_get($k);
    } catch (Throwable $e) {
        return '';
    }
    return $v === null ? '' : (string)$v;
};
$customPreset       = $cs('custom_preset');
$customResourceUrl  = $cs('custom_resource_url');
$customDeployment   = $cs('custom_deployment');
$customApiVersion   = $cs('custom_api_version');
if ($customApiVersion === '') $customApiVersion = LLM_CUSTOM_DEFAULT_AZURE_API_VERSION;
$customBaseUrl      = $cs('custom_base_url');
$customModel        = $cs('custom_model');
$customTokenField   = $cs('custom_token_field');
if ($customTokenField === '') $customTokenField = 'max_tokens';

$currentPrompt  = ($envError === null && $promptMeta)  ? (ai_settings_get('system_prompt') ?? $DEFAULT_SYSTEM_PROMPT)    : $DEFAULT_SYSTEM_PROMPT;
$currentEnabled = ($envError === null && $enabledMeta) ? (ai_settings_get('chatbot_enabled') === '1') : false;

$currentRateMinute = $envError ? AI_RATE_LIMIT_PER_MINUTE_DEFAULT : ai_rate_limit_get_limit('minute');
$currentRateDay    = $envError ? AI_RATE_LIMIT_PER_DAY_DEFAULT    : ai_rate_limit_get_limit('day');

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

    <?php
        if ($activeProvider === LLM_PROVIDER_OPENAI) {
            $activeCfg = $openaiCfg;
        } elseif ($activeProvider === LLM_PROVIDER_CUSTOM) {
            $activeCfg = $customCfg;
        } else {
            $activeCfg = $groqCfg;
        }
    ?>
    <div class="provider-banner">
        <strong><?= htmlspecialchars(llm_active_provider_banner($activeCfg)); ?></strong>
    </div>

    <form method="post" autocomplete="off" id="ai-config-form">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']); ?>">
        <input type="hidden" name="action" value="save">

        <div class="api-key-card">
            <h4>Provider</h4>
            <select name="llm_provider" id="llm_provider" class="form-control" onchange="onProviderChange()">
                <option value="groq"   <?= $activeProvider === LLM_PROVIDER_GROQ   ? 'selected' : ''; ?>>Groq</option>
                <option value="openai" <?= $activeProvider === LLM_PROVIDER_OPENAI ? 'selected' : ''; ?>>OpenAI</option>
                <option value="custom" <?= $activeProvider === LLM_PROVIDER_CUSTOM ? 'selected' : ''; ?>>Custom</option>
            </select>
            <small class="text-muted">Chatbot calls go to whichever provider is selected here. "Custom" lets you point at an institutional gateway (Azure APIM, OpenRouter, DeepSeek, etc.).</small>
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

        <?php
            $customActive = ($activeProvider === LLM_PROVIDER_CUSTOM);
            $azureModelIsPreset = in_array($customModel, LLM_CUSTOM_AZURE_ANTHROPIC_MODELS, true);
        ?>
        <details class="provider-section <?= $customActive ? 'active-provider' : ''; ?>" id="custom-section" <?= $customActive ? 'open' : ''; ?>>
            <summary>Custom Provider Configuration <?= $customActive ? '<span class="badge bg-success ms-2">active</span>' : ''; ?></summary>
            <div class="provider-body">
                <div class="mb-3">
                    <label class="form-label" for="custom_preset"><strong>Preset</strong></label>
                    <select name="custom_preset" id="custom_preset" class="form-control" onchange="onCustomPresetChange()">
                        <option value="" <?= $customPreset === '' ? 'selected' : ''; ?>>— pick a preset —</option>
                        <option value="azure_openai"        <?= $customPreset === 'azure_openai'        ? 'selected' : ''; ?>>Azure OpenAI (GPT models)</option>
                        <option value="azure_anthropic"     <?= $customPreset === 'azure_anthropic'     ? 'selected' : ''; ?>>Azure Anthropic / Claude via APIM</option>
                        <option value="openai_compatible"   <?= $customPreset === 'openai_compatible'   ? 'selected' : ''; ?>>OpenAI-compatible (generic)</option>
                    </select>
                    <small class="text-muted">Choose the preset that matches your gateway. The fields below change to match.</small>
                </div>

                <!-- Azure OpenAI fields -->
                <div class="custom-preset-group" data-preset="azure_openai" style="display: none;">
                    <div class="mb-3">
                        <label class="form-label" for="custom_resource_url">Resource URL</label>
                        <input type="text" id="custom_resource_url" name="custom_resource_url" class="form-control"
                               value="<?= htmlspecialchars($customResourceUrl); ?>"
                               placeholder="https://my-resource.openai.azure.com">
                        <small class="text-muted">Your Azure OpenAI resource endpoint, or APIM gateway URL (e.g. https://apim-xyz.azure-api.net).</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="custom_deployment">Deployment name</label>
                        <input type="text" id="custom_deployment" name="custom_deployment" class="form-control"
                               value="<?= htmlspecialchars($customDeployment); ?>"
                               placeholder="gpt-4o-mini">
                        <small class="text-muted">The deployment name configured in Azure (not the OpenAI model name).</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="custom_api_version">API version</label>
                        <input type="text" id="custom_api_version" name="custom_api_version" class="form-control"
                               value="<?= htmlspecialchars($customApiVersion); ?>"
                               placeholder="<?= htmlspecialchars(LLM_CUSTOM_DEFAULT_AZURE_API_VERSION); ?>">
                        <small class="text-muted">Azure API version. Leave default unless your admin says otherwise.</small>
                    </div>
                </div>

                <!-- Azure Anthropic / Claude via APIM fields -->
                <div class="custom-preset-group" data-preset="azure_anthropic" style="display: none;">
                    <div class="mb-3">
                        <label class="form-label" for="custom_base_url_anthropic">Base URL</label>
                        <input type="text" id="custom_base_url_anthropic" name="custom_base_url" class="form-control custom-base-url"
                               value="<?= htmlspecialchars($customBaseUrl); ?>"
                               placeholder="https://apim-xyz.azure-api.net">
                        <small class="text-muted">Your APIM gateway base URL. The chatbot will POST to <code>{base}/anthropic/v1/messages</code>.</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="custom_model_anthropic">Model</label>
                        <select id="custom_model_anthropic" name="custom_model" class="form-control" onchange="onAnthropicModelChange()">
                            <?php foreach (LLM_CUSTOM_AZURE_ANTHROPIC_MODELS as $m): ?>
                                <option value="<?= htmlspecialchars($m); ?>" <?= $customModel === $m ? 'selected' : ''; ?>>
                                    <?= htmlspecialchars($m); ?>
                                </option>
                            <?php endforeach; ?>
                            <option value="__other" <?= (!$azureModelIsPreset && $customModel !== '') ? 'selected' : ''; ?>>Custom…</option>
                        </select>
                        <input type="text" id="custom_model_other" name="custom_model_other"
                               class="form-control mt-2"
                               value="<?= htmlspecialchars($azureModelIsPreset ? '' : $customModel); ?>"
                               placeholder="claude-<model-name>"
                               style="display: <?= (!$azureModelIsPreset && $customModel !== '') ? 'block' : 'none'; ?>;">
                        <small class="text-muted">Pick a Claude model or "Custom…" to type any model id your gateway accepts.</small>
                    </div>
                </div>

                <!-- OpenAI-compatible (generic) fields -->
                <div class="custom-preset-group" data-preset="openai_compatible" style="display: none;">
                    <div class="mb-3">
                        <label class="form-label" for="custom_base_url_oa">Base URL</label>
                        <input type="text" id="custom_base_url_oa" name="custom_base_url" class="form-control custom-base-url"
                               value="<?= htmlspecialchars($customBaseUrl); ?>"
                               placeholder="https://api.openrouter.ai/api/v1">
                        <small class="text-muted">Provider base URL. The chatbot will POST to <code>{base}/chat/completions</code>.</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="custom_model_oa">Model</label>
                        <input type="text" id="custom_model_oa" name="custom_model" class="form-control custom-model-text"
                               value="<?= htmlspecialchars($customModel); ?>"
                               placeholder="openrouter/deepseek/deepseek-chat-v3">
                        <small class="text-muted">Exact model id your provider expects. Copy it from their docs.</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="custom_token_field">Token field (optional)</label>
                        <select name="custom_token_field" id="custom_token_field" class="form-control">
                            <option value="max_tokens"            <?= $customTokenField === 'max_tokens'            ? 'selected' : ''; ?>>max_tokens (default)</option>
                            <option value="max_completion_tokens" <?= $customTokenField === 'max_completion_tokens' ? 'selected' : ''; ?>>max_completion_tokens (newer reasoning models)</option>
                        </select>
                        <small class="text-muted">Some newer reasoning models reject the legacy <code>max_tokens</code>. Leave default unless the provider's API errors mention it.</small>
                    </div>
                </div>

                <div class="mb-3">
                    <h5>Custom API Key</h5>
                    <?php if ($customKeyMeta): ?>
                        <p class="ai-status-ok">
                            <i class="fas fa-check-circle"></i>
                            Configured — last updated by
                            <strong><?= htmlspecialchars($customKeyMeta['updated_by_name'] ?? '(deleted user)'); ?></strong>
                            on <?= htmlspecialchars($customKeyMeta['updated_at']); ?>
                        </p>
                        <div id="custom-key-input-wrap" style="display:none;">
                            <label class="form-label" for="custom_api_key">Replace key</label>
                            <input type="password" id="custom_api_key" name="custom_api_key" class="form-control"
                                   placeholder="Enter new key" autocomplete="new-password">
                        </div>
                        <button type="button" class="btn btn-secondary btn-sm" onclick="document.getElementById('custom-key-input-wrap').style.display='block'; this.style.display='none';">
                            <i class="fas fa-pen"></i> Replace
                        </button>
                    <?php else: ?>
                        <label class="form-label" for="custom_api_key">Key</label>
                        <input type="password" id="custom_api_key" name="custom_api_key" class="form-control"
                               placeholder="Paste the key issued by your gateway / provider" autocomplete="new-password">
                        <small class="text-muted">The key your provider issued (Azure subscription key, OpenRouter key, DeepSeek key, …). Encrypted before storage.</small>
                    <?php endif; ?>
                </div>
            </div>
        </details>

        <div class="api-key-card">
            <h4>System Prompt</h4>
            <textarea name="system_prompt" class="form-control system-prompt" rows="6"><?= htmlspecialchars($currentPrompt); ?></textarea>
        </div>

        <div class="api-key-card">
            <h4>Rate Limiting</h4>
            <p class="text-muted">Per-user limit on AI chatbot messages. Counts user messages only; tool calls and LLM round-trips do not count. Independent of the REST API key limit.</p>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label" for="ai_rate_limit_messages_per_minute">Messages per minute</label>
                    <input type="number" class="form-control" id="ai_rate_limit_messages_per_minute" name="ai_rate_limit_messages_per_minute"
                           min="<?= AI_RATE_LIMIT_PER_MINUTE_MIN; ?>"
                           max="<?= AI_RATE_LIMIT_PER_MINUTE_MAX; ?>"
                           value="<?= htmlspecialchars((string)$currentRateMinute); ?>">
                    <small class="text-muted">Default <?= AI_RATE_LIMIT_PER_MINUTE_DEFAULT; ?>, min <?= AI_RATE_LIMIT_PER_MINUTE_MIN; ?>, max <?= AI_RATE_LIMIT_PER_MINUTE_MAX; ?>. Fixed 60-second window.</small>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="ai_rate_limit_messages_per_day">Messages per day</label>
                    <input type="number" class="form-control" id="ai_rate_limit_messages_per_day" name="ai_rate_limit_messages_per_day"
                           min="<?= AI_RATE_LIMIT_PER_DAY_MIN; ?>"
                           max="<?= AI_RATE_LIMIT_PER_DAY_MAX; ?>"
                           value="<?= htmlspecialchars((string)$currentRateDay); ?>">
                    <small class="text-muted">Default <?= AI_RATE_LIMIT_PER_DAY_DEFAULT; ?>, min <?= AI_RATE_LIMIT_PER_DAY_MIN; ?>, max <?= AI_RATE_LIMIT_PER_DAY_MAX; ?>. Resets at midnight UTC.</small>
                </div>
            </div>
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

    <?php if ($groqKeyMeta || $openaiKeyMeta || $customKeyMeta): ?>
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
            <form method="post" onsubmit="return confirm('Remove the stored OpenAI API key? You will need to re-enter it to use OpenAI.');" class="d-inline-block me-2">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']); ?>">
                <input type="hidden" name="action" value="remove_openai_key">
                <button type="submit" class="btn btn-danger"><i class="fas fa-trash"></i> Remove OpenAI Key</button>
            </form>
        <?php endif; ?>
        <?php if ($customKeyMeta): ?>
            <form method="post" onsubmit="return confirm('Remove the stored Custom API key? You will need to re-enter it to use the custom provider.');" class="d-inline-block">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']); ?>">
                <input type="hidden" name="action" value="remove_custom_key">
                <button type="submit" class="btn btn-danger"><i class="fas fa-trash"></i> Remove Custom Key</button>
            </form>
        <?php endif; ?>
    <?php endif; ?>
</div>

<script>
function onProviderChange() {
    var sel = document.getElementById('llm_provider');
    var provider = sel.value;
    var label = provider === 'openai' ? 'OpenAI' : (provider === 'custom' ? 'Custom' : 'Groq');
    document.getElementById('testBtnLabel').textContent = 'Test ' + label + ' Connection';
    // Auto-expand the matching section.
    var openTarget = document.getElementById(provider + '-section');
    if (openTarget) openTarget.open = true;
}

// Show/hide the preset-specific field groups inside the Custom section.
// All field values stay in the DOM regardless of which group is visible, so
// the browser still POSTs them on submit. The server keeps every value for
// every preset, so switching back recovers prior config.
function onCustomPresetChange() {
    var preset = document.getElementById('custom_preset').value;
    var groups = document.querySelectorAll('.custom-preset-group');
    for (var i = 0; i < groups.length; i++) {
        groups[i].style.display = (groups[i].getAttribute('data-preset') === preset) ? 'block' : 'none';
    }
}

function onAnthropicModelChange() {
    var sel = document.getElementById('custom_model_anthropic');
    var other = document.getElementById('custom_model_other');
    if (sel.value === '__other') {
        other.style.display = 'block';
        // Move user focus to the other field so they can start typing.
        try { other.focus(); } catch (e) {}
    } else {
        other.style.display = 'none';
    }
}

// Initialize visibility on page load.
document.addEventListener('DOMContentLoaded', function () {
    onCustomPresetChange();
    // On submit, disable inputs in non-visible preset groups so the form
    // doesn't POST stale or duplicate values (Anthropic and
    // openai_compatible share the name "custom_model"). The currently
    // visible group submits as normal.
    var form = document.getElementById('ai-config-form');
    if (form) {
        form.addEventListener('submit', function () {
            var preset = document.getElementById('custom_preset').value;
            var groups = document.querySelectorAll('.custom-preset-group');
            for (var i = 0; i < groups.length; i++) {
                var visible = groups[i].getAttribute('data-preset') === preset;
                var inputs = groups[i].querySelectorAll('input, select, textarea');
                for (var j = 0; j < inputs.length; j++) {
                    inputs[j].disabled = !visible;
                }
            }
        });
    }
});

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
