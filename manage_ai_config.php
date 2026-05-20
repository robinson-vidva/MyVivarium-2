<?php
// AI Configuration admin page (multi-config manager).
// Admins create named provider configurations, reorder them as a fallback
// chain, manage per-config primary + secondary keys, system prompt,
// temperature, max tokens, free-form key/value rows, and global chatbot
// settings (default system prompt, rate limits, enable flag, tool strategy).
// All state-changing requests require an admin session and a valid CSRF token.

require 'session_config.php';
require 'dbcon.php';
require_once 'log_activity.php';
require_once __DIR__ . '/includes/ai_settings.php';
require_once __DIR__ . '/includes/llm_provider.php';
require_once __DIR__ . '/includes/ai_configs.php';
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

function aimc_json_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function aimc_is_ajax(): bool
{
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        return true;
    }
    if (!empty($_SERVER['HTTP_ACCEPT']) && stripos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false) {
        return true;
    }
    return false;
}

$saveError   = null;
$saveSuccess = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfOk = isset($_POST['csrf_token']) && hash_equals($_SESSION['csrf_token'], (string)$_POST['csrf_token']);
    if (!$csrfOk) {
        if (aimc_is_ajax()) aimc_json_response(['ok' => false, 'message' => 'CSRF token validation failed.'], 400);
        die('CSRF token validation failed');
    }
    if ($envError !== null) {
        $msg = 'Cannot save: ' . $envError;
        if (aimc_is_ajax()) aimc_json_response(['ok' => false, 'message' => $msg], 500);
        $_SESSION['message'] = $msg;
        header('Location: manage_ai_config.php');
        exit;
    }
    $action = (string)($_POST['action'] ?? '');
    $userId = (int)$_SESSION['user_id'];

    try {
        if ($action === 'save_globals') {
            $prompt = (string)($_POST['system_prompt'] ?? '');
            $enabled = isset($_POST['chatbot_enabled']) ? '1' : '0';
            if ($prompt !== '') {
                ai_settings_set('system_prompt', $prompt, $userId);
            }
            ai_settings_set('chatbot_enabled', $enabled, $userId);

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

            $_SESSION['message'] = 'Global settings saved.';
            header('Location: manage_ai_config.php');
            exit;
        }
        if ($action === 'save_config') {
            $id = isset($_POST['config_id']) && (int)$_POST['config_id'] > 0 ? (int)$_POST['config_id'] : null;
            $customKeys   = isset($_POST['custom_key'])   && is_array($_POST['custom_key'])   ? $_POST['custom_key']   : [];
            $customValues = isset($_POST['custom_value']) && is_array($_POST['custom_value']) ? $_POST['custom_value'] : [];
            $customSettings = [];
            $n = min(count($customKeys), count($customValues));
            for ($i = 0; $i < $n; $i++) {
                $k = trim((string)$customKeys[$i]);
                $v = (string)$customValues[$i];
                if ($k === '') continue;
                $customSettings[] = ['key' => $k, 'value' => $v];
            }

            $data = [
                'nickname'          => (string)($_POST['nickname'] ?? ''),
                'provider'          => (string)($_POST['provider'] ?? ''),
                'model'             => (string)($_POST['model'] ?? ''),
                'preset'            => (string)($_POST['preset'] ?? ''),
                'base_url'          => (string)($_POST['base_url'] ?? ''),
                'system_prompt'     => (string)($_POST['system_prompt'] ?? ''),
                'temperature'       => (string)($_POST['temperature'] ?? ''),
                'max_tokens'        => (string)($_POST['max_tokens'] ?? ''),
                'enabled'           => isset($_POST['enabled']) ? 1 : 0,
                'is_default'        => isset($_POST['is_default']) ? 1 : 0,
                'api_key_primary'   => (string)($_POST['api_key_primary'] ?? ''),
                'api_key_secondary' => (string)($_POST['api_key_secondary'] ?? ''),
                'custom_settings'   => $customSettings,
            ];
            if (!empty($_POST['clear_primary_key']))   $data['api_key_primary']   = '__clear__';
            if (!empty($_POST['clear_secondary_key'])) $data['api_key_secondary'] = '__clear__';

            $savedId = ai_configs_save($con, $id, $data, $userId);
            $_SESSION['message'] = $id ? 'Configuration updated.' : 'Configuration added.';
            header('Location: manage_ai_config.php?highlight=' . $savedId);
            exit;
        }
        if ($action === 'delete_config') {
            $id = (int)($_POST['config_id'] ?? 0);
            if ($id > 0) {
                ai_configs_delete($con, $id, $userId);
                $_SESSION['message'] = 'Configuration deleted.';
            }
            header('Location: manage_ai_config.php');
            exit;
        }
        if ($action === 'toggle_config') {
            $id = (int)($_POST['config_id'] ?? 0);
            $on = !empty($_POST['enabled']);
            if ($id > 0) ai_configs_set_enabled($con, $id, $on, $userId);
            if (aimc_is_ajax()) aimc_json_response(['ok' => true, 'enabled' => $on]);
            header('Location: manage_ai_config.php');
            exit;
        }
        if ($action === 'reorder_configs') {
            $orderRaw = (string)($_POST['order'] ?? '');
            $ids = array_filter(array_map('intval', explode(',', $orderRaw)));
            ai_configs_reorder($con, $ids, $userId);
            if (aimc_is_ajax()) aimc_json_response(['ok' => true]);
            header('Location: manage_ai_config.php');
            exit;
        }
        if ($action === 'set_default') {
            $id = (int)($_POST['config_id'] ?? 0);
            if ($id > 0) ai_configs_set_default($con, $id, $userId);
            if (aimc_is_ajax()) aimc_json_response(['ok' => true]);
            header('Location: manage_ai_config.php');
            exit;
        }
        throw new RuntimeException('Unknown action.');
    } catch (Throwable $e) {
        if (aimc_is_ajax()) aimc_json_response(['ok' => false, 'message' => $e->getMessage()], 400);
        $saveError = $e->getMessage();
    }
}

if ($envError === null) {
    try {
        $migrated = ai_configs_migrate_from_legacy($con, (int)$_SESSION['user_id']);
        if ($migrated > 0) {
            $_SESSION['message'] = "Imported $migrated existing provider(s) into the new configuration list.";
        }
    } catch (Throwable $e) {
        error_log('AI config migrate-from-legacy failed: ' . $e->getMessage());
    }
}

$configs = $envError ? [] : ai_configs_list($con, false);

$promptMeta  = $envError ? null : ai_settings_get_meta('system_prompt');
$enabledMeta = $envError ? null : ai_settings_get_meta('chatbot_enabled');
$currentPrompt  = ($envError === null && $promptMeta) ? (ai_settings_get('system_prompt') ?? $DEFAULT_SYSTEM_PROMPT) : $DEFAULT_SYSTEM_PROMPT;
$currentEnabled = ($envError === null && $enabledMeta) ? (ai_settings_get('chatbot_enabled') === '1') : false;
$currentRateMinute = $envError ? AI_RATE_LIMIT_PER_MINUTE_DEFAULT : ai_rate_limit_get_limit('minute');
$currentRateDay    = $envError ? AI_RATE_LIMIT_PER_DAY_DEFAULT    : ai_rate_limit_get_limit('day');
$currentToolStrategy = 'minimal';
if ($envError === null) {
    $stored = ai_settings_get('chatbot_default_tool_strategy');
    if ($stored === 'all') $currentToolStrategy = 'all';
}

$primaryConfig = null;
foreach ($configs as $c) {
    if (!empty($c['enabled']) && !empty($c['api_key_primary'])) { $primaryConfig = $c; break; }
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
        .container.ai-cfg { max-width: 1100px; }
        .ai-card { background-color: var(--bs-tertiary-bg); padding: 18px; border-radius: 8px; margin-bottom: 18px; }
        .ai-status-ok  { color: var(--bs-success); }
        .ai-status-bad { color: var(--bs-danger); }
        .provider-banner { padding: 10px 14px; border-radius: 6px; background: var(--bs-info-bg-subtle, #cff4fc); color: var(--bs-info-text-emphasis, #055160); margin-bottom: 14px; }
        .config-card { border: 1px solid var(--bs-border-color, #dee2e6); border-radius: 8px; padding: 14px; margin-bottom: 10px; background: var(--bs-body-bg, #fff); cursor: grab; }
        .config-card.dragging { opacity: 0.5; }
        .config-card.drag-over { border-color: var(--bs-primary); background: var(--bs-primary-bg-subtle, #cfe2ff); }
        .config-card-header { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; }
        .config-priority { width: 30px; height: 30px; border-radius: 50%; background: var(--bs-secondary-bg, #e9ecef); color: var(--bs-secondary-color, #495057); display: inline-flex; align-items: center; justify-content: center; font-weight: 600; flex-shrink: 0; }
        .config-meta { flex: 1 1 200px; min-width: 200px; }
        .config-actions { display: flex; flex-wrap: wrap; gap: 4px; align-items: center; }
        .config-actions .btn { padding: 4px 10px; font-size: 14px; line-height: 1.2; }
        .drag-handle { cursor: grab; color: var(--bs-secondary-color); padding: 0 6px; }
        .drag-handle:active { cursor: grabbing; }
        .config-test-result { font-size: 12px; margin-left: 8px; }
        .empty-state { padding: 40px 20px; text-align: center; color: var(--bs-secondary-color); border: 2px dashed var(--bs-border-color, #dee2e6); border-radius: 8px; }
        .custom-kv-row { display: flex; gap: 8px; margin-bottom: 6px; }
        .custom-kv-row input { flex: 1; }
        textarea.system-prompt { font-family: monospace; }
        .usage-chart { width: 100%; height: 140px; }
        .usage-chart rect { fill: var(--bs-primary); }
        .usage-stat { padding: 10px; background: var(--bs-tertiary-bg); border-radius: 6px; text-align: center; }
        .usage-stat .value { font-size: 1.4rem; font-weight: 600; }
        .usage-stat .label { font-size: 0.85rem; color: var(--bs-secondary-color); }
        @media (max-width: 576px) {
            .config-actions { width: 100%; justify-content: flex-end; }
            .config-meta    { flex: 1 1 100%; }
        }
    </style>
</head>
<body>
<div class="container mt-4 content ai-cfg">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
        <h1 class="mb-0">AI Configuration</h1>
        <div class="d-flex gap-2">
            <button type="button" class="btn btn-outline-info" data-bs-toggle="modal" data-bs-target="#tokenUsageModal" id="openUsageBtn">
                <i class="fas fa-chart-bar"></i> Token Usage
            </button>
            <button type="button" class="btn btn-primary" id="openAddConfigBtn">
                <i class="fas fa-plus"></i> Add Configuration
            </button>
        </div>
    </div>
    <p class="text-muted">Each configuration is one named provider profile. The chatbot tries enabled configs in priority order, with the primary key first and the optional secondary key on transient failures (429, 5xx, network). Keys are encrypted at rest with AES-256-CBC and never echoed back.</p>

    <?php include 'message.php'; ?>

    <?php if ($envError !== null): ?>
        <div class="alert alert-danger"><strong>Encryption key error:</strong> <?= htmlspecialchars($envError); ?></div>
    <?php endif; ?>
    <?php if ($saveError !== null): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($saveError); ?></div>
    <?php endif; ?>

    <div class="provider-banner">
        <?php if ($primaryConfig !== null): ?>
            <strong>Primary: <?= htmlspecialchars($primaryConfig['nickname']); ?></strong>
            (<?= htmlspecialchars(llm_provider_label((string)$primaryConfig['provider'])); ?>
            <?= htmlspecialchars($primaryConfig['model'] !== '' ? ' / ' . $primaryConfig['model'] : ''); ?>)
        <?php else: ?>
            <span class="text-danger"><strong>No active configuration.</strong> Add at least one enabled configuration with a primary API key for the chatbot to work.</span>
        <?php endif; ?>
    </div>

    <div class="ai-card">
        <h4 class="mb-3">Configurations</h4>
        <p class="text-muted small mb-2">
            Drag a card or use the up/down arrows to change the fallback order. Toggle the switch to enable or disable without losing the config. The Test button pings the provider with the primary key, then the secondary if the primary fails on a transient error.
        </p>
        <div id="configCardsContainer">
            <?php if (empty($configs)): ?>
                <div class="empty-state">
                    <p class="mb-2"><i class="fas fa-robot fa-2x"></i></p>
                    <p>No configurations yet. Click <strong>Add Configuration</strong> to create the first one.</p>
                </div>
            <?php else: foreach ($configs as $idx => $c):
                $hl = isset($_GET['highlight']) && (int)$_GET['highlight'] === (int)$c['id']; ?>
                <div class="config-card<?= $hl ? ' border-primary' : ''; ?>" draggable="true" data-config-id="<?= (int)$c['id']; ?>">
                    <div class="config-card-header">
                        <span class="drag-handle" title="Drag to reorder"><i class="fas fa-grip-vertical"></i></span>
                        <span class="config-priority"><?= $idx + 1; ?></span>
                        <div class="config-meta">
                            <div>
                                <strong><?= htmlspecialchars($c['nickname']); ?></strong>
                                <?php if (!empty($c['is_default'])): ?>
                                    <span class="badge bg-primary ms-1">default</span>
                                <?php endif; ?>
                                <?php if (empty($c['api_key_primary'])): ?>
                                    <span class="badge bg-secondary ms-1">no key</span>
                                <?php else: ?>
                                    <span class="badge bg-success ms-1">key set</span>
                                <?php endif; ?>
                                <?php if (!empty($c['api_key_secondary'])): ?>
                                    <span class="badge bg-info ms-1">+ fallback key</span>
                                <?php endif; ?>
                            </div>
                            <small class="text-muted">
                                <?= htmlspecialchars(llm_provider_label((string)$c['provider'])); ?>
                                <?php if ($c['provider'] === 'custom' && !empty($c['preset'])): ?>
                                    / <?= htmlspecialchars(llm_custom_preset_label((string)$c['preset'])); ?>
                                <?php endif; ?>
                                <?php if (!empty($c['model'])): ?>
                                    / <?= htmlspecialchars($c['model']); ?>
                                <?php endif; ?>
                            </small>
                            <span class="config-test-result" data-for-config="<?= (int)$c['id']; ?>"></span>
                        </div>
                        <div class="config-actions">
                            <button type="button" class="btn btn-sm btn-outline-secondary move-up"   title="Move up"   aria-label="Move up"><i class="fas fa-arrow-up"></i></button>
                            <button type="button" class="btn btn-sm btn-outline-secondary move-down" title="Move down" aria-label="Move down"><i class="fas fa-arrow-down"></i></button>
                            <div class="form-check form-switch d-inline-block mx-2 mb-0">
                                <input type="checkbox" class="form-check-input enabled-switch" id="enabled_<?= (int)$c['id']; ?>" <?= !empty($c['enabled']) ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="enabled_<?= (int)$c['id']; ?>">On</label>
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-info test-btn"   title="Test connection"><i class="fas fa-plug"></i></button>
                            <button type="button" class="btn btn-sm btn-outline-primary edit-btn"   title="Edit"><i class="fas fa-pen"></i></button>
                            <button type="button" class="btn btn-sm btn-outline-danger  delete-btn" title="Delete"><i class="fas fa-trash"></i></button>
                        </div>
                    </div>
                </div>
            <?php endforeach; endif; ?>
        </div>
    </div>

    <form method="post" id="globalSettingsForm">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']); ?>">
        <input type="hidden" name="action" value="save_globals">

        <div class="ai-card">
            <h4>Default System Prompt</h4>
            <p class="text-muted small mb-2">Used by any configuration that does not set its own prompt override.</p>
            <textarea name="system_prompt" class="form-control system-prompt" rows="5"><?= htmlspecialchars($currentPrompt); ?></textarea>
        </div>

        <div class="ai-card">
            <h4>Rate Limiting</h4>
            <p class="text-muted">Per-user limit on AI chatbot messages. Counts user messages only; tool calls and LLM round-trips do not count.</p>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label" for="ai_rate_limit_messages_per_minute">Messages per minute</label>
                    <input type="number" class="form-control" id="ai_rate_limit_messages_per_minute" name="ai_rate_limit_messages_per_minute"
                           min="<?= AI_RATE_LIMIT_PER_MINUTE_MIN; ?>" max="<?= AI_RATE_LIMIT_PER_MINUTE_MAX; ?>"
                           value="<?= htmlspecialchars((string)$currentRateMinute); ?>">
                    <small class="text-muted">Default <?= AI_RATE_LIMIT_PER_MINUTE_DEFAULT; ?>, min <?= AI_RATE_LIMIT_PER_MINUTE_MIN; ?>, max <?= AI_RATE_LIMIT_PER_MINUTE_MAX; ?>.</small>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="ai_rate_limit_messages_per_day">Messages per day</label>
                    <input type="number" class="form-control" id="ai_rate_limit_messages_per_day" name="ai_rate_limit_messages_per_day"
                           min="<?= AI_RATE_LIMIT_PER_DAY_MIN; ?>" max="<?= AI_RATE_LIMIT_PER_DAY_MAX; ?>"
                           value="<?= htmlspecialchars((string)$currentRateDay); ?>">
                    <small class="text-muted">Default <?= AI_RATE_LIMIT_PER_DAY_DEFAULT; ?>, min <?= AI_RATE_LIMIT_PER_DAY_MIN; ?>, max <?= AI_RATE_LIMIT_PER_DAY_MAX; ?>.</small>
                </div>
            </div>
        </div>

        <div class="ai-card">
            <h4>Chatbot</h4>
            <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" id="chatbot_enabled" name="chatbot_enabled" value="1" <?= $currentEnabled ? 'checked' : ''; ?>>
                <label class="form-check-label" for="chatbot_enabled">Enable chatbot</label>
            </div>
            <div class="mt-3">
                <label class="form-label" for="chatbot_default_tool_strategy">Tool selection strategy</label>
                <select class="form-control" id="chatbot_default_tool_strategy" name="chatbot_default_tool_strategy">
                    <option value="minimal" <?= $currentToolStrategy === 'minimal' ? 'selected' : ''; ?>>Minimal (layered) - recommended</option>
                    <option value="all"     <?= $currentToolStrategy === 'all'     ? 'selected' : ''; ?>>All tools (debug)</option>
                </select>
                <small class="text-muted">Minimal sends a per-turn tool subset chosen by intent. All tools sends every definition each turn and is far more expensive.</small>
            </div>
        </div>

        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Global Settings</button>
    </form>
</div>

<!-- Add/Edit Configuration modal -->
<div class="modal fade" id="configModal" tabindex="-1" aria-labelledby="configModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <form method="post" id="configModalForm">
        <div class="modal-header">
          <h5 class="modal-title" id="configModalLabel">Add Configuration</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']); ?>">
          <input type="hidden" name="action" value="save_config">
          <input type="hidden" name="config_id" id="modal_config_id" value="">
          <div class="row g-3">
            <div class="col-md-8">
              <label class="form-label" for="modal_nickname">Nickname <span class="text-danger">*</span></label>
              <input type="text" class="form-control" id="modal_nickname" name="nickname" maxlength="100" required>
              <small class="text-muted">Short label that identifies this config in the cards list.</small>
            </div>
            <div class="col-md-4 d-flex align-items-end">
              <div class="form-check form-switch mb-2">
                <input class="form-check-input" type="checkbox" id="modal_enabled" name="enabled" value="1" checked>
                <label class="form-check-label" for="modal_enabled">Enabled</label>
              </div>
              <div class="form-check form-switch mb-2 ms-3">
                <input class="form-check-input" type="checkbox" id="modal_is_default" name="is_default" value="1">
                <label class="form-check-label" for="modal_is_default">Default</label>
              </div>
            </div>
            <div class="col-md-6">
              <label class="form-label" for="modal_provider">Provider</label>
              <select class="form-select" id="modal_provider" name="provider">
                <option value="groq">Groq</option>
                <option value="openai">OpenAI</option>
                <option value="custom">Custom</option>
              </select>
            </div>
            <div class="col-md-6 preset-wrapper" style="display:none;">
              <label class="form-label" for="modal_preset">Preset</label>
              <select class="form-select" id="modal_preset" name="preset">
                <option value="">- pick a preset -</option>
                <option value="azure_openai">Azure OpenAI</option>
                <option value="azure_anthropic">Azure Anthropic</option>
                <option value="openai_compatible">OpenAI-compatible</option>
              </select>
            </div>
            <div class="col-md-6 model-wrapper">
              <label class="form-label" for="modal_model">Model</label>
              <select class="form-select" id="modal_model_select" name="model_select"></select>
              <input type="text" class="form-control mt-2" id="modal_model" name="model" placeholder="model id">
              <small class="text-muted" id="modal_model_help">Pick from the list, or type a custom id.</small>
            </div>
            <div class="col-md-6 baseurl-wrapper">
              <label class="form-label" for="modal_base_url">Base URL</label>
              <input type="text" class="form-control" id="modal_base_url" name="base_url" placeholder="https://...">
              <small class="text-muted">Optional for Groq / OpenAI (defaults are used). Required for Custom.</small>
            </div>
            <div class="col-md-6">
              <label class="form-label" for="modal_api_key_primary">Primary API key</label>
              <input type="password" class="form-control" id="modal_api_key_primary" name="api_key_primary" autocomplete="new-password" placeholder="paste key here">
              <div class="form-text" id="modal_primary_status"></div>
              <div class="form-check mt-1" id="modal_clear_primary_wrap" style="display:none;">
                <input class="form-check-input" type="checkbox" id="modal_clear_primary" name="clear_primary_key" value="1">
                <label class="form-check-label" for="modal_clear_primary">Clear stored primary key</label>
              </div>
            </div>
            <div class="col-md-6">
              <label class="form-label" for="modal_api_key_secondary">Secondary API key (fallback)</label>
              <input type="password" class="form-control" id="modal_api_key_secondary" name="api_key_secondary" autocomplete="new-password" placeholder="optional fallback key">
              <div class="form-text" id="modal_secondary_status"></div>
              <div class="form-check mt-1" id="modal_clear_secondary_wrap" style="display:none;">
                <input class="form-check-input" type="checkbox" id="modal_clear_secondary" name="clear_secondary_key" value="1">
                <label class="form-check-label" for="modal_clear_secondary">Clear stored secondary key</label>
              </div>
            </div>
            <div class="col-md-4">
              <label class="form-label" for="modal_temperature">Temperature</label>
              <input type="number" step="0.01" min="0" max="2" class="form-control" id="modal_temperature" name="temperature" placeholder="0.2">
              <small class="text-muted">0 - 2. Leave blank for provider default.</small>
            </div>
            <div class="col-md-4">
              <label class="form-label" for="modal_max_tokens">Max tokens</label>
              <input type="number" min="1" class="form-control" id="modal_max_tokens" name="max_tokens" placeholder="(provider default)">
            </div>
            <div class="col-12">
              <label class="form-label" for="modal_system_prompt">System prompt override (optional)</label>
              <textarea class="form-control system-prompt" id="modal_system_prompt" name="system_prompt" rows="3" placeholder="Leave blank to inherit the global default prompt."></textarea>
            </div>
            <div class="col-12">
              <label class="form-label">Custom key/value rows</label>
              <div class="form-text mb-1">
                Merged into the request body at call time. Values parse as JSON when possible so numbers and booleans send with proper types. Prefix a key with <code>header.</code> to send it as an HTTP request header instead.
              </div>
              <div id="customKvRows"></div>
              <button type="button" class="btn btn-sm btn-outline-secondary" id="addKvRowBtn">
                <i class="fas fa-plus"></i> Add row
              </button>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Configuration</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Delete confirmation modal -->
<div class="modal fade" id="deleteConfigModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post" id="deleteConfigForm">
        <div class="modal-header">
          <h5 class="modal-title">Delete configuration?</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']); ?>">
          <input type="hidden" name="action" value="delete_config">
          <input type="hidden" name="config_id" id="delete_config_id" value="">
          <p>Delete <strong id="delete_config_name"></strong>? Stored API keys for this configuration will be removed.</p>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-danger"><i class="fas fa-trash"></i> Delete</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Token usage modal -->
<div class="modal fade" id="tokenUsageModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">AI Token Usage</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div id="usageLoading" class="text-center py-4 text-muted"><i class="fas fa-spinner fa-spin"></i> Loading...</div>
        <div id="usageContent" style="display:none;">
          <div class="row g-2 mb-3">
            <div class="col-6 col-md-3"><div class="usage-stat"><div class="value" id="us-today">0</div><div class="label">Today</div></div></div>
            <div class="col-6 col-md-3"><div class="usage-stat"><div class="value" id="us-yesterday">0</div><div class="label">Yesterday</div></div></div>
            <div class="col-6 col-md-3"><div class="usage-stat"><div class="value" id="us-7d">0</div><div class="label">Last 7 days</div></div></div>
            <div class="col-6 col-md-3"><div class="usage-stat"><div class="value" id="us-30d">0</div><div class="label">Last 30 days</div></div></div>
          </div>
          <h6>Daily totals (last 30 days)</h6>
          <svg class="usage-chart" id="usageChart" viewBox="0 0 600 140" preserveAspectRatio="none"></svg>
          <h6 class="mt-3">By configuration (last 30 days)</h6>
          <div class="table-responsive">
            <table class="table table-sm table-striped">
              <thead><tr><th>Configuration</th><th class="text-end">Prompt</th><th class="text-end">Completion</th><th class="text-end">Total</th><th class="text-end">Requests</th></tr></thead>
              <tbody id="us-per-config"></tbody>
            </table>
          </div>
        </div>
        <div id="usageError" class="alert alert-danger" style="display:none;"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<script>
(function () {
    'use strict';

    var CSRF = <?= json_encode($_SESSION['csrf_token']); ?>;
    var CONFIGS = <?= json_encode(array_map(function ($c) {
        return [
            'id'                => (int)$c['id'],
            'nickname'          => (string)$c['nickname'],
            'provider'          => (string)$c['provider'],
            'model'             => (string)$c['model'],
            'preset'            => (string)$c['preset'],
            'base_url'          => (string)($c['base_url'] ?? ''),
            'system_prompt'     => (string)($c['system_prompt'] ?? ''),
            'temperature'       => $c['temperature'] === null ? '' : (string)$c['temperature'],
            'max_tokens'        => $c['max_tokens'] === null ? '' : (int)$c['max_tokens'],
            'enabled'           => !empty($c['enabled']),
            'is_default'        => !empty($c['is_default']),
            'has_primary_key'   => !empty($c['api_key_primary']),
            'has_secondary_key' => !empty($c['api_key_secondary']),
            'custom_settings'   => $c['custom_settings'] ?? [],
        ];
    }, $configs)); ?>;

    var GROQ_MODELS   = <?= json_encode(LLM_GROQ_ALLOWED_MODELS); ?>;
    var OPENAI_MODELS = <?= json_encode(LLM_OPENAI_ALLOWED_MODELS); ?>;
    var ANTHROPIC_MODELS = <?= json_encode(LLM_CUSTOM_AZURE_ANTHROPIC_MODELS); ?>;

    function el(id) { return document.getElementById(id); }
    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, function (c) {
            return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
        });
    }

    function configById(id) {
        id = parseInt(id, 10);
        for (var i = 0; i < CONFIGS.length; i++) if (CONFIGS[i].id === id) return CONFIGS[i];
        return null;
    }

    // -------- Modal: add / edit ----------
    var configModalEl = el('configModal');
    var configModal   = configModalEl ? new bootstrap.Modal(configModalEl) : null;

    function openConfigModal(cfg) {
        el('configModalLabel').textContent = cfg ? 'Edit Configuration' : 'Add Configuration';
        el('modal_config_id').value     = cfg ? cfg.id : '';
        el('modal_nickname').value      = cfg ? cfg.nickname : '';
        el('modal_provider').value      = cfg ? cfg.provider : 'groq';
        el('modal_preset').value        = cfg && cfg.preset ? cfg.preset : '';
        el('modal_base_url').value      = cfg ? cfg.base_url : '';
        el('modal_system_prompt').value = cfg ? cfg.system_prompt : '';
        el('modal_temperature').value   = cfg ? cfg.temperature : '';
        el('modal_max_tokens').value    = cfg ? cfg.max_tokens : '';
        el('modal_enabled').checked     = cfg ? !!cfg.enabled : true;
        el('modal_is_default').checked  = cfg ? !!cfg.is_default : false;
        el('modal_api_key_primary').value   = '';
        el('modal_api_key_secondary').value = '';
        el('modal_clear_primary').checked   = false;
        el('modal_clear_secondary').checked = false;

        // Key status hints + clear-checkbox visibility
        el('modal_primary_status').textContent   = cfg && cfg.has_primary_key   ? 'A key is set. Leave blank to keep it, or paste a new key to replace it.' : 'No key stored.';
        el('modal_secondary_status').textContent = cfg && cfg.has_secondary_key ? 'A secondary key is set. Leave blank to keep it, or paste a new key to replace it.' : 'No secondary key stored.';
        el('modal_clear_primary_wrap').style.display   = (cfg && cfg.has_primary_key)   ? 'block' : 'none';
        el('modal_clear_secondary_wrap').style.display = (cfg && cfg.has_secondary_key) ? 'block' : 'none';

        renderCustomKvRows(cfg ? cfg.custom_settings : []);
        onProviderChange();
        configModal.show();
    }

    function onProviderChange() {
        var prov = el('modal_provider').value;
        el('configModal').querySelectorAll('.preset-wrapper').forEach(function (n) {
            n.style.display = (prov === 'custom') ? 'block' : 'none';
        });
        rebuildModelControls();
    }

    function rebuildModelControls() {
        var prov = el('modal_provider').value;
        var preset = el('modal_preset').value;
        var sel = el('modal_model_select');
        var txt = el('modal_model');
        var help = el('modal_model_help');
        sel.innerHTML = '';
        var choices = [];
        if (prov === 'groq')   choices = GROQ_MODELS;
        else if (prov === 'openai') choices = OPENAI_MODELS;
        else if (prov === 'custom' && preset === 'azure_anthropic') choices = ANTHROPIC_MODELS;

        if (choices.length > 0) {
            sel.style.display = 'block';
            choices.forEach(function (m) {
                var opt = document.createElement('option');
                opt.value = m; opt.textContent = m;
                if (m === txt.value) opt.selected = true;
                sel.appendChild(opt);
            });
            var custom = document.createElement('option');
            custom.value = '__custom__';
            custom.textContent = 'Custom...';
            if (txt.value && choices.indexOf(txt.value) === -1) custom.selected = true;
            sel.appendChild(custom);
            txt.style.display = (sel.value === '__custom__') ? 'block' : 'none';
            help.textContent = 'Pick from the list, or "Custom..." to type a different id.';
        } else {
            sel.style.display = 'none';
            txt.style.display = 'block';
            if (prov === 'custom' && preset === 'azure_openai') {
                help.textContent = 'For Azure OpenAI this is the deployment name configured in Azure, not the OpenAI model id.';
            } else {
                help.textContent = 'Exact model id your provider expects.';
            }
        }
    }

    function renderCustomKvRows(rows) {
        var box = el('customKvRows');
        box.innerHTML = '';
        (rows || []).forEach(function (r) { appendKvRow(r.key, r.value); });
    }
    function appendKvRow(k, v) {
        var div = document.createElement('div');
        div.className = 'custom-kv-row';
        div.innerHTML = '<input type="text" class="form-control" name="custom_key[]" placeholder="key (e.g. top_p or header.x-org-id)" value="'+escapeHtml(k||'')+'">'
                      + '<input type="text" class="form-control" name="custom_value[]" placeholder="value" value="'+escapeHtml(v||'')+'">'
                      + '<button type="button" class="btn btn-sm btn-outline-danger remove-kv-row" aria-label="Remove row"><i class="fas fa-times"></i></button>';
        div.querySelector('.remove-kv-row').addEventListener('click', function () { div.remove(); });
        el('customKvRows').appendChild(div);
    }

    el('modal_provider').addEventListener('change', onProviderChange);
    el('modal_preset').addEventListener('change', rebuildModelControls);
    el('modal_model_select').addEventListener('change', function () {
        var v = this.value;
        if (v === '__custom__') {
            el('modal_model').style.display = 'block';
            el('modal_model').focus();
        } else {
            el('modal_model').value = v;
            el('modal_model').style.display = 'none';
        }
    });
    el('addKvRowBtn').addEventListener('click', function () { appendKvRow('', ''); });

    el('openAddConfigBtn').addEventListener('click', function () { openConfigModal(null); });

    // Submit handler: if user picked a model from the dropdown, copy it across.
    el('configModalForm').addEventListener('submit', function () {
        var sel = el('modal_model_select');
        if (sel && sel.style.display !== 'none' && sel.value && sel.value !== '__custom__') {
            el('modal_model').value = sel.value;
        }
    });

    // -------- Card actions: edit / delete / toggle / test / move ----------
    var container = el('configCardsContainer');

    function postForm(action, params, expectJson) {
        var fd = new FormData();
        fd.append('csrf_token', CSRF);
        fd.append('action', action);
        Object.keys(params).forEach(function (k) { fd.append(k, params[k]); });
        return fetch('manage_ai_config.php', {
            method: 'POST',
            body: fd,
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
        }).then(function (r) {
            if (expectJson) return r.json();
            return r;
        });
    }

    function renumberPriorities() {
        var cards = container.querySelectorAll('.config-card');
        cards.forEach(function (card, idx) {
            var n = card.querySelector('.config-priority');
            if (n) n.textContent = (idx + 1);
        });
    }

    function persistOrder() {
        var ids = Array.prototype.map.call(container.querySelectorAll('.config-card'), function (c) {
            return c.getAttribute('data-config-id');
        });
        postForm('reorder_configs', { order: ids.join(',') }, true).then(function (j) {
            if (!j || !j.ok) {
                console.warn('reorder failed', j);
            }
        });
    }

    container.addEventListener('click', function (e) {
        var card = e.target.closest('.config-card');
        if (!card) return;
        var id = card.getAttribute('data-config-id');
        var cfg = configById(id);
        if (e.target.closest('.edit-btn'))  { openConfigModal(cfg); return; }
        if (e.target.closest('.delete-btn')) {
            el('delete_config_id').value = id;
            el('delete_config_name').textContent = cfg ? cfg.nickname : '#' + id;
            new bootstrap.Modal(el('deleteConfigModal')).show();
            return;
        }
        if (e.target.closest('.move-up')) {
            var prev = card.previousElementSibling;
            if (prev && prev.classList.contains('config-card')) {
                container.insertBefore(card, prev);
                renumberPriorities();
                persistOrder();
            }
            return;
        }
        if (e.target.closest('.move-down')) {
            var next = card.nextElementSibling;
            if (next && next.classList.contains('config-card')) {
                container.insertBefore(next, card);
                renumberPriorities();
                persistOrder();
            }
            return;
        }
        if (e.target.closest('.test-btn')) {
            var out = card.querySelector('.config-test-result');
            out.innerHTML = '<span class="text-muted small"><i class="fas fa-spinner fa-spin"></i> Testing...</span>';
            fetch('ai_config_test_connection.php?config_id=' + encodeURIComponent(id), { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (j) {
                    if (j && j.ok) {
                        var k = j.used_key === 'secondary' ? ' (secondary key)' : '';
                        out.innerHTML = '<span class="ai-status-ok"><i class="fas fa-check-circle"></i> Connected' + escapeHtml(k) + '</span>';
                    } else {
                        out.innerHTML = '<span class="ai-status-bad"><i class="fas fa-times-circle"></i> ' + escapeHtml((j && j.message) || 'Failed') + '</span>';
                    }
                })
                .catch(function (e) {
                    out.innerHTML = '<span class="ai-status-bad"><i class="fas fa-times-circle"></i> ' + escapeHtml(String(e)) + '</span>';
                });
            return;
        }
    });

    container.addEventListener('change', function (e) {
        if (!e.target.classList.contains('enabled-switch')) return;
        var card = e.target.closest('.config-card');
        if (!card) return;
        var id = card.getAttribute('data-config-id');
        var on = e.target.checked;
        postForm('toggle_config', { config_id: id, enabled: on ? '1' : '' }, true).then(function (j) {
            if (!j || !j.ok) {
                e.target.checked = !on;
                alert((j && j.message) || 'Toggle failed.');
            } else {
                var cfg = configById(id);
                if (cfg) cfg.enabled = on;
            }
        });
    });

    // -------- Drag and drop ----------
    var dragSrc = null;
    container.addEventListener('dragstart', function (e) {
        var card = e.target.closest('.config-card');
        if (!card) return;
        dragSrc = card;
        card.classList.add('dragging');
        if (e.dataTransfer) {
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', card.getAttribute('data-config-id'));
        }
    });
    container.addEventListener('dragend', function () {
        if (dragSrc) dragSrc.classList.remove('dragging');
        container.querySelectorAll('.drag-over').forEach(function (n) { n.classList.remove('drag-over'); });
        dragSrc = null;
    });
    container.addEventListener('dragover', function (e) {
        var card = e.target.closest('.config-card');
        if (!card || card === dragSrc) return;
        e.preventDefault();
        card.classList.add('drag-over');
    });
    container.addEventListener('dragleave', function (e) {
        var card = e.target.closest('.config-card');
        if (card) card.classList.remove('drag-over');
    });
    container.addEventListener('drop', function (e) {
        e.preventDefault();
        var card = e.target.closest('.config-card');
        if (!card || !dragSrc || card === dragSrc) return;
        var rect = card.getBoundingClientRect();
        var before = (e.clientY - rect.top) < rect.height / 2;
        container.insertBefore(dragSrc, before ? card : card.nextSibling);
        card.classList.remove('drag-over');
        renumberPriorities();
        persistOrder();
    });

    // -------- Token usage popup ----------
    el('openUsageBtn').addEventListener('click', function () {
        el('usageLoading').style.display = 'block';
        el('usageContent').style.display = 'none';
        el('usageError').style.display = 'none';
        fetch('ai_usage_summary.php', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                el('usageLoading').style.display = 'none';
                if (!j || !j.ok) {
                    el('usageError').textContent = (j && j.message) || 'Failed to load summary.';
                    el('usageError').style.display = 'block';
                    return;
                }
                renderUsage(j.summary);
                el('usageContent').style.display = 'block';
            })
            .catch(function (e) {
                el('usageLoading').style.display = 'none';
                el('usageError').textContent = String(e);
                el('usageError').style.display = 'block';
            });
    });

    function fmt(n) {
        n = Number(n || 0);
        if (n >= 1000000) return (n / 1000000).toFixed(1) + 'M';
        if (n >= 1000)    return (n / 1000).toFixed(1) + 'k';
        return String(n);
    }
    function renderUsage(s) {
        el('us-today').textContent     = fmt(s.today.total);
        el('us-yesterday').textContent = fmt(s.yesterday.total);
        el('us-7d').textContent        = fmt(s.last7days.total);
        el('us-30d').textContent       = fmt(s.last30days.total);

        var chart = el('usageChart');
        chart.innerHTML = '';
        var bars = s.per_day || [];
        var max = 1;
        bars.forEach(function (b) { if (b.total > max) max = b.total; });
        var w = 600, h = 140, padTop = 8, padBot = 18;
        var bw = bars.length > 0 ? (w / bars.length) : 0;
        bars.forEach(function (b, i) {
            var barH = Math.round(((h - padTop - padBot) * b.total) / max);
            if (barH < 1 && b.total > 0) barH = 1;
            var x = i * bw + 1;
            var y = h - padBot - barH;
            var rect = document.createElementNS('http://www.w3.org/2000/svg', 'rect');
            rect.setAttribute('x', x);
            rect.setAttribute('y', y);
            rect.setAttribute('width', Math.max(bw - 2, 1));
            rect.setAttribute('height', barH);
            var t = document.createElementNS('http://www.w3.org/2000/svg', 'title');
            t.textContent = b.date + ': ' + b.total + ' tokens';
            rect.appendChild(t);
            chart.appendChild(rect);
        });
        if (bars.length) {
            var labelLeft = document.createElementNS('http://www.w3.org/2000/svg', 'text');
            labelLeft.setAttribute('x', 0); labelLeft.setAttribute('y', h - 4);
            labelLeft.setAttribute('font-size', '10');
            labelLeft.setAttribute('fill', 'currentColor');
            labelLeft.textContent = bars[0].date;
            chart.appendChild(labelLeft);
            var labelRight = document.createElementNS('http://www.w3.org/2000/svg', 'text');
            labelRight.setAttribute('x', w); labelRight.setAttribute('y', h - 4);
            labelRight.setAttribute('font-size', '10');
            labelRight.setAttribute('text-anchor', 'end');
            labelRight.setAttribute('fill', 'currentColor');
            labelRight.textContent = bars[bars.length - 1].date;
            chart.appendChild(labelRight);
        }

        var tbody = el('us-per-config');
        tbody.innerHTML = '';
        (s.per_config || []).forEach(function (row) {
            var tr = document.createElement('tr');
            tr.innerHTML = '<td>' + escapeHtml(row.label) + '</td>'
                         + '<td class="text-end">' + fmt(row.prompt)     + '</td>'
                         + '<td class="text-end">' + fmt(row.completion) + '</td>'
                         + '<td class="text-end"><strong>' + fmt(row.total) + '</strong></td>'
                         + '<td class="text-end">' + row.requests + '</td>';
            tbody.appendChild(tr);
        });
        if (!(s.per_config || []).length) {
            tbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted">No usage recorded in the last 30 days.</td></tr>';
        }
    }
})();
</script>

<?php include 'footer.php'; ?>
</body>
</html>
