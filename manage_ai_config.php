<?php
/**
 * AI Configuration admin page.
 *
 * Admins manage:
 *   - the provider chain — an ordered list of providers (Groq, OpenAI,
 *     Custom) each with enable/disable + priority. The chatbot tries them
 *     in order on 429/5xx/network failures and surfaces which one served.
 *   - per-provider API key + model (every provider can be configured in
 *     parallel; misconfiguration of one does not block enabling another)
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

            // Chain: each provider has a priority (1-based int) and an
            // enabled checkbox. Ordering submits priorities; renaming /
            // dropping unknown providers is handled by llm_normalize_chain.
            $chainPriorityIn = isset($_POST['chain_priority']) && is_array($_POST['chain_priority'])
                ? $_POST['chain_priority'] : [];
            $chainEnabledIn  = isset($_POST['chain_enabled'])  && is_array($_POST['chain_enabled'])
                ? $_POST['chain_enabled']  : [];
            $submittedChain = [];
            foreach (LLM_PROVIDER_ALL as $prov) {
                $submittedChain[] = [
                    'provider' => $prov,
                    'enabled'  => !empty($chainEnabledIn[$prov]),
                    'priority' => (int)($chainPriorityIn[$prov] ?? 99),
                ];
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

            // Persist per-provider key/model first so the chain validation
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

            // Chain validation: at least one provider must be enabled AND
            // fully configured (key present, custom preset complete). Each
            // provider is checked independently — Groq being incomplete
            // does not block enabling OpenAI, which is the regression the
            // previous "Cannot switch to X — Y required" logic introduced.
            $normalized = llm_normalize_chain($submittedChain);
            $hasAny = false;
            foreach ($normalized as $entry) {
                if (!$entry['enabled']) continue;
                $cfg = llm_get_provider_config($entry['provider']);
                if (($cfg['api_key'] ?? '') === '') continue;
                if (($cfg['provider'] ?? '') === LLM_PROVIDER_CUSTOM && !empty($cfg['config_errors'])) continue;
                $hasAny = true;
                break;
            }
            if (!$hasAny) {
                throw new RuntimeException('At least one provider must be enabled and fully configured.');
            }

            llm_save_provider_chain($normalized, $userId);
            // Drop the legacy single-provider key so it can't drift out of
            // sync with the chain. Subsequent reads come exclusively from
            // llm_provider_chain.
            try { ai_settings_delete('llm_provider', $userId); } catch (Throwable $e) { /* idempotent */ }

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

            $toolStrategyIn = (string)($_POST['chatbot_default_tool_strategy'] ?? 'minimal');
            if (!in_array($toolStrategyIn, ['minimal', 'all'], true)) $toolStrategyIn = 'minimal';
            ai_settings_set('chatbot_default_tool_strategy', $toolStrategyIn, $userId);

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
$groqCfg        = $envError ? ['model' => LLM_GROQ_DEFAULT_MODEL, 'api_key' => '']   : llm_get_provider_config(LLM_PROVIDER_GROQ);
$openaiCfg      = $envError ? ['model' => LLM_OPENAI_DEFAULT_MODEL, 'api_key' => ''] : llm_get_provider_config(LLM_PROVIDER_OPENAI);
$customCfg      = $envError ? ['preset' => '', 'model' => '', 'preset_label' => '', 'config_errors' => ['preset'], 'api_key' => ''] : llm_get_provider_config(LLM_PROVIDER_CUSTOM);

// Provider chain (raw → ordered list of {provider, enabled, priority}).
$providerChainRaw = $envError ? llm_chain_from_legacy() : llm_get_provider_chain_raw();
$providerCfgByName = [
    LLM_PROVIDER_GROQ   => $groqCfg,
    LLM_PROVIDER_OPENAI => $openaiCfg,
    LLM_PROVIDER_CUSTOM => $customCfg,
];
// "Configured" = key present and (for custom) no missing required fields.
$providerConfiguredByName = [];
foreach (LLM_PROVIDER_ALL as $prov) {
    $cfg = $providerCfgByName[$prov];
    $configured = (($cfg['api_key'] ?? '') !== '');
    if ($prov === LLM_PROVIDER_CUSTOM && !empty($cfg['config_errors'])) {
        $configured = false;
    }
    $providerConfiguredByName[$prov] = $configured;
}
// Compute primary + fallbacks for the banner: highest-priority entry that
// is both enabled AND configured. Fallbacks = remaining enabled+configured
// providers in priority order.
$primaryProvider = null;
$fallbackProviders = [];
foreach ($providerChainRaw as $entry) {
    if (!$entry['enabled']) continue;
    if (!$providerConfiguredByName[$entry['provider']]) continue;
    if ($primaryProvider === null) {
        $primaryProvider = $entry['provider'];
    } else {
        $fallbackProviders[] = $entry['provider'];
    }
}
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

$currentToolStrategy = 'minimal';
if ($envError === null) {
    $stored = ai_settings_get('chatbot_default_tool_strategy');
    if ($stored === 'all') $currentToolStrategy = 'all';
}

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
        .chain-card { border: 1px solid var(--bs-border-color, #dee2e6); border-radius: 6px; padding: 10px 14px; margin-bottom: 8px; background: var(--bs-body-bg, #fff); }
        .chain-card-row { display: flex; align-items: center; gap: 12px; }
        .chain-priority-label { width: 28px; height: 28px; border-radius: 50%; background: var(--bs-secondary-bg, #e9ecef); color: var(--bs-secondary-color, #495057); display: flex; align-items: center; justify-content: center; font-weight: 600; flex-shrink: 0; }
        .chain-card-name { flex: 1; }
        .chain-card-controls { display: flex; align-items: center; gap: 4px; }
        .chain-card-controls .btn { padding: 2px 8px; font-size: 14px; line-height: 1; }
        .test-result { font-size: 12px; }
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
        $primaryCfg = $primaryProvider ? $providerCfgByName[$primaryProvider] : null;
    ?>
    <div class="provider-banner">
        <?php if ($primaryProvider !== null && $primaryCfg !== null): ?>
            <strong>Primary: <?= htmlspecialchars(llm_active_provider_banner($primaryCfg)); ?></strong>
            <div class="mt-1 small">
                <?php if (!empty($fallbackProviders)): ?>
                    Fallback order:
                    <?php $i = 2; foreach ($fallbackProviders as $fp): ?>
                        <?= $i; ?>. <?= htmlspecialchars(llm_provider_label($fp)); ?><?= ($fp !== end($fallbackProviders)) ? ',' : ''; ?>
                    <?php $i++; endforeach; ?>
                <?php else: ?>
                    No fallbacks configured.
                <?php endif; ?>
            </div>
        <?php else: ?>
            <span class="text-danger"><strong>⚠ No provider available — chatbot is disabled.</strong> Enable and fully configure at least one provider below.</span>
        <?php endif; ?>
    </div>

    <form method="post" autocomplete="off" id="ai-config-form">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']); ?>">
        <input type="hidden" name="action" value="save">

        <div class="api-key-card">
            <h4>Provider Chain</h4>
            <p class="text-muted small mb-2">
                The chatbot tries providers in priority order. If one fails on a transient error (HTTP 429, 5xx, network timeout) it falls through to the next enabled provider. Hard errors (400, 401, 403, 404) do <em>not</em> trigger fallback — those are deterministic and a different provider won't fix them. Use the arrows to reorder, and the toggle to skip a provider without losing its configuration.
            </p>
            <div id="provider-chain-cards">
                <?php foreach ($providerChainRaw as $idx => $entry):
                    $prov = $entry['provider'];
                    $configured = $providerConfiguredByName[$prov];
                    $enabled    = (bool)$entry['enabled'];
                ?>
                <div class="chain-card" data-provider="<?= htmlspecialchars($prov); ?>">
                    <div class="chain-card-row">
                        <div class="chain-priority-label">
                            <span class="priority-num"><?= (int)$entry['priority']; ?></span>
                        </div>
                        <div class="chain-card-name">
                            <strong><?= htmlspecialchars(llm_provider_label($prov)); ?></strong>
                            <?php if ($configured): ?>
                                <span class="badge bg-success ms-2">configured</span>
                            <?php else: ?>
                                <span class="badge bg-secondary ms-2">not configured</span>
                            <?php endif; ?>
                        </div>
                        <div class="chain-card-controls">
                            <button type="button" class="btn btn-sm btn-outline-secondary chain-up"    title="Move up"   aria-label="Move up">▲</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary chain-down"  title="Move down" aria-label="Move down">▼</button>
                            <div class="form-check form-switch d-inline-block ms-2">
                                <input type="checkbox" class="form-check-input chain-enabled-cb" id="chain_enabled_<?= htmlspecialchars($prov); ?>" name="chain_enabled[<?= htmlspecialchars($prov); ?>]" value="1" <?= $enabled ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="chain_enabled_<?= htmlspecialchars($prov); ?>">Enabled</label>
                            </div>
                        </div>
                    </div>
                    <input type="hidden" name="chain_priority[<?= htmlspecialchars($prov); ?>]" value="<?= (int)$entry['priority']; ?>" class="chain-priority-input">
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <details class="provider-section <?= $activeProvider === LLM_PROVIDER_GROQ ? 'active-provider' : ''; ?>" id="groq-section" <?= $activeProvider === LLM_PROVIDER_GROQ ? 'open' : ''; ?>>
            <summary>
                Groq Configuration
                <?= $activeProvider === LLM_PROVIDER_GROQ ? '<span class="badge bg-success ms-2">primary</span>' : ''; ?>
                <button type="button" class="btn btn-sm btn-outline-secondary float-end test-provider-btn" data-provider="groq" onclick="event.stopPropagation(); event.preventDefault(); testConnection('groq');">
                    <i class="fas fa-plug"></i> Test
                </button>
                <span class="test-result float-end me-2" data-for="groq"></span>
            </summary>
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
            <summary>
                OpenAI Configuration
                <?= $activeProvider === LLM_PROVIDER_OPENAI ? '<span class="badge bg-success ms-2">primary</span>' : ''; ?>
                <button type="button" class="btn btn-sm btn-outline-secondary float-end test-provider-btn" data-provider="openai" onclick="event.stopPropagation(); event.preventDefault(); testConnection('openai');">
                    <i class="fas fa-plug"></i> Test
                </button>
                <span class="test-result float-end me-2" data-for="openai"></span>
            </summary>
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
            <summary>
                Custom Provider Configuration
                <?= $customActive ? '<span class="badge bg-success ms-2">primary</span>' : ''; ?>
                <button type="button" class="btn btn-sm btn-outline-secondary float-end test-provider-btn" data-provider="custom" onclick="event.stopPropagation(); event.preventDefault(); testConnection('custom');">
                    <i class="fas fa-plug"></i> Test
                </button>
                <span class="test-result float-end me-2" data-for="custom"></span>
            </summary>
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

            <div class="mt-3">
                <label class="form-label" for="chatbot_default_tool_strategy">Tool selection strategy</label>
                <select class="form-control" id="chatbot_default_tool_strategy" name="chatbot_default_tool_strategy">
                    <option value="minimal" <?= $currentToolStrategy === 'minimal' ? 'selected' : ''; ?>>Minimal (layered) — recommended</option>
                    <option value="all"     <?= $currentToolStrategy === 'all'     ? 'selected' : ''; ?>>All tools (debug)</option>
                </select>
                <small class="text-muted">
                    <strong>Minimal</strong> picks a per-turn tool subset by intent: greetings get only <code>getMe</code>,
                    acknowledgements get zero tools, capability questions get only <code>listCapabilities</code>,
                    domain queries get the matching group, and vague queries get a curated 15–20 tool fallback.
                    Typical small messages spend 1,500–3,000 prompt tokens instead of 9,000+.
                    Switch to <strong>All tools</strong> only when debugging tool routing — it forces every turn to
                    send all 45 tool definitions and is far more expensive.
                </small>
            </div>
        </div>

        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Configuration</button>
    </form>

    <p class="text-muted small">Each provider section above has its own <strong>Test</strong> button. The API key is never sent to your browser.</p>

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
// Provider chain: up/down arrows reorder cards visually and rewrite
// the chain_priority[<provider>] hidden inputs + visible priority badges.
// Disabling a provider via the checkbox is independent of order — the
// chain saved to the server respects both: each provider has a numeric
// priority AND an enabled flag.
(function () {
    var container = document.getElementById('provider-chain-cards');
    if (!container) return;
    function renumber() {
        var cards = container.querySelectorAll('.chain-card');
        cards.forEach(function (card, idx) {
            var priority = idx + 1;
            var num   = card.querySelector('.priority-num');
            var input = card.querySelector('.chain-priority-input');
            if (num)   num.textContent = priority;
            if (input) input.value     = String(priority);
            var upBtn   = card.querySelector('.chain-up');
            var downBtn = card.querySelector('.chain-down');
            if (upBtn)   upBtn.disabled   = (idx === 0);
            if (downBtn) downBtn.disabled = (idx === cards.length - 1);
        });
    }
    container.addEventListener('click', function (e) {
        var btn = e.target.closest('button');
        if (!btn) return;
        var card = btn.closest('.chain-card');
        if (!card) return;
        if (btn.classList.contains('chain-up') && card.previousElementSibling) {
            container.insertBefore(card, card.previousElementSibling);
            renumber();
        } else if (btn.classList.contains('chain-down') && card.nextElementSibling) {
            container.insertBefore(card.nextElementSibling, card);
            renumber();
        }
    });
    renumber();
})();

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

function testConnection(provider) {
    var btn = document.querySelector('.test-provider-btn[data-provider="' + provider + '"]');
    var out = document.querySelector('.test-result[data-for="' + provider + '"]');
    if (!out) return;
    if (btn) btn.disabled = true;
    out.innerHTML = '<span class="text-muted small">Testing…</span>';
    fetch('ai_test_connection.php?provider=' + encodeURIComponent(provider), {credentials: 'same-origin'})
        .then(function (r) { return r.json(); })
        .then(function (j) {
            if (btn) btn.disabled = false;
            if (j.ok) {
                var count = (j.model_count != null) ? (j.model_count + ' models') : 'reachable';
                out.innerHTML = '<span class="ai-status-ok small"><i class="fas fa-check-circle"></i> ' + count + '</span>';
            } else {
                var msg = j.message || 'Unknown error';
                var d = document.createElement('span');
                d.className = 'ai-status-bad small';
                d.textContent = '⚠ ' + msg;
                out.innerHTML = '';
                out.appendChild(d);
            }
        })
        .catch(function (e) {
            if (btn) btn.disabled = false;
            var d = document.createElement('span');
            d.className = 'ai-status-bad small';
            d.textContent = '⚠ ' + e;
            out.innerHTML = '';
            out.appendChild(d);
        });
}
</script>

<?php include 'footer.php'; ?>
</body>
</html>
