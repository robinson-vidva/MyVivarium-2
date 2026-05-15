<?php
/**
 * LLM provider abstraction.
 *
 * Three providers are supported:
 *
 *   - groq           — OpenAI-compatible chat completions on api.groq.com
 *   - openai         — OpenAI-compatible chat completions on api.openai.com
 *   - custom         — admin-configured third-party endpoint. The custom
 *                      provider runs in one of three presets:
 *                        * azure_openai        — Azure OpenAI / APIM gateway
 *                                                that exposes Azure's
 *                                                deployment-based URL shape.
 *                        * azure_anthropic     — Anthropic Messages format
 *                                                tunneled through Azure APIM.
 *                                                Request/response shape is
 *                                                translated to/from the
 *                                                OpenAI shape internally so
 *                                                ai_chat.php never sees the
 *                                                difference.
 *                        * openai_compatible   — any provider whose API
 *                                                matches OpenAI chat
 *                                                completions (OpenRouter,
 *                                                DeepSeek, vLLM, …).
 *
 * The chatbot never picks a provider directly — it always calls
 * llm_chat_completions() and lets this file decide which backend to hit and
 * which format to speak. Translation (when needed) happens entirely inside
 * llm_chat_completions(); ai_chat.php always feeds OpenAI-shape messages in
 * and always gets OpenAI-shape responses out.
 *
 * The API key only lives in the decrypted variable returned by
 * llm_get_active_config() and is never logged or persisted in plaintext.
 */

// Tests can stub ai_settings_get() before including this file to avoid
// pulling in dbcon.php / a live mysqli connection. In production, all real
// callers (ai_chat.php, manage_ai_config.php, ai_test_connection.php,
// chatbot_widget.php) require_once ai_settings.php themselves.
if (!function_exists('ai_settings_get')) {
    require_once __DIR__ . '/ai_settings.php';
}

class LlmProviderException extends RuntimeException {}

const LLM_PROVIDER_GROQ   = 'groq';
const LLM_PROVIDER_OPENAI = 'openai';
const LLM_PROVIDER_CUSTOM = 'custom';

const LLM_GROQ_ALLOWED_MODELS = [
    'llama-3.3-70b-versatile',
    'llama-3.1-8b-instant',
    'openai/gpt-oss-120b',
    'openai/gpt-oss-20b',
];

const LLM_OPENAI_ALLOWED_MODELS = [
    'gpt-5.4-mini',
    'gpt-5.4-nano',
    'gpt-5.4',
    'gpt-4.1-nano',
    'gpt-4.1-mini',
];

const LLM_GROQ_DEFAULT_MODEL   = 'llama-3.3-70b-versatile';
const LLM_OPENAI_DEFAULT_MODEL = 'gpt-5.4-mini';

const LLM_CUSTOM_PRESET_AZURE_OPENAI      = 'azure_openai';
const LLM_CUSTOM_PRESET_AZURE_ANTHROPIC   = 'azure_anthropic';
const LLM_CUSTOM_PRESET_OPENAI_COMPATIBLE = 'openai_compatible';

const LLM_CUSTOM_ALLOWED_PRESETS = [
    LLM_CUSTOM_PRESET_AZURE_OPENAI,
    LLM_CUSTOM_PRESET_AZURE_ANTHROPIC,
    LLM_CUSTOM_PRESET_OPENAI_COMPATIBLE,
];

const LLM_CUSTOM_AZURE_ANTHROPIC_MODELS = [
    'claude-opus-4-5',
    'claude-sonnet-4-6',
    'claude-haiku-4-5',
];

const LLM_CUSTOM_DEFAULT_AZURE_API_VERSION = '2024-10-21';
const LLM_CUSTOM_ANTHROPIC_VERSION         = '2023-06-01';
const LLM_CUSTOM_ANTHROPIC_DEFAULT_MAX_TOKENS = 1024;

const LLM_PROVIDER_ALL = [LLM_PROVIDER_GROQ, LLM_PROVIDER_OPENAI, LLM_PROVIDER_CUSTOM];

/**
 * Read the raw provider chain stored in ai_settings.llm_provider_chain.
 * If the new key is absent, fall back to the legacy single-provider
 * llm_provider key: build a chain with the legacy provider at priority 1
 * (enabled) and the other two appended (disabled). Returns an ordered list
 * of ['provider', 'enabled', 'priority'] entries.
 *
 * Never persists — callers that want to migrate the storage should call
 * llm_save_provider_chain() once they have a user_id (typically on the
 * first admin save).
 */
function llm_get_provider_chain_raw(): array
{
    $raw = null;
    try {
        $raw = ai_settings_get('llm_provider_chain');
    } catch (Throwable $e) {
        $raw = null;
    }
    if (is_string($raw) && $raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return llm_normalize_chain($decoded);
        }
    }
    return llm_chain_from_legacy();
}

/**
 * Normalize a stored or submitted chain: ensures each known provider is
 * represented exactly once, sorts by priority ascending, and renumbers
 * priorities 1..N. Unknown providers are dropped.
 */
function llm_normalize_chain(array $chain): array
{
    $seen = [];
    foreach ($chain as $entry) {
        if (!is_array($entry)) continue;
        $p = (string)($entry['provider'] ?? '');
        if (!in_array($p, LLM_PROVIDER_ALL, true)) continue;
        if (isset($seen[$p])) continue;
        $seen[$p] = [
            'provider' => $p,
            'enabled'  => !empty($entry['enabled']),
            'priority' => (int)($entry['priority'] ?? 99),
        ];
    }
    // Append any missing providers at the end, disabled.
    $maxPriority = 0;
    foreach ($seen as $e) {
        if ($e['priority'] > $maxPriority) $maxPriority = $e['priority'];
    }
    foreach (LLM_PROVIDER_ALL as $p) {
        if (!isset($seen[$p])) {
            $maxPriority++;
            $seen[$p] = ['provider' => $p, 'enabled' => false, 'priority' => $maxPriority];
        }
    }
    $list = array_values($seen);
    usort($list, function ($a, $b) {
        if ($a['priority'] === $b['priority']) {
            return array_search($a['provider'], LLM_PROVIDER_ALL, true)
                 - array_search($b['provider'], LLM_PROVIDER_ALL, true);
        }
        return $a['priority'] - $b['priority'];
    });
    // Renumber.
    foreach ($list as $i => &$e) {
        $e['priority'] = $i + 1;
    }
    unset($e);
    return $list;
}

/**
 * Build a chain from the legacy llm_provider key for backward compatibility.
 * If the legacy value is one of the known providers, that provider goes to
 * priority 1 enabled and the others follow at priority 2-3 disabled. If the
 * legacy value is absent or unknown (fresh install), all three providers
 * land at priority 1-3 with enabled=false — the admin must enable at least
 * one before the chatbot will accept any traffic.
 */
function llm_chain_from_legacy(): array
{
    $legacy = '';
    try {
        $legacy = (string)(ai_settings_get('llm_provider') ?? '');
    } catch (Throwable $e) {
        $legacy = '';
    }
    if (!in_array($legacy, LLM_PROVIDER_ALL, true)) {
        // Fresh install — no chain, no legacy. All disabled.
        $chain = [];
        $p = 1;
        foreach (LLM_PROVIDER_ALL as $prov) {
            $chain[] = ['provider' => $prov, 'enabled' => false, 'priority' => $p++];
        }
        return $chain;
    }
    $chain = [['provider' => $legacy, 'enabled' => true, 'priority' => 1]];
    $p = 2;
    foreach (LLM_PROVIDER_ALL as $prov) {
        if ($prov === $legacy) continue;
        $chain[] = ['provider' => $prov, 'enabled' => false, 'priority' => $p++];
    }
    return $chain;
}

/**
 * Persist a chain to ai_settings.llm_provider_chain (encrypted) and remove
 * the legacy llm_provider key. Used by manage_ai_config.php at save time.
 */
function llm_save_provider_chain(array $chain, int $userId): void
{
    if (!function_exists('ai_settings_set')) return;
    $normalized = llm_normalize_chain($chain);
    ai_settings_set('llm_provider_chain', json_encode($normalized, JSON_UNESCAPED_SLASHES), $userId);
}

/**
 * Returns the ordered list of providers that are enabled in the chain AND
 * fully configured (key present and, for custom, no config_errors). Each
 * entry is ['provider' => string, 'config' => array]. Disabled or
 * misconfigured entries are skipped silently. Highest priority first.
 */
function llm_get_provider_chain(): array
{
    $raw = llm_get_provider_chain_raw();
    $resolved = [];
    foreach ($raw as $entry) {
        if (empty($entry['enabled'])) continue;
        $prov = (string)$entry['provider'];
        try {
            $cfg = llm_get_provider_config($prov);
        } catch (Throwable $e) {
            continue;
        }
        if (($cfg['api_key'] ?? '') === '') continue;
        if (($cfg['provider'] ?? '') === LLM_PROVIDER_CUSTOM && !empty($cfg['config_errors'])) continue;
        $resolved[] = ['provider' => $prov, 'config' => $cfg];
    }
    return $resolved;
}

/**
 * Returns the active provider id — defined as the highest-priority
 * enabled-and-configured provider in the chain. Falls back to the legacy
 * llm_provider key (or 'groq') when no chain entry qualifies, so existing
 * code paths and unit tests that only set 'llm_provider' keep working.
 */
function llm_get_active_provider(): string
{
    $chain = llm_get_provider_chain();
    if (!empty($chain)) {
        return $chain[0]['provider'];
    }
    // Legacy fallback for backward compatibility with the old single-provider
    // setup and the existing unit tests that just set llm_provider.
    try {
        $p = ai_settings_get('llm_provider');
    } catch (Throwable $e) {
        return LLM_PROVIDER_GROQ;
    }
    if ($p === LLM_PROVIDER_OPENAI) return LLM_PROVIDER_OPENAI;
    if ($p === LLM_PROVIDER_CUSTOM) return LLM_PROVIDER_CUSTOM;
    return LLM_PROVIDER_GROQ;
}

/**
 * Returns the resolved config for the active provider. Shape for groq /
 * openai:
 *   ['provider', 'api_key', 'model', 'base_url', 'allowed_models']
 *
 * Custom provider returns the same five keys plus:
 *   ['preset', 'preset_label', 'request_url', 'test_url', 'auth_header',
 *    'auth_prefix', 'extra_headers', 'body_format', 'token_field',
 *    'include_model_in_body', 'config_errors']
 *
 * api_key may be '' (empty string) when no key has been configured yet —
 * callers should check for that and surface a "not configured" error rather
 * than attempting the HTTP call. This function never throws on a missing
 * key; it returns the empty-key state so admin UIs can still render.
 */
function llm_get_active_config(): array
{
    $provider = llm_get_active_provider();
    return llm_get_provider_config($provider);
}

/**
 * Same as llm_get_active_config() but for an arbitrary provider id. Useful
 * for the Test Connection endpoint which targets a specific provider.
 */
function llm_get_provider_config(string $provider): array
{
    if ($provider === LLM_PROVIDER_CUSTOM) {
        return llm_get_custom_config();
    }
    if ($provider !== LLM_PROVIDER_GROQ && $provider !== LLM_PROVIDER_OPENAI) {
        throw new LlmProviderException("Unknown provider '$provider'.");
    }
    if ($provider === LLM_PROVIDER_OPENAI) {
        $keyRaw   = '';
        $modelRaw = '';
        try { $keyRaw   = (string)(ai_settings_get('openai_api_key') ?? ''); } catch (Throwable $e) {}
        try { $modelRaw = (string)(ai_settings_get('openai_model')   ?? ''); } catch (Throwable $e) {}
        $model = $modelRaw !== '' ? $modelRaw : LLM_OPENAI_DEFAULT_MODEL;
        return [
            'provider'       => LLM_PROVIDER_OPENAI,
            'api_key'        => $keyRaw,
            'model'          => $model,
            'base_url'       => 'https://api.openai.com/v1',
            'allowed_models' => LLM_OPENAI_ALLOWED_MODELS,
        ];
    }
    // groq
    $keyRaw   = '';
    $modelRaw = '';
    try { $keyRaw   = (string)(ai_settings_get('groq_api_key') ?? ''); } catch (Throwable $e) {}
    try { $modelRaw = (string)(ai_settings_get('groq_model')   ?? ''); } catch (Throwable $e) {}
    $model = $modelRaw !== '' ? $modelRaw : LLM_GROQ_DEFAULT_MODEL;
    return [
        'provider'       => LLM_PROVIDER_GROQ,
        'api_key'        => $keyRaw,
        'model'          => $model,
        'base_url'       => 'https://api.groq.com/openai/v1',
        'allowed_models' => LLM_GROQ_ALLOWED_MODELS,
    ];
}

/**
 * Reads the stored custom-provider configuration and resolves it down to a
 * fully built request URL + header set + body format hint. Per-preset
 * required fields are validated; missing ones land in 'config_errors' as
 * human-readable strings and api_key is left at '' so callers report
 * not-configured rather than attempt an HTTP call.
 */
function llm_get_custom_config(): array
{
    $preset = '';
    $resourceUrl = '';
    $deployment  = '';
    $apiVersion  = '';
    $baseUrl     = '';
    $model       = '';
    $apiKey      = '';
    $tokenField  = '';

    try { $preset      = (string)(ai_settings_get('custom_preset')       ?? ''); } catch (Throwable $e) {}
    try { $resourceUrl = (string)(ai_settings_get('custom_resource_url') ?? ''); } catch (Throwable $e) {}
    try { $deployment  = (string)(ai_settings_get('custom_deployment')   ?? ''); } catch (Throwable $e) {}
    try { $apiVersion  = (string)(ai_settings_get('custom_api_version')  ?? ''); } catch (Throwable $e) {}
    try { $baseUrl     = (string)(ai_settings_get('custom_base_url')     ?? ''); } catch (Throwable $e) {}
    try { $model       = (string)(ai_settings_get('custom_model')        ?? ''); } catch (Throwable $e) {}
    try { $apiKey      = (string)(ai_settings_get('custom_api_key')      ?? ''); } catch (Throwable $e) {}
    try { $tokenField  = (string)(ai_settings_get('custom_token_field')  ?? ''); } catch (Throwable $e) {}

    $errors = [];

    $cfg = [
        'provider'              => LLM_PROVIDER_CUSTOM,
        'preset'                => $preset,
        'preset_label'          => llm_custom_preset_label($preset),
        'api_key'               => $apiKey,
        'model'                 => $model,
        'base_url'              => '',
        'request_url'           => '',
        'test_url'              => '',
        'auth_header'           => 'Authorization',
        'auth_prefix'           => 'Bearer ',
        'extra_headers'         => [],
        'body_format'           => 'openai_chat',
        'token_field'           => 'max_tokens',
        'include_model_in_body' => true,
        'allowed_models'        => [],
        'config_errors'         => [],
    ];

    if ($preset === '') {
        $errors[] = 'preset';
        $cfg['config_errors'] = $errors;
        $cfg['api_key'] = '';
        return $cfg;
    }

    if ($preset === LLM_CUSTOM_PRESET_AZURE_OPENAI) {
        if ($resourceUrl === '') $errors[] = 'Resource URL';
        if ($deployment  === '') $errors[] = 'Deployment name';
        $ver = $apiVersion !== '' ? $apiVersion : LLM_CUSTOM_DEFAULT_AZURE_API_VERSION;
        $resBase = rtrim($resourceUrl, '/');
        $cfg['base_url']    = $resBase;
        $cfg['request_url'] = $resBase . '/openai/deployments/' . rawurlencode($deployment)
                            . '/chat/completions?api-version=' . rawurlencode($ver);
        $cfg['test_url']    = $resBase . '/openai/deployments/' . rawurlencode($deployment)
                            . '/chat/completions?api-version=' . rawurlencode($ver);
        $cfg['auth_header'] = 'api-key';
        $cfg['auth_prefix'] = '';
        $cfg['body_format'] = 'openai_chat';
        $cfg['include_model_in_body'] = false;
        $cfg['model']       = $deployment !== '' ? $deployment : $model;
        $depLower = strtolower($deployment);
        $useCompletionTokens = (
            strpos($depLower, 'gpt-5') === 0 ||
            strpos($depLower, 'o1')    === 0 ||
            strpos($depLower, 'o3')    === 0
        );
        $cfg['token_field'] = $useCompletionTokens ? 'max_completion_tokens' : 'max_tokens';
    } elseif ($preset === LLM_CUSTOM_PRESET_AZURE_ANTHROPIC) {
        if ($baseUrl === '') $errors[] = 'Base URL';
        if ($model   === '') $errors[] = 'Model';
        $base = rtrim($baseUrl, '/');
        $cfg['base_url']    = $base;
        $cfg['request_url'] = $base . '/anthropic/v1/messages';
        $cfg['test_url']    = $base . '/anthropic/v1/messages';
        $cfg['auth_header'] = 'x-api-key';
        $cfg['auth_prefix'] = '';
        $cfg['extra_headers'] = ['anthropic-version: ' . LLM_CUSTOM_ANTHROPIC_VERSION];
        $cfg['body_format']   = 'anthropic_messages';
        $cfg['include_model_in_body'] = true;
        $cfg['token_field']   = 'max_tokens';
    } elseif ($preset === LLM_CUSTOM_PRESET_OPENAI_COMPATIBLE) {
        if ($baseUrl === '') $errors[] = 'Base URL';
        if ($model   === '') $errors[] = 'Model';
        $base = rtrim($baseUrl, '/');
        $cfg['base_url']    = $base;
        $cfg['request_url'] = $base . '/chat/completions';
        $cfg['test_url']    = $base . '/models';
        $cfg['auth_header'] = 'Authorization';
        $cfg['auth_prefix'] = 'Bearer ';
        $cfg['body_format'] = 'openai_chat';
        $cfg['include_model_in_body'] = true;
        $cfg['token_field'] = ($tokenField === 'max_completion_tokens') ? 'max_completion_tokens' : 'max_tokens';
    } else {
        $errors[] = 'valid preset';
    }

    if ($apiKey === '') $errors[] = 'API key';

    $cfg['config_errors'] = $errors;
    return $cfg;
}

/**
 * Display label, e.g. "OpenAI" / "Groq" / "Custom".
 */
function llm_provider_label(string $provider): string
{
    if ($provider === LLM_PROVIDER_OPENAI) return 'OpenAI';
    if ($provider === LLM_PROVIDER_CUSTOM) return 'Custom';
    return 'Groq';
}

/**
 * Human-friendly label for a custom preset id.
 */
function llm_custom_preset_label(string $preset): string
{
    switch ($preset) {
        case LLM_CUSTOM_PRESET_AZURE_OPENAI:      return 'Azure OpenAI';
        case LLM_CUSTOM_PRESET_AZURE_ANTHROPIC:   return 'Azure Anthropic';
        case LLM_CUSTOM_PRESET_OPENAI_COMPATIBLE: return 'OpenAI-compatible';
    }
    return '';
}

/**
 * Render the "Active: ..." banner string for the AI Configuration page.
 * Pure: takes a config dict, returns a string. No side effects.
 */
function llm_active_provider_banner(array $cfg): string
{
    $provider = (string)($cfg['provider'] ?? '');
    $model    = (string)($cfg['model']    ?? '');
    if ($provider === LLM_PROVIDER_GROQ) {
        return 'Active: Groq (' . $model . ')';
    }
    if ($provider === LLM_PROVIDER_OPENAI) {
        return 'Active: OpenAI (' . $model . ')';
    }
    if ($provider === LLM_PROVIDER_CUSTOM) {
        $label = (string)($cfg['preset_label'] ?? llm_custom_preset_label((string)($cfg['preset'] ?? '')));
        if ($label === '') $label = 'unconfigured';
        return 'Active: Custom — ' . $label . ($model !== '' ? ' (' . $model . ')' : '');
    }
    return 'Active: ' . $provider;
}

/**
 * Decide whether to omit the temperature field from an OpenAI-shape request
 * body. Azure's GPT-5 family and the reasoning models (o1, o3) reject any
 * temperature value other than the default 1 with an "Unsupported value"
 * error, so MyVivarium omits the field entirely for those deployments /
 * model ids and lets the provider apply its default. The detection rule is
 * a case-insensitive prefix match — same as the one already used for
 * picking max_completion_tokens vs max_tokens.
 *
 * Pure helper, unit-tested in tests/llm_provider_custom_test.php.
 */
function llm_model_omits_temperature(string $modelOrDeployment): bool
{
    $name = strtolower($modelOrDeployment);
    return (
        strpos($name, 'gpt-5') === 0 ||
        strpos($name, 'o1')    === 0 ||
        strpos($name, 'o3')    === 0
    );
}

/**
 * Build the OpenAI-shape request body for groq / openai / custom-openai_chat
 * presets. Pure: no I/O.
 */
function llm_build_openai_chat_payload(array $cfg, array $messages, array $tools, ?int $max_tokens): array
{
    $payload = [
        'messages' => $messages,
    ];
    $modelForName = (string)($cfg['model'] ?? '');
    if (!llm_model_omits_temperature($modelForName)) {
        $payload['temperature'] = 0.2;
    }
    $includeModel = $cfg['include_model_in_body'] ?? true;
    if ($includeModel) {
        $payload['model'] = $cfg['model'];
    }
    if (!empty($tools)) {
        $payload['tools']       = $tools;
        $payload['tool_choice'] = 'auto';
    }
    if ($max_tokens !== null && $max_tokens > 0) {
        $tokField = (string)($cfg['token_field'] ?? 'max_tokens');
        $payload[$tokField] = $max_tokens;
    }
    return $payload;
}

/**
 * Build the Anthropic Messages request body. Pure: no I/O.
 */
function llm_build_anthropic_payload(array $cfg, array $messages, array $tools, ?int $max_tokens): array
{
    [$systemPrompt, $oaMessages] = llm_extract_system_prompt($messages);
    $translated = llm_translate_messages_to_anthropic($oaMessages, $systemPrompt);
    $payload = [
        'model'      => (string)($cfg['model'] ?? ''),
        'messages'   => $translated['messages'],
        'max_tokens' => ($max_tokens !== null && $max_tokens > 0) ? $max_tokens : LLM_CUSTOM_ANTHROPIC_DEFAULT_MAX_TOKENS,
    ];
    if ($translated['system'] !== null && $translated['system'] !== '') {
        $payload['system'] = $translated['system'];
    }
    if (!empty($tools)) {
        $payload['tools'] = llm_translate_tools_to_anthropic($tools);
    }
    return $payload;
}

/**
 * Pull the first system message (if any) out of an OpenAI-shape messages
 * array. Returns [systemPromptStringOrNull, remainingMessagesWithoutSystem].
 */
function llm_extract_system_prompt(array $messages): array
{
    $system = null;
    $rest   = [];
    foreach ($messages as $m) {
        $role = $m['role'] ?? '';
        if ($role === 'system' && $system === null) {
            $c = $m['content'] ?? '';
            $system = is_string($c) ? $c : json_encode($c);
        } else {
            $rest[] = $m;
        }
    }
    return [$system, $rest];
}

/**
 * Translate OpenAI-shape messages to Anthropic Messages format.
 *
 *   - role 'system'        → top-level $systemPrompt (first one only); the
 *                            caller usually does this via
 *                            llm_extract_system_prompt() first.
 *   - role 'user'          → {role:'user', content:[{type:'text', text:…}]}
 *   - role 'assistant'     → {role:'assistant', content:[blocks…]}
 *                            with optional text + tool_use blocks
 *   - role 'tool'          → {role:'user', content:[{type:'tool_result',
 *                            tool_use_id, content}]}
 *
 * Returns ['system' => ?string, 'messages' => array].
 */
function llm_translate_messages_to_anthropic(array $openaiMessages, ?string $systemPrompt): array
{
    $out = [];
    $sys = $systemPrompt;
    foreach ($openaiMessages as $m) {
        $role = $m['role'] ?? '';
        if ($role === 'system') {
            if ($sys === null || $sys === '') {
                $c = $m['content'] ?? '';
                $sys = is_string($c) ? $c : json_encode($c);
            }
            continue;
        }
        if ($role === 'user') {
            $c = $m['content'] ?? '';
            $text = is_string($c) ? $c : json_encode($c);
            $out[] = ['role' => 'user', 'content' => [['type' => 'text', 'text' => $text]]];
            continue;
        }
        if ($role === 'assistant') {
            $blocks = [];
            $content = $m['content'] ?? '';
            if (is_string($content) && $content !== '') {
                $blocks[] = ['type' => 'text', 'text' => $content];
            }
            $toolCalls = $m['tool_calls'] ?? [];
            if (is_array($toolCalls)) {
                foreach ($toolCalls as $tc) {
                    $id   = (string)($tc['id'] ?? '');
                    $name = (string)($tc['function']['name'] ?? '');
                    $args = $tc['function']['arguments'] ?? '';
                    $input = [];
                    if (is_string($args)) {
                        $decoded = json_decode($args, true);
                        $input = is_array($decoded) ? $decoded : [];
                    } elseif (is_array($args)) {
                        $input = $args;
                    }
                    $blocks[] = [
                        'type'  => 'tool_use',
                        'id'    => $id,
                        'name'  => $name,
                        'input' => (object)$input,
                    ];
                }
            }
            if (empty($blocks)) {
                $blocks[] = ['type' => 'text', 'text' => ''];
            }
            $out[] = ['role' => 'assistant', 'content' => $blocks];
            continue;
        }
        if ($role === 'tool') {
            $toolCallId = (string)($m['tool_call_id'] ?? '');
            $c = $m['content'] ?? '';
            $text = is_string($c) ? $c : json_encode($c);
            $out[] = [
                'role'    => 'user',
                'content' => [[
                    'type'         => 'tool_result',
                    'tool_use_id'  => $toolCallId,
                    'content'      => $text,
                ]],
            ];
            continue;
        }
    }
    return ['system' => $sys, 'messages' => $out];
}

/**
 * Translate OpenAI tool definitions to Anthropic tools schema.
 *   {type:'function', function:{name, description, parameters}}
 *      → {name, description, input_schema}
 */
function llm_translate_tools_to_anthropic(array $openaiTools): array
{
    $out = [];
    foreach ($openaiTools as $t) {
        $fn = $t['function'] ?? null;
        if (!is_array($fn)) continue;
        $name = (string)($fn['name'] ?? '');
        if ($name === '') continue;
        $desc = (string)($fn['description'] ?? '');
        $schema = $fn['parameters'] ?? ['type' => 'object', 'properties' => (object)[]];
        // Anthropic requires input_schema with type=object even if no
        // properties; ensure that minimum shape.
        if (!is_array($schema)) $schema = ['type' => 'object', 'properties' => (object)[]];
        if (!isset($schema['type'])) $schema['type'] = 'object';
        if (!isset($schema['properties'])) $schema['properties'] = (object)[];
        $out[] = [
            'name'        => $name,
            'description' => $desc,
            'input_schema' => $schema,
        ];
    }
    return $out;
}

/**
 * Translate an Anthropic Messages response into the OpenAI chat completions
 * shape that the rest of the chatbot expects.
 *
 * Input  (Anthropic): {id, model, content:[{type:'text',text}, {type:'tool_use',id,name,input}], stop_reason, usage:{input_tokens,output_tokens}}
 * Output (OpenAI):    {id, model, choices:[{message:{role:'assistant', content, tool_calls?}, finish_reason}], usage:{prompt_tokens, completion_tokens}}
 */
function llm_translate_anthropic_response(array $anthropicResponse): array
{
    $textParts = [];
    $toolCalls = [];
    $content   = $anthropicResponse['content'] ?? [];
    if (is_array($content)) {
        foreach ($content as $block) {
            $type = $block['type'] ?? '';
            if ($type === 'text') {
                $textParts[] = (string)($block['text'] ?? '');
            } elseif ($type === 'tool_use') {
                $args = $block['input'] ?? [];
                if (!is_array($args) && !is_object($args)) $args = [];
                $argsJson = json_encode($args, JSON_UNESCAPED_SLASHES);
                if ($argsJson === false || $argsJson === 'null') $argsJson = '{}';
                $toolCalls[] = [
                    'id'       => (string)($block['id'] ?? ''),
                    'type'     => 'function',
                    'function' => [
                        'name'      => (string)($block['name'] ?? ''),
                        'arguments' => $argsJson,
                    ],
                ];
            }
        }
    }
    $finish = 'stop';
    $stop   = $anthropicResponse['stop_reason'] ?? '';
    if ($stop === 'tool_use')   $finish = 'tool_calls';
    elseif ($stop === 'max_tokens') $finish = 'length';

    $message = [
        'role'    => 'assistant',
        'content' => implode('', $textParts),
    ];
    if (!empty($toolCalls)) {
        $message['tool_calls'] = $toolCalls;
    }

    $usage = $anthropicResponse['usage'] ?? [];
    $promptT = (int)($usage['input_tokens']  ?? 0);
    $compT   = (int)($usage['output_tokens'] ?? 0);

    return [
        'id'      => (string)($anthropicResponse['id'] ?? ''),
        'model'   => (string)($anthropicResponse['model'] ?? ''),
        'choices' => [[
            'index'         => 0,
            'message'       => $message,
            'finish_reason' => $finish,
        ]],
        'usage'   => [
            'prompt_tokens'     => $promptT,
            'completion_tokens' => $compT,
            'total_tokens'      => $promptT + $compT,
        ],
    ];
}

/**
 * Internal helper that performs the HTTP POST for one chat-completions
 * request. Split out for testability — tests can override $__llm_http_post
 * via the LLM_HTTP_POST_HOOK global to skip cURL entirely.
 *
 * Returns [statusCode, rawBody, curlError]. statusCode === 0 with non-empty
 * curlError indicates a network-layer failure (curl_exec returned false).
 */
function llm_http_post(string $url, array $headers, string $body): array
{
    if (isset($GLOBALS['LLM_HTTP_POST_HOOK']) && is_callable($GLOBALS['LLM_HTTP_POST_HOOK'])) {
        return ($GLOBALS['LLM_HTTP_POST_HOOK'])($url, $headers, $body);
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_TIMEOUT        => 30,
    ]);
    $raw = curl_exec($ch);
    if ($raw === false) {
        $err = curl_error($ch);
        curl_close($ch);
        return [0, '', $err];
    }
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$status, (string)$raw, ''];
}

function llm_http_get(string $url, array $headers): array
{
    if (isset($GLOBALS['LLM_HTTP_GET_HOOK']) && is_callable($GLOBALS['LLM_HTTP_GET_HOOK'])) {
        return ($GLOBALS['LLM_HTTP_GET_HOOK'])($url, $headers);
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    $raw = curl_exec($ch);
    if ($raw === false) {
        $err = curl_error($ch);
        curl_close($ch);
        return [0, '', $err];
    }
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$status, (string)$raw, ''];
}

/**
 * Build the request URL, headers and body for one chat completion turn.
 * Used by llm_chat_completions() and exposed for tests so they can assert
 * that each preset constructs the request correctly without doing real I/O.
 *
 * Returns ['url', 'headers', 'body_str', 'body_arr'].
 */
function llm_build_chat_request(array $cfg, array $messages, array $tools, ?int $max_tokens): array
{
    $provider = (string)($cfg['provider'] ?? '');
    if ($provider === LLM_PROVIDER_CUSTOM) {
        $url     = (string)($cfg['request_url'] ?? '');
        $headers = llm_build_auth_headers($cfg);
        $headers[] = 'Content-Type: application/json';
        $headers[] = 'Accept: application/json';
        if ($cfg['body_format'] === 'anthropic_messages') {
            $body = llm_build_anthropic_payload($cfg, $messages, $tools, $max_tokens);
        } else {
            $body = llm_build_openai_chat_payload($cfg, $messages, $tools, $max_tokens);
        }
    } else {
        $url     = rtrim((string)$cfg['base_url'], '/') . '/chat/completions';
        $headers = [
            'Authorization: Bearer ' . $cfg['api_key'],
            'Content-Type: application/json',
        ];
        // groq/openai always speak OpenAI chat completions with model in body
        // and the standard max_tokens field.
        $body = [
            'model'    => $cfg['model'],
            'messages' => $messages,
        ];
        if (!llm_model_omits_temperature((string)($cfg['model'] ?? ''))) {
            $body['temperature'] = 0.2;
        }
        if (!empty($tools)) {
            $body['tools']       = $tools;
            $body['tool_choice'] = 'auto';
        }
        if ($max_tokens !== null && $max_tokens > 0) {
            $body['max_tokens'] = $max_tokens;
        }
    }
    $bodyStr = json_encode($body, JSON_UNESCAPED_SLASHES);
    return [
        'url'      => $url,
        'headers'  => $headers,
        'body_str' => $bodyStr,
        'body_arr' => $body,
    ];
}

/**
 * Build the auth + extra headers for a custom-provider request. The Bearer
 * prefix and header name vary by preset; this collapses that into a single
 * place.
 */
function llm_build_auth_headers(array $cfg): array
{
    $authHeader = (string)($cfg['auth_header'] ?? 'Authorization');
    $prefix     = (string)($cfg['auth_prefix'] ?? '');
    $headers    = [$authHeader . ': ' . $prefix . (string)($cfg['api_key'] ?? '')];
    foreach (($cfg['extra_headers'] ?? []) as $h) {
        $headers[] = $h;
    }
    return $headers;
}

/**
 * Perform one chat-completions request against the currently configured
 * provider. The chatbot never calls Groq / OpenAI / a custom endpoint
 * directly; it goes through here. Translation between OpenAI shape and
 * Anthropic Messages shape happens inside this function — ai_chat.php
 * always sends OpenAI shape in and always gets OpenAI shape back.
 *
 * Returns:
 *   [
 *     'ok'       => bool,
 *     'status'   => int,
 *     'error'    => ?string,
 *     'body'     => ?array,    // parsed JSON in OpenAI shape
 *     'raw'      => string,    // raw HTTP body for logging
 *     'provider' => string,    // 'groq' | 'openai' | 'custom'
 *     'model'    => string,
 *   ]
 *
 * The active provider's API key is the only place the secret lives during
 * the call; it is never logged.
 */
function llm_chat_completions(array $messages, array $tools, ?int $max_tokens = null, ?array $cfg = null): array
{
    if ($cfg === null) {
        $cfg = llm_get_active_config();
    }
    // For custom: report the full list of missing fields (which includes
    // "API key" when it's missing) so the admin sees everything at once.
    if (($cfg['provider'] ?? '') === LLM_PROVIDER_CUSTOM && !empty($cfg['config_errors'])) {
        return [
            'ok'       => false,
            'status'   => 0,
            'error'    => 'custom_misconfigured: ' . implode(', ', $cfg['config_errors']),
            'body'     => null,
            'raw'      => '',
            'provider' => $cfg['provider'],
            'model'    => $cfg['model'],
        ];
    }
    if ($cfg['api_key'] === '') {
        return [
            'ok'       => false,
            'status'   => 0,
            'error'    => 'no_api_key',
            'body'     => null,
            'raw'      => '',
            'provider' => $cfg['provider'],
            'model'    => $cfg['model'],
        ];
    }

    $req = llm_build_chat_request($cfg, $messages, $tools, $max_tokens);
    [$status, $raw, $curlErr] = llm_http_post($req['url'], $req['headers'], $req['body_str']);

    if ($status === 0) {
        return [
            'ok'       => false,
            'status'   => 0,
            'error'    => $curlErr !== '' ? $curlErr : 'network error',
            'body'     => null,
            'raw'      => '',
            'provider' => $cfg['provider'],
            'model'    => $cfg['model'],
        ];
    }

    $decoded = json_decode($raw, true);
    $ok = ($status >= 200 && $status < 300);
    $bodyOut = is_array($decoded) ? $decoded : null;

    // Translate Anthropic-shape responses back to OpenAI shape so callers
    // see a stable interface regardless of which provider is configured.
    if ($ok && $bodyOut !== null
        && ($cfg['provider'] ?? '') === LLM_PROVIDER_CUSTOM
        && ($cfg['body_format'] ?? '') === 'anthropic_messages') {
        $bodyOut = llm_translate_anthropic_response($bodyOut);
    }

    return [
        'ok'       => $ok,
        'status'   => $status,
        'error'    => null,
        'body'     => $bodyOut,
        'raw'      => $raw,
        'provider' => $cfg['provider'],
        'model'    => $cfg['model'],
    ];
}

/**
 * HTTP status codes (plus the synthetic status=0 for network failures) that
 * should trigger failover to the next provider in the chain. Anything else
 * (400/401/403/404/etc.) is treated as a deterministic, configuration-level
 * error that retrying with a different provider won't fix.
 */
function llm_is_failover_status(int $status): bool
{
    if ($status === 0)   return true;   // curl_exec returned false: network / DNS / connection refused / timeout
    if ($status === 429) return true;   // rate limited
    if ($status >= 500 && $status <= 599) return true;
    return false;
}

/**
 * Public entry point that the chatbot calls. Iterates the configured
 * provider chain in priority order, returning the first successful
 * response. On 429 / 5xx / network failures it falls back to the next
 * provider; on 400 / 401 / 403 / 404 it returns the error immediately
 * since those are deterministic and another provider won't fix them.
 *
 * Adds three keys to the returned envelope that downstream callers
 * (ai_chat.php, ai_usage_log, the chat widget) can show to the admin:
 *   served_by_provider  → 'groq' | 'openai' | 'custom' | '' (none worked)
 *   served_by_model     → model id of the provider that actually responded
 *   fell_back_from      → ['groq', ...] providers attempted before success
 */
function llm_chat_completions_with_fallback(array $messages, array $tools, ?int $max_tokens = null): array
{
    $chain = llm_get_provider_chain();
    if (empty($chain)) {
        return [
            'ok'                 => false,
            'status'             => 0,
            'error'              => 'no_provider_available',
            'body'               => null,
            'raw'                => '',
            'provider'           => '',
            'model'              => '',
            'served_by_provider' => '',
            'served_by_model'    => '',
            'fell_back_from'     => [],
            'chain_attempted'    => [],
        ];
    }

    $fellBackFrom = [];
    $attempted    = [];
    $lastResp     = null;
    $count        = count($chain);

    for ($i = 0; $i < $count; $i++) {
        $entry = $chain[$i];
        $provider = $entry['provider'];
        $cfg      = $entry['config'];
        $attempted[] = $provider;

        $resp = llm_chat_completions($messages, $tools, $max_tokens, $cfg);

        if ($resp['ok']) {
            $resp['served_by_provider'] = $provider;
            $resp['served_by_model']    = (string)($cfg['model'] ?? '');
            $resp['fell_back_from']     = $fellBackFrom;
            $resp['chain_attempted']    = $attempted;
            return $resp;
        }

        $status = (int)$resp['status'];
        if (!llm_is_failover_status($status)) {
            // Deterministic error — surface it without trying other providers.
            $resp['served_by_provider'] = $provider;
            $resp['served_by_model']    = (string)($cfg['model'] ?? '');
            $resp['fell_back_from']     = $fellBackFrom;
            $resp['chain_attempted']    = $attempted;
            return $resp;
        }

        // Failover-worthy. Log the reason and move on.
        $reason = $status === 0
            ? ('network: ' . ($resp['error'] ?? 'unknown'))
            : ('HTTP ' . $status);
        $nextName = isset($chain[$i + 1]) ? $chain[$i + 1]['provider'] : 'none';
        error_log("Chatbot fallback: provider {$provider} returned {$reason}, trying provider {$nextName}");
        $fellBackFrom[] = $provider;
        $lastResp = $resp;
    }

    // All providers failed. Return the last error with chain context.
    if ($lastResp === null) {
        $lastResp = [
            'ok' => false, 'status' => 0, 'error' => 'all_providers_failed',
            'body' => null, 'raw' => '', 'provider' => '', 'model' => '',
        ];
    }
    $lastResp['served_by_provider'] = '';
    $lastResp['served_by_model']    = '';
    $lastResp['fell_back_from']     = $fellBackFrom;
    $lastResp['chain_attempted']    = $attempted;
    return $lastResp;
}

/**
 * Test the connection to a specific provider with whatever key is stored.
 *
 * Returns ['ok' => bool, 'model_count' => int|null, 'error' => ?string,
 *          'http_status' => int]. The API key is never echoed back.
 *
 * - groq / openai: GET /models with Bearer auth (original behavior).
 * - custom azure_openai: minimal chat completions probe with max_tokens=1.
 * - custom azure_anthropic: minimal POST to /anthropic/v1/messages with
 *   max_tokens=1 and a one-word user message.
 * - custom openai_compatible: GET /models; if that returns 404 fall back to
 *   the chat completions probe.
 */
function llm_test_connection(string $provider): array
{
    try {
        $cfg = llm_get_provider_config($provider);
    } catch (LlmProviderException $e) {
        return ['ok' => false, 'model_count' => null, 'error' => $e->getMessage(), 'http_status' => 0];
    }
    if ($provider === LLM_PROVIDER_CUSTOM && !empty($cfg['config_errors'])) {
        return [
            'ok'          => false,
            'model_count' => null,
            'error'       => 'Custom provider is incomplete: missing ' . implode(', ', $cfg['config_errors']) . '.',
            'http_status' => 0,
        ];
    }
    if ($cfg['api_key'] === '') {
        return [
            'ok'          => false,
            'model_count' => null,
            'error'       => 'No ' . llm_provider_label($provider) . ' API key is configured.',
            'http_status' => 0,
        ];
    }

    if ($provider === LLM_PROVIDER_GROQ || $provider === LLM_PROVIDER_OPENAI) {
        $url = rtrim($cfg['base_url'], '/') . '/models';
        [$code, $body, $err] = llm_http_get($url, [
            'Authorization: Bearer ' . $cfg['api_key'],
            'Accept: application/json',
        ]);
        return llm_summarize_test_response($provider, $code, $body, $err, /*expect_models=*/true);
    }

    // Custom provider — dispatch on preset.
    $preset = (string)($cfg['preset'] ?? '');
    if ($preset === LLM_CUSTOM_PRESET_OPENAI_COMPATIBLE) {
        // Try GET /models first.
        $url = rtrim((string)$cfg['base_url'], '/') . '/models';
        $hdrs = llm_build_auth_headers($cfg);
        $hdrs[] = 'Accept: application/json';
        [$code, $body, $err] = llm_http_get($url, $hdrs);
        if ($code !== 0 && $code !== 404 && $err === '') {
            return llm_summarize_test_response($provider, $code, $body, $err, /*expect_models=*/true);
        }
        // Fall through to chat probe.
    }
    return llm_test_chat_probe($cfg, $provider);
}

/**
 * Token budget for a connection-test probe. Most providers accept and
 * return cleanly at max_tokens=1, but two paths need more headroom:
 *
 *   - Anthropic Messages (azure_anthropic preset): Claude returns the
 *     specific error "Could not finish the message because max_tokens or
 *     model output limit was reached" rather than HTTP 200 when the limit
 *     is below ~10 tokens. 50 is plenty for the model to produce a "ok"
 *     reply and stop naturally on its own end-turn signal.
 *
 *   - Azure OpenAI deployments backed by the GPT-5 family or the o1 / o3
 *     reasoning models. These bill reasoning tokens against the same
 *     budget that caps visible output, so max_completion_tokens=1 leaves
 *     no room for any user-visible reply and the deployment errors out.
 *     50 lets the model produce one short token of output.
 *
 * Everything else (Groq /models GET, openai_compatible /models GET, the
 * older openai_compatible chat-completion fallback, and azure_openai
 * non-reasoning deployments like gpt-4o-mini) continues to use 1. The
 * extra spend on the two paths above is a few output tokens per probe
 * — probes are rare (manual admin click), so the cost is negligible.
 */
function llm_probe_max_tokens(array $cfg): int
{
    $bodyFormat = (string)($cfg['body_format'] ?? 'openai_chat');
    if ($bodyFormat === 'anthropic_messages') {
        return 50;
    }
    $tokField = (string)($cfg['token_field'] ?? 'max_tokens');
    if ($tokField === 'max_completion_tokens') {
        // Azure GPT-5 / o1 / o3 (or any openai_compatible model the admin
        // pointed at a reasoning model with that override).
        return 50;
    }
    return 1;
}

/**
 * Minimal POST probe: one user message. For Anthropic-shape presets we POST
 * the Anthropic Messages body; for OpenAI-shape we POST a single-turn chat
 * completions body. The probe token budget comes from llm_probe_max_tokens()
 * so models that can't finish a coherent reply at max_tokens=1 still get a
 * 2xx success on the probe. A 2xx response counts as a connection success.
 */
function llm_test_chat_probe(array $cfg, string $provider): array
{
    $maxTokens = llm_probe_max_tokens($cfg);
    // Anthropic + Azure GPT-5/o1/o3 use a slightly more directive prompt so
    // the model produces a deterministic short reply; the cheaper providers
    // keep the original one-word "hi" body for size and speed.
    $userText = ($maxTokens > 1) ? "Reply with 'ok'." : 'hi';
    $messages = [['role' => 'user', 'content' => $userText]];
    $req = llm_build_chat_request($cfg, $messages, [], $maxTokens);
    [$code, $body, $err] = llm_http_post($req['url'], $req['headers'], $req['body_str']);
    if ($code === 0) {
        return ['ok' => false, 'model_count' => null, 'error' => 'Network error: ' . ($err ?: 'unknown'), 'http_status' => 0];
    }
    if ($code >= 200 && $code < 300) {
        return ['ok' => true, 'model_count' => null, 'error' => null, 'http_status' => $code];
    }
    $msg = llm_extract_error_message($body, $code, $provider);
    return ['ok' => false, 'model_count' => null, 'error' => $msg, 'http_status' => $code];
}

function llm_summarize_test_response(string $provider, int $code, string $body, string $err, bool $expectModels): array
{
    if ($code === 0) {
        return ['ok' => false, 'model_count' => null, 'error' => 'Network error: ' . ($err ?: 'unknown'), 'http_status' => 0];
    }
    if ($code === 200) {
        $count = null;
        $decoded = json_decode($body, true);
        if ($expectModels && is_array($decoded) && isset($decoded['data']) && is_array($decoded['data'])) {
            $count = count($decoded['data']);
        }
        return ['ok' => true, 'model_count' => $count, 'error' => null, 'http_status' => 200];
    }
    $msg = llm_extract_error_message($body, $code, $provider);
    return ['ok' => false, 'model_count' => null, 'error' => $msg, 'http_status' => $code];
}

function llm_extract_error_message(string $body, int $code, string $provider): string
{
    $decoded = json_decode($body, true);
    if (is_array($decoded)) {
        if (isset($decoded['error']['message'])) return (string)$decoded['error']['message'];
        if (isset($decoded['error']) && is_string($decoded['error'])) return (string)$decoded['error'];
        if (isset($decoded['message'])) return (string)$decoded['message'];
    }
    return 'HTTP ' . $code . ' from ' . llm_provider_label($provider);
}
