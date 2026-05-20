<?php
// Multi-configuration AI provider data access + runtime resolver.
// Encryption reuses ai_settings_encrypt/_decrypt (AES-256-CBC, key in .env).
//
// TODO(retire-legacy): once production has run on ai_configs for one full
// release cycle with no rollbacks, delete the legacy provider-chain code path
// in includes/llm_provider.php (llm_get_provider_chain[_raw], llm_normalize_chain,
// llm_chain_from_legacy, llm_save_provider_chain, llm_chat_completions_with_fallback)
// and the ai_settings keys it reads (groq_api_key/openai_api_key/custom_*,
// llm_provider, llm_provider_chain). ai_chat.php's fallback branch and the
// migration logic in ai_configs_migrate_from_legacy() can go at the same time.

// Lazy-load ai_settings.php so tests can stub ai_settings_encrypt/decrypt
// + ai_settings_get without touching the real .env / DB. Matches the same
// guard llm_provider.php uses.
if (!function_exists('ai_settings_encrypt')) {
    require_once __DIR__ . '/ai_settings.php';
}
require_once __DIR__ . '/llm_provider.php';

function ai_configs_ensure_tables(mysqli $con): void
{
    static $ensured = false;
    if ($ensured) return;
    @$con->query("CREATE TABLE IF NOT EXISTS `ai_configs` (
        `id` int NOT NULL AUTO_INCREMENT,
        `nickname` varchar(100) NOT NULL,
        `provider` varchar(32) NOT NULL,
        `model` varchar(255) DEFAULT NULL,
        `preset` varchar(64) DEFAULT NULL,
        `api_key_primary` mediumtext,
        `api_key_secondary` mediumtext,
        `base_url` varchar(512) DEFAULT NULL,
        `system_prompt` text,
        `temperature` decimal(3,2) DEFAULT NULL,
        `max_tokens` int DEFAULT NULL,
        `enabled` tinyint(1) NOT NULL DEFAULT 1,
        `sort_order` int NOT NULL DEFAULT 0,
        `is_default` tinyint(1) NOT NULL DEFAULT 0,
        `created_by` int DEFAULT NULL,
        `updated_by` int DEFAULT NULL,
        `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uniq_ai_configs_nickname` (`nickname`),
        KEY `idx_ai_configs_order` (`sort_order`),
        KEY `idx_ai_configs_enabled_order` (`enabled`, `sort_order`)
    )");
    @$con->query("CREATE TABLE IF NOT EXISTS `ai_config_settings` (
        `id` int NOT NULL AUTO_INCREMENT,
        `config_id` int NOT NULL,
        `setting_key` varchar(128) NOT NULL,
        `setting_value` mediumtext,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uniq_ai_config_settings_key` (`config_id`, `setting_key`),
        KEY `idx_ai_config_settings_config` (`config_id`),
        CONSTRAINT `fk_ai_config_settings_config` FOREIGN KEY (`config_id`) REFERENCES `ai_configs` (`id`) ON DELETE CASCADE
    )");
    $col = @$con->query("SHOW COLUMNS FROM ai_usage_log LIKE 'config_id'");
    if ($col && $col->num_rows === 0) {
        @$con->query("ALTER TABLE ai_usage_log ADD COLUMN config_id int DEFAULT NULL, ADD KEY idx_ai_usage_config_created (config_id, created_at)");
    }
    if ($col) $col->close();
    $ensured = true;
}

function ai_configs_decrypt_blob(?string $blob): string
{
    if ($blob === null || $blob === '') return '';
    try {
        return ai_settings_decrypt($blob);
    } catch (Throwable $e) {
        return '';
    }
}

function ai_configs_encrypt_or_null(string $value): ?string
{
    if ($value === '') return null;
    return ai_settings_encrypt($value);
}

function ai_configs_list(mysqli $con, bool $includeSecrets = false): array
{
    ai_configs_ensure_tables($con);
    $rows = [];
    $res = $con->query("SELECT * FROM ai_configs ORDER BY sort_order ASC, id ASC");
    if (!$res) return $rows;
    while ($r = $res->fetch_assoc()) {
        if ($includeSecrets) {
            $r['api_key_primary_plain']   = ai_configs_decrypt_blob($r['api_key_primary']);
            $r['api_key_secondary_plain'] = ai_configs_decrypt_blob($r['api_key_secondary']);
        }
        $r['has_primary_key']   = !empty($r['api_key_primary']);
        $r['has_secondary_key'] = !empty($r['api_key_secondary']);
        $r['custom_settings']   = ai_configs_settings_list($con, (int)$r['id']);
        $rows[] = $r;
    }
    $res->close();
    return $rows;
}

function ai_configs_get(mysqli $con, int $id, bool $includeSecrets = false): ?array
{
    ai_configs_ensure_tables($con);
    $stmt = $con->prepare("SELECT * FROM ai_configs WHERE id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) return null;
    if ($includeSecrets) {
        $row['api_key_primary_plain']   = ai_configs_decrypt_blob($row['api_key_primary']);
        $row['api_key_secondary_plain'] = ai_configs_decrypt_blob($row['api_key_secondary']);
    }
    $row['has_primary_key']   = !empty($row['api_key_primary']);
    $row['has_secondary_key'] = !empty($row['api_key_secondary']);
    $row['custom_settings']   = ai_configs_settings_list($con, $id);
    return $row;
}

function ai_configs_settings_list(mysqli $con, int $configId): array
{
    $out = [];
    $stmt = $con->prepare("SELECT setting_key, setting_value FROM ai_config_settings WHERE config_id = ? ORDER BY id ASC");
    $stmt->bind_param('i', $configId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) {
        $out[] = [
            'key'   => (string)$r['setting_key'],
            'value' => ai_configs_decrypt_blob($r['setting_value']),
        ];
    }
    $stmt->close();
    return $out;
}

function ai_configs_count(mysqli $con): int
{
    ai_configs_ensure_tables($con);
    $r = @$con->query("SELECT COUNT(*) AS n FROM ai_configs");
    if (!$r) return 0;
    $n = (int)($r->fetch_assoc()['n'] ?? 0);
    $r->close();
    return $n;
}

function ai_configs_next_sort_order(mysqli $con): int
{
    $r = $con->query("SELECT COALESCE(MAX(sort_order), 0) + 1 AS n FROM ai_configs");
    $n = (int)($r->fetch_assoc()['n'] ?? 1);
    $r->close();
    return $n;
}

/**
 * Insert or update a config. $data keys:
 *   nickname (required, unique), provider, model, preset, base_url,
 *   system_prompt, temperature, max_tokens, enabled, is_default,
 *   api_key_primary (plaintext or '' to leave; '__clear__' to clear),
 *   api_key_secondary (same),
 *   custom_settings => [['key'=>..., 'value'=>...], ...]
 *
 * Throws on duplicate nickname or invalid input. Returns the saved id.
 */
function ai_configs_save(mysqli $con, ?int $id, array $data, int $userId): int
{
    ai_configs_ensure_tables($con);

    $nickname = trim((string)($data['nickname'] ?? ''));
    if ($nickname === '' || strlen($nickname) > 100) {
        throw new RuntimeException('Nickname is required (max 100 chars).');
    }
    $provider = (string)($data['provider'] ?? '');
    if (!in_array($provider, ['groq', 'openai', 'custom'], true)) {
        throw new RuntimeException('Invalid provider.');
    }
    $model        = trim((string)($data['model'] ?? ''));
    $preset       = trim((string)($data['preset'] ?? ''));
    $baseUrl      = trim((string)($data['base_url'] ?? ''));
    $systemPrompt = (string)($data['system_prompt'] ?? '');
    $tempRaw      = $data['temperature'] ?? '';
    $temperature  = ($tempRaw === '' || $tempRaw === null) ? null : max(0.0, min(2.0, (float)$tempRaw));
    $maxTokensRaw = $data['max_tokens'] ?? '';
    $maxTokens    = ($maxTokensRaw === '' || $maxTokensRaw === null) ? null : max(1, (int)$maxTokensRaw);
    $enabled      = !empty($data['enabled']) ? 1 : 0;
    $isDefault    = !empty($data['is_default']) ? 1 : 0;

    if ($provider === 'custom') {
        if ($preset === '' || !in_array($preset, LLM_CUSTOM_ALLOWED_PRESETS, true)) {
            throw new RuntimeException('Custom provider requires a valid preset.');
        }
        if ($baseUrl === '') {
            throw new RuntimeException('Custom provider requires a base URL.');
        }
        if ($model === '') {
            throw new RuntimeException('Custom provider requires a model / deployment name.');
        }
    } elseif ($provider === 'groq') {
        if ($model === '') $model = LLM_GROQ_DEFAULT_MODEL;
        if (!in_array($model, LLM_GROQ_ALLOWED_MODELS, true)) {
            throw new RuntimeException('Invalid Groq model.');
        }
        if ($baseUrl === '') $baseUrl = 'https://api.groq.com/openai/v1';
    } elseif ($provider === 'openai') {
        if ($model === '') $model = LLM_OPENAI_DEFAULT_MODEL;
        if (!in_array($model, LLM_OPENAI_ALLOWED_MODELS, true)) {
            throw new RuntimeException('Invalid OpenAI model.');
        }
        if ($baseUrl === '') $baseUrl = 'https://api.openai.com/v1';
    }

    $dup = $con->prepare("SELECT id FROM ai_configs WHERE nickname = ? AND id <> ?");
    $idForDup = $id ?? 0;
    $dup->bind_param('si', $nickname, $idForDup);
    $dup->execute();
    if ($dup->get_result()->fetch_assoc()) {
        $dup->close();
        throw new RuntimeException('Another configuration already uses that nickname.');
    }
    $dup->close();

    $con->begin_transaction();
    try {
        if ($id === null) {
            $sortOrder = ai_configs_next_sort_order($con);
            $primaryEnc = ai_configs_encrypt_or_null((string)($data['api_key_primary'] ?? ''));
            $secondaryRaw = (string)($data['api_key_secondary'] ?? '');
            $secondaryEnc = ($secondaryRaw === '' || $secondaryRaw === '__clear__') ? null : ai_configs_encrypt_or_null($secondaryRaw);
            $stmt = $con->prepare("INSERT INTO ai_configs
                (nickname, provider, model, preset, api_key_primary, api_key_secondary,
                 base_url, system_prompt, temperature, max_tokens, enabled, sort_order,
                 is_default, created_by, updated_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $tempStr = $temperature === null ? null : (string)$temperature;
            $stmt->bind_param('ssssssssdiiiiii',
                $nickname, $provider, $model, $preset, $primaryEnc, $secondaryEnc,
                $baseUrl, $systemPrompt, $tempStr, $maxTokens, $enabled, $sortOrder,
                $isDefault, $userId, $userId);
            $stmt->execute();
            $newId = (int)$stmt->insert_id;
            $stmt->close();
            $savedId = $newId;
            log_activity($con, 'ai_config_create', 'ai_config', (string)$newId, 'nickname=' . $nickname);
        } else {
            $existing = ai_configs_get($con, $id);
            if (!$existing) {
                throw new RuntimeException('Configuration not found.');
            }

            $primaryRaw = (string)($data['api_key_primary'] ?? '');
            if ($primaryRaw === '__clear__') {
                $primaryEnc = null;
            } elseif ($primaryRaw === '') {
                $primaryEnc = $existing['api_key_primary'];
            } else {
                $primaryEnc = ai_configs_encrypt_or_null($primaryRaw);
            }

            $secondaryRaw = (string)($data['api_key_secondary'] ?? '');
            if ($secondaryRaw === '__clear__') {
                $secondaryEnc = null;
            } elseif ($secondaryRaw === '') {
                $secondaryEnc = $existing['api_key_secondary'];
            } else {
                $secondaryEnc = ai_configs_encrypt_or_null($secondaryRaw);
            }

            $stmt = $con->prepare("UPDATE ai_configs
                SET nickname = ?, provider = ?, model = ?, preset = ?,
                    api_key_primary = ?, api_key_secondary = ?, base_url = ?,
                    system_prompt = ?, temperature = ?, max_tokens = ?,
                    enabled = ?, is_default = ?, updated_by = ?
                WHERE id = ?");
            $tempStr = $temperature === null ? null : (string)$temperature;
            $stmt->bind_param('ssssssssdiiiii',
                $nickname, $provider, $model, $preset,
                $primaryEnc, $secondaryEnc, $baseUrl,
                $systemPrompt, $tempStr, $maxTokens,
                $enabled, $isDefault, $userId, $id);
            $stmt->execute();
            $stmt->close();
            $savedId = $id;
            log_activity($con, 'ai_config_update', 'ai_config', (string)$id, 'nickname=' . $nickname);
        }

        if ($isDefault) {
            $u = $con->prepare("UPDATE ai_configs SET is_default = 0 WHERE id <> ?");
            $u->bind_param('i', $savedId);
            $u->execute();
            $u->close();
        }

        $del = $con->prepare("DELETE FROM ai_config_settings WHERE config_id = ?");
        $del->bind_param('i', $savedId);
        $del->execute();
        $del->close();

        $custom = $data['custom_settings'] ?? [];
        if (is_array($custom)) {
            $ins = $con->prepare("INSERT INTO ai_config_settings (config_id, setting_key, setting_value) VALUES (?, ?, ?)");
            $seen = [];
            foreach ($custom as $row) {
                if (!is_array($row)) continue;
                $k = trim((string)($row['key'] ?? ''));
                $v = (string)($row['value'] ?? '');
                if ($k === '' || isset($seen[$k])) continue;
                if (strlen($k) > 128) continue;
                $seen[$k] = true;
                $enc = $v === '' ? null : ai_settings_encrypt($v);
                $ins->bind_param('iss', $savedId, $k, $enc);
                $ins->execute();
            }
            $ins->close();
        }

        $con->commit();
        return $savedId;
    } catch (Throwable $e) {
        $con->rollback();
        throw $e;
    }
}

function ai_configs_delete(mysqli $con, int $id, int $userId): void
{
    ai_configs_ensure_tables($con);
    $existing = ai_configs_get($con, $id);
    if (!$existing) return;
    $stmt = $con->prepare("DELETE FROM ai_configs WHERE id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $stmt->close();
    log_activity($con, 'ai_config_delete', 'ai_config', (string)$id, 'nickname=' . ($existing['nickname'] ?? ''));
}

function ai_configs_set_enabled(mysqli $con, int $id, bool $enabled, int $userId): void
{
    ai_configs_ensure_tables($con);
    $e = $enabled ? 1 : 0;
    $stmt = $con->prepare("UPDATE ai_configs SET enabled = ?, updated_by = ? WHERE id = ?");
    $stmt->bind_param('iii', $e, $userId, $id);
    $stmt->execute();
    $stmt->close();
    log_activity($con, 'ai_config_toggle', 'ai_config', (string)$id, $enabled ? 'enabled' : 'disabled');
}

function ai_configs_reorder(mysqli $con, array $orderedIds, int $userId): void
{
    ai_configs_ensure_tables($con);
    $stmt = $con->prepare("UPDATE ai_configs SET sort_order = ?, updated_by = ? WHERE id = ?");
    $i = 1;
    foreach ($orderedIds as $id) {
        $id = (int)$id;
        if ($id <= 0) continue;
        $stmt->bind_param('iii', $i, $userId, $id);
        $stmt->execute();
        $i++;
    }
    $stmt->close();
    log_activity($con, 'ai_config_reorder', 'ai_config', null, 'order=' . implode(',', array_map('intval', $orderedIds)));
}

function ai_configs_set_default(mysqli $con, int $id, int $userId): void
{
    ai_configs_ensure_tables($con);
    $con->begin_transaction();
    try {
        $con->query("UPDATE ai_configs SET is_default = 0");
        $stmt = $con->prepare("UPDATE ai_configs SET is_default = 1, updated_by = ? WHERE id = ?");
        $stmt->bind_param('ii', $userId, $id);
        $stmt->execute();
        $stmt->close();
        $con->commit();
    } catch (Throwable $e) {
        $con->rollback();
        throw $e;
    }
    log_activity($con, 'ai_config_default', 'ai_config', (string)$id, 'set default');
}

/**
 * Build a runtime config dict (same shape as llm_get_provider_config())
 * for use with llm_chat_completions(). $whichKey is 'primary' or 'secondary'.
 * Returns null if the requested key is not set.
 */
function ai_configs_to_runtime(array $row, string $whichKey = 'primary'): ?array
{
    $keyBlob = $whichKey === 'secondary' ? ($row['api_key_secondary'] ?? null) : ($row['api_key_primary'] ?? null);
    $apiKey  = ai_configs_decrypt_blob($keyBlob);
    if ($apiKey === '') return null;

    $provider = (string)($row['provider'] ?? '');
    $model    = (string)($row['model']    ?? '');
    $baseUrl  = rtrim((string)($row['base_url'] ?? ''), '/');
    $preset   = (string)($row['preset']   ?? '');

    $cfg = [
        'provider'              => $provider,
        'api_key'               => $apiKey,
        'model'                 => $model,
        'base_url'              => $baseUrl,
        'request_url'           => '',
        'test_url'              => '',
        'auth_header'           => 'Authorization',
        'auth_prefix'           => 'Bearer ',
        'extra_headers'         => [],
        'body_format'           => 'openai_chat',
        'token_field'           => 'max_tokens',
        'include_model_in_body' => true,
        'allowed_models'        => [],
        'preset'                => $preset,
        'preset_label'          => llm_custom_preset_label($preset),
        'config_errors'         => [],
        'config_id'             => isset($row['id']) ? (int)$row['id'] : 0,
        'config_nickname'       => (string)($row['nickname'] ?? ''),
        'custom_settings'       => $row['custom_settings'] ?? [],
        'temperature_override'  => $row['temperature'] === null || $row['temperature'] === ''
                                   ? null : (float)$row['temperature'],
        'max_tokens_override'   => $row['max_tokens']  === null || $row['max_tokens']  === ''
                                   ? null : (int)$row['max_tokens'],
    ];

    if ($provider === 'groq') {
        if ($baseUrl === '') $cfg['base_url'] = 'https://api.groq.com/openai/v1';
        $cfg['request_url'] = $cfg['base_url'] . '/chat/completions';
        $cfg['test_url']    = $cfg['base_url'] . '/models';
        $cfg['allowed_models'] = LLM_GROQ_ALLOWED_MODELS;
        if ($model === '') $cfg['model'] = LLM_GROQ_DEFAULT_MODEL;
    } elseif ($provider === 'openai') {
        if ($baseUrl === '') $cfg['base_url'] = 'https://api.openai.com/v1';
        $cfg['request_url'] = $cfg['base_url'] . '/chat/completions';
        $cfg['test_url']    = $cfg['base_url'] . '/models';
        $cfg['allowed_models'] = LLM_OPENAI_ALLOWED_MODELS;
        if ($model === '') $cfg['model'] = LLM_OPENAI_DEFAULT_MODEL;
    } elseif ($provider === 'custom') {
        $apiVersion = LLM_CUSTOM_DEFAULT_AZURE_API_VERSION;
        foreach (($row['custom_settings'] ?? []) as $s) {
            if (($s['key'] ?? '') === 'api_version' && ($s['value'] ?? '') !== '') {
                $apiVersion = (string)$s['value'];
            }
        }
        if ($preset === LLM_CUSTOM_PRESET_AZURE_OPENAI) {
            $cfg['request_url'] = $baseUrl . '/openai/deployments/' . rawurlencode($model)
                                . '/chat/completions?api-version=' . rawurlencode($apiVersion);
            $cfg['test_url']    = $cfg['request_url'];
            $cfg['auth_header'] = 'api-key';
            $cfg['auth_prefix'] = '';
            $cfg['include_model_in_body'] = false;
            $depLower = strtolower($model);
            $cfg['token_field'] = (strpos($depLower, 'gpt-5') === 0
                                || strpos($depLower, 'o1')    === 0
                                || strpos($depLower, 'o3')    === 0)
                                ? 'max_completion_tokens' : 'max_tokens';
        } elseif ($preset === LLM_CUSTOM_PRESET_AZURE_ANTHROPIC) {
            $cfg['request_url']   = $baseUrl . '/anthropic/v1/messages';
            $cfg['test_url']      = $cfg['request_url'];
            $cfg['auth_header']   = 'x-api-key';
            $cfg['auth_prefix']   = '';
            $cfg['extra_headers'] = ['anthropic-version: ' . LLM_CUSTOM_ANTHROPIC_VERSION];
            $cfg['body_format']   = 'anthropic_messages';
            $cfg['token_field']   = 'max_tokens';
        } elseif ($preset === LLM_CUSTOM_PRESET_OPENAI_COMPATIBLE) {
            $cfg['request_url'] = $baseUrl . '/chat/completions';
            $cfg['test_url']    = $baseUrl . '/models';
            $cfg['body_format'] = 'openai_chat';
            foreach (($row['custom_settings'] ?? []) as $s) {
                if (($s['key'] ?? '') === 'token_field' && ($s['value'] ?? '') === 'max_completion_tokens') {
                    $cfg['token_field'] = 'max_completion_tokens';
                }
            }
        } else {
            $cfg['config_errors'][] = 'preset';
        }
    } else {
        $cfg['config_errors'][] = 'provider';
    }

    return $cfg;
}

/**
 * Custom settings get merged into the outgoing request. Keys prefixed with
 * "header." become HTTP headers; everything else becomes a top-level body field.
 * Values try json_decode first so 0.7 / true / [1,2] arrive with proper types.
 *
 * Returns ['headers' => [...], 'body_extras' => [...]].
 */
function ai_configs_split_custom_settings(array $customSettings): array
{
    $headers = [];
    $body    = [];
    foreach ($customSettings as $row) {
        $k = (string)($row['key'] ?? '');
        $v = (string)($row['value'] ?? '');
        if ($k === '') continue;
        if (strpos($k, 'header.') === 0) {
            $hName = substr($k, strlen('header.'));
            if ($hName === '') continue;
            $headers[] = $hName . ': ' . $v;
            continue;
        }
        $decoded = json_decode($v, true);
        if ($decoded === null && strtolower(trim($v)) !== 'null') {
            $body[$k] = $v;
        } else {
            $body[$k] = $decoded;
        }
    }
    return ['headers' => $headers, 'body_extras' => $body];
}

/**
 * Build, send, and parse one chat-completions request for a single ai_config
 * runtime dict. Returns the same envelope shape as llm_chat_completions().
 * Custom settings are merged here so each per-config retry sees the same
 * extras.
 */
function ai_configs_chat_once(array $cfg, array $messages, array $tools, ?int $maxTokens): array
{
    if (!empty($cfg['config_errors'])) {
        return [
            'ok' => false, 'status' => 0,
            'error' => 'misconfigured: ' . implode(', ', $cfg['config_errors']),
            'body' => null, 'raw' => '',
            'provider' => $cfg['provider'] ?? '', 'model' => $cfg['model'] ?? '',
        ];
    }
    if (($cfg['api_key'] ?? '') === '') {
        return [
            'ok' => false, 'status' => 0, 'error' => 'no_api_key',
            'body' => null, 'raw' => '',
            'provider' => $cfg['provider'] ?? '', 'model' => $cfg['model'] ?? '',
        ];
    }

    $maxTokensEff = $maxTokens;
    if ($maxTokensEff === null && !empty($cfg['max_tokens_override'])) {
        $maxTokensEff = (int)$cfg['max_tokens_override'];
    }
    $req = llm_build_chat_request($cfg, $messages, $tools, $maxTokensEff);

    $bodyArr = $req['body_arr'];
    if (!empty($cfg['temperature_override']) && isset($bodyArr['temperature'])) {
        $bodyArr['temperature'] = (float)$cfg['temperature_override'];
    }
    $split = ai_configs_split_custom_settings($cfg['custom_settings'] ?? []);
    foreach ($split['body_extras'] as $k => $v) {
        $bodyArr[$k] = $v;
    }
    $headers = $req['headers'];
    foreach ($split['headers'] as $h) {
        $headers[] = $h;
    }
    $bodyStr = json_encode($bodyArr, JSON_UNESCAPED_SLASHES);

    [$status, $raw, $curlErr] = llm_http_post($req['url'], $headers, $bodyStr);

    if ($status === 0) {
        return [
            'ok' => false, 'status' => 0,
            'error' => $curlErr !== '' ? $curlErr : 'network error',
            'body' => null, 'raw' => '',
            'provider' => $cfg['provider'] ?? '', 'model' => $cfg['model'] ?? '',
        ];
    }
    $decoded = json_decode($raw, true);
    $ok      = ($status >= 200 && $status < 300);
    $bodyOut = is_array($decoded) ? $decoded : null;
    if ($ok && $bodyOut !== null && ($cfg['body_format'] ?? '') === 'anthropic_messages') {
        $bodyOut = llm_translate_anthropic_response($bodyOut);
    }
    return [
        'ok' => $ok, 'status' => $status, 'error' => null,
        'body' => $bodyOut, 'raw' => $raw,
        'provider' => $cfg['provider'] ?? '', 'model' => $cfg['model'] ?? '',
    ];
}

/**
 * Enabled configs only, in sort_order. The chatbot iterates these.
 */
function ai_configs_enabled_chain(mysqli $con): array
{
    $out = [];
    foreach (ai_configs_list($con, false) as $row) {
        if (empty($row['enabled'])) continue;
        $out[] = $row;
    }
    return $out;
}

/**
 * Full fallback pipeline:
 *   for each enabled config in sort_order:
 *     try primary key
 *     on transient failure (429/5xx/network): try secondary key if set
 *     on transient failure of secondary (or no secondary): move to next config
 *     on deterministic failure (400/401/403/404/422): return immediately
 *   if all configs exhausted: return last error with chain context
 *
 * Every hop is logged via error_log so admins can correlate with provider
 * outages. Returns the same envelope shape as llm_chat_completions_with_fallback,
 * plus the served-by-config metadata.
 */
function llm_chat_completions_via_configs(mysqli $con, array $messages, array $tools, ?int $maxTokens = null): array
{
    $chain = ai_configs_enabled_chain($con);
    if (empty($chain)) {
        return [
            'ok' => false, 'status' => 0, 'error' => 'no_config_available',
            'body' => null, 'raw' => '',
            'provider' => '', 'model' => '',
            'served_by_provider' => '', 'served_by_model' => '',
            'served_by_config_id' => 0, 'served_by_config_nickname' => '',
            'served_by_key' => '',
            'fell_back_from' => [], 'chain_attempted' => [],
        ];
    }

    $attempted    = [];
    $fellBackFrom = [];
    $lastResp     = null;

    foreach ($chain as $row) {
        $configId    = (int)$row['id'];
        $nickname    = (string)$row['nickname'];
        $primaryRun  = ai_configs_to_runtime($row, 'primary');
        $secondaryRun = (!empty($row['api_key_secondary'])) ? ai_configs_to_runtime($row, 'secondary') : null;

        foreach (['primary' => $primaryRun, 'secondary' => $secondaryRun] as $whichKey => $cfg) {
            if ($cfg === null) continue;
            $attempted[] = ['config_id' => $configId, 'nickname' => $nickname, 'key' => $whichKey];

            $resp = ai_configs_chat_once($cfg, $messages, $tools, $maxTokens);
            if ($resp['ok']) {
                $resp['served_by_provider']         = $cfg['provider'];
                $resp['served_by_model']            = $cfg['model'];
                $resp['served_by_config_id']        = $configId;
                $resp['served_by_config_nickname']  = $nickname;
                $resp['served_by_key']              = $whichKey;
                $resp['fell_back_from']             = $fellBackFrom;
                $resp['chain_attempted']            = $attempted;
                return $resp;
            }

            $status = (int)$resp['status'];
            if (!llm_is_failover_status($status)) {
                $resp['served_by_provider']         = $cfg['provider'];
                $resp['served_by_model']            = $cfg['model'];
                $resp['served_by_config_id']        = $configId;
                $resp['served_by_config_nickname']  = $nickname;
                $resp['served_by_key']              = $whichKey;
                $resp['fell_back_from']             = $fellBackFrom;
                $resp['chain_attempted']            = $attempted;
                return $resp;
            }

            error_log(sprintf(
                'AI fallback: config=%s(id=%d) key=%s provider=%s status=%s error=%s',
                $nickname, $configId, $whichKey, $cfg['provider'],
                $status === 0 ? 'network' : (string)$status,
                $resp['error'] ?? ''
            ));
            $fellBackFrom[] = ['config_id' => $configId, 'nickname' => $nickname, 'key' => $whichKey, 'status' => $status];
            $lastResp = $resp;
        }
    }

    if ($lastResp === null) {
        $lastResp = ['ok' => false, 'status' => 0, 'error' => 'all_configs_failed',
                     'body' => null, 'raw' => '', 'provider' => '', 'model' => ''];
    }
    $lastResp['served_by_provider']         = '';
    $lastResp['served_by_model']            = '';
    $lastResp['served_by_config_id']        = 0;
    $lastResp['served_by_config_nickname']  = '';
    $lastResp['served_by_key']              = '';
    $lastResp['fell_back_from']             = $fellBackFrom;
    $lastResp['chain_attempted']            = $attempted;
    return $lastResp;
}

/**
 * Mirror of llm_test_chat_probe() that also merges custom_settings (header.*
 * and body extras) into the probe request. Test must match the real chat
 * path or admins see false negatives on configs whose auth lives in a
 * custom header (e.g. Azure APIM's Ocp-Apim-Subscription-Key).
 */
function ai_configs_run_test_chat_probe(array $cfg): array
{
    $maxTokens = llm_probe_max_tokens($cfg);
    $userText  = ($maxTokens > 1) ? "Reply with 'ok'." : 'hi';
    $messages  = [['role' => 'user', 'content' => $userText]];
    $req       = llm_build_chat_request($cfg, $messages, [], $maxTokens);

    $bodyArr = $req['body_arr'];
    $headers = $req['headers'];
    $split   = ai_configs_split_custom_settings($cfg['custom_settings'] ?? []);
    foreach ($split['body_extras'] as $k => $v) $bodyArr[$k] = $v;
    foreach ($split['headers']     as $h)       $headers[]   = $h;
    $bodyStr = json_encode($bodyArr, JSON_UNESCAPED_SLASHES);

    [$code, $body, $err] = llm_http_post($req['url'], $headers, $bodyStr);
    if ($code === 0) {
        return ['ok' => false, 'model_count' => null,
                'error' => 'Network error: ' . ($err ?: 'unknown'),
                'http_status' => 0];
    }
    if ($code >= 200 && $code < 300) {
        return ['ok' => true, 'model_count' => null, 'error' => null, 'http_status' => $code];
    }
    $msg = llm_extract_error_message($body, $code, (string)($cfg['provider'] ?? ''));
    return ['ok' => false, 'model_count' => null, 'error' => $msg, 'http_status' => $code];
}

/**
 * Per-config test connection. Tries primary first, then secondary on a
 * transient failure. Returns ['ok', 'http_status', 'error', 'used_key'].
 */
function ai_configs_test_connection(mysqli $con, int $configId): array
{
    $row = ai_configs_get($con, $configId);
    if (!$row) {
        return ['ok' => false, 'http_status' => 0, 'error' => 'Configuration not found.', 'used_key' => ''];
    }
    foreach (['primary', 'secondary'] as $whichKey) {
        $cfg = ai_configs_to_runtime($row, $whichKey);
        if ($cfg === null) continue;
        if (!empty($cfg['config_errors'])) {
            return ['ok' => false, 'http_status' => 0,
                    'error' => 'Misconfigured: ' . implode(', ', $cfg['config_errors']),
                    'used_key' => $whichKey];
        }

        $url     = (string)$cfg['test_url'];
        $headers = llm_build_auth_headers($cfg);
        $headers[] = 'Accept: application/json';
        $split = ai_configs_split_custom_settings($cfg['custom_settings'] ?? []);
        foreach ($split['headers'] as $h) $headers[] = $h;

        $useGet = in_array($cfg['provider'], ['groq', 'openai'], true)
               || ($cfg['provider'] === 'custom' && $cfg['preset'] === LLM_CUSTOM_PRESET_OPENAI_COMPATIBLE);

        if ($useGet) {
            [$code, $body, $err] = llm_http_get($url, $headers);
            if ($code === 0 && $whichKey === 'primary' && !empty($row['api_key_secondary'])) {
                continue;
            }
            if ($code !== 0 && $code !== 404) {
                $res = llm_summarize_test_response((string)$cfg['provider'], $code, $body, $err, true);
                $res['used_key'] = $whichKey;
                if (!$res['ok'] && llm_is_failover_status($code) && $whichKey === 'primary' && !empty($row['api_key_secondary'])) {
                    continue;
                }
                return $res;
            }
        }
        $probe = ai_configs_run_test_chat_probe($cfg);
        $probe['used_key'] = $whichKey;
        if (!$probe['ok'] && llm_is_failover_status((int)$probe['http_status']) && $whichKey === 'primary' && !empty($row['api_key_secondary'])) {
            continue;
        }
        return $probe;
    }
    return ['ok' => false, 'http_status' => 0, 'error' => 'No API key configured.', 'used_key' => ''];
}

/**
 * Token usage summary for the popup. Returns aggregate totals plus per-config
 * breakdown for the last 30 days plus per-day totals for the bar chart.
 */
function ai_configs_usage_summary(mysqli $con): array
{
    ai_configs_ensure_tables($con);
    $has = @$con->query("SHOW COLUMNS FROM ai_usage_log LIKE 'config_id'");
    $hasConfigCol = ($has && $has->num_rows > 0);
    if ($has) $has->close();

    $total = function (string $whereClause) use ($con): array {
        $sql = "SELECT COALESCE(SUM(prompt_tokens),0) AS pt, COALESCE(SUM(completion_tokens),0) AS ct, COUNT(*) AS n
                  FROM ai_usage_log WHERE $whereClause";
        $r = @$con->query($sql);
        if (!$r) return ['prompt' => 0, 'completion' => 0, 'total' => 0, 'requests' => 0];
        $row = $r->fetch_assoc();
        $r->close();
        $pt = (int)($row['pt'] ?? 0); $ct = (int)($row['ct'] ?? 0);
        return ['prompt' => $pt, 'completion' => $ct, 'total' => $pt + $ct, 'requests' => (int)($row['n'] ?? 0)];
    };

    $today     = $total("DATE(created_at) = UTC_DATE()");
    $yesterday = $total("DATE(created_at) = DATE_SUB(UTC_DATE(), INTERVAL 1 DAY)");
    $last7     = $total("created_at >= DATE_SUB(UTC_DATE(), INTERVAL 7 DAY)");
    $last30    = $total("created_at >= DATE_SUB(UTC_DATE(), INTERVAL 30 DAY)");

    $perConfig = [];
    if ($hasConfigCol) {
        $sql = "SELECT u.config_id, COALESCE(c.nickname, u.provider, '(unknown)') AS label,
                       COALESCE(SUM(u.prompt_tokens),0) AS pt,
                       COALESCE(SUM(u.completion_tokens),0) AS ct,
                       COUNT(*) AS n
                  FROM ai_usage_log u
                  LEFT JOIN ai_configs c ON c.id = u.config_id
                 WHERE u.created_at >= DATE_SUB(UTC_DATE(), INTERVAL 30 DAY)
                 GROUP BY u.config_id, label
                 ORDER BY (SUM(u.prompt_tokens) + SUM(u.completion_tokens)) DESC";
    } else {
        $sql = "SELECT NULL AS config_id, COALESCE(provider, '(unknown)') AS label,
                       COALESCE(SUM(prompt_tokens),0) AS pt,
                       COALESCE(SUM(completion_tokens),0) AS ct,
                       COUNT(*) AS n
                  FROM ai_usage_log
                 WHERE created_at >= DATE_SUB(UTC_DATE(), INTERVAL 30 DAY)
                 GROUP BY label
                 ORDER BY (SUM(prompt_tokens) + SUM(completion_tokens)) DESC";
    }
    $r = @$con->query($sql);
    if ($r) {
        while ($row = $r->fetch_assoc()) {
            $pt = (int)$row['pt']; $ct = (int)$row['ct'];
            $perConfig[] = [
                'config_id' => $row['config_id'] === null ? null : (int)$row['config_id'],
                'label'     => (string)$row['label'],
                'prompt'    => $pt, 'completion' => $ct, 'total' => $pt + $ct,
                'requests'  => (int)$row['n'],
            ];
        }
        $r->close();
    }

    $perDay = [];
    $sql = "SELECT DATE(created_at) AS d,
                   COALESCE(SUM(prompt_tokens),0) AS pt,
                   COALESCE(SUM(completion_tokens),0) AS ct
              FROM ai_usage_log
             WHERE created_at >= DATE_SUB(UTC_DATE(), INTERVAL 30 DAY)
             GROUP BY DATE(created_at)
             ORDER BY d ASC";
    $r = @$con->query($sql);
    $byDay = [];
    if ($r) {
        while ($row = $r->fetch_assoc()) {
            $byDay[(string)$row['d']] = (int)$row['pt'] + (int)$row['ct'];
        }
        $r->close();
    }
    for ($i = 29; $i >= 0; $i--) {
        $d = gmdate('Y-m-d', strtotime('-' . $i . ' days'));
        $perDay[] = ['date' => $d, 'total' => $byDay[$d] ?? 0];
    }

    return [
        'today'      => $today,
        'yesterday'  => $yesterday,
        'last7days'  => $last7,
        'last30days' => $last30,
        'per_config' => $perConfig,
        'per_day'    => $perDay,
    ];
}

/**
 * One-time data migration from the legacy single-slot per-provider keys.
 * Called when the admin lands on the AI Configuration page and the
 * ai_configs table is empty. Reads ai_settings.groq_api_key /
 * openai_api_key / custom_* and the legacy llm_provider_chain ordering,
 * then creates one ai_configs row per provider that has a key.
 *
 * Returns the number of configs created.
 */
function ai_configs_migrate_from_legacy(mysqli $con, int $userId): int
{
    ai_configs_ensure_tables($con);

    // One-time migration. The flag is set after the first attempt (regardless
    // of whether it found legacy keys) so deleting every config later does
    // not cause this helper to resurrect the legacy entries from ai_settings
    // on the next page load.
    try {
        if (ai_settings_get('ai_configs_migrated_from_legacy') === '1') return 0;
    } catch (Throwable $e) {
        return 0;
    }
    if (ai_configs_count($con) > 0) {
        try { ai_settings_set('ai_configs_migrated_from_legacy', '1', $userId); } catch (Throwable $e) {}
        return 0;
    }

    $chain = llm_get_provider_chain_raw();
    $created = 0;

    foreach ($chain as $entry) {
        $prov = (string)$entry['provider'];
        try {
            $cfg = llm_get_provider_config($prov);
        } catch (Throwable $e) {
            continue;
        }
        if (($cfg['api_key'] ?? '') === '') continue;

        $data = [
            'nickname' => ucfirst($prov) . ' (legacy)',
            'provider' => $prov,
            'model'    => $cfg['model'] ?? '',
            'preset'   => $cfg['preset'] ?? '',
            'base_url' => $prov === 'custom' ? (string)($cfg['base_url'] ?? '') : '',
            'enabled'  => !empty($entry['enabled']),
            'api_key_primary' => (string)$cfg['api_key'],
            'is_default' => $created === 0 ? 1 : 0,
        ];
        if ($prov === 'custom') {
            $extras = [];
            try {
                $apiVer = ai_settings_get('custom_api_version');
                if ($apiVer) $extras[] = ['key' => 'api_version', 'value' => $apiVer];
            } catch (Throwable $e) {}
            try {
                $tf = ai_settings_get('custom_token_field');
                if ($tf) $extras[] = ['key' => 'token_field', 'value' => $tf];
            } catch (Throwable $e) {}
            $data['custom_settings'] = $extras;
        }
        try {
            ai_configs_save($con, null, $data, $userId);
            $created++;
        } catch (Throwable $e) {
            error_log('ai_configs migration: ' . $e->getMessage());
        }
    }
    if ($created > 0) {
        log_activity($con, 'ai_config_migrate_legacy', 'ai_config', null, "created=$created");
    }
    try { ai_settings_set('ai_configs_migrated_from_legacy', '1', $userId); } catch (Throwable $e) {}
    return $created;
}
