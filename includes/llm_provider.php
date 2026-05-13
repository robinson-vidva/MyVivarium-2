<?php
/**
 * LLM provider abstraction.
 *
 * Both Groq and OpenAI speak the same OpenAI-compatible /v1/chat/completions
 * shape, so dispatch is a single function that reads the configured provider
 * from ai_settings, decrypts the per-provider API key for the duration of
 * one request, and POSTs to <base_url>/chat/completions with Bearer auth.
 *
 * The chatbot never picks a provider directly — it always calls
 * llm_chat_completions() and lets this file decide which backend to hit.
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

/**
 * Returns the active provider id ("groq" or "openai"), defaulting to groq
 * if unset or invalid.
 */
function llm_get_active_provider(): string
{
    try {
        $p = ai_settings_get('llm_provider');
    } catch (Throwable $e) {
        return LLM_PROVIDER_GROQ;
    }
    if ($p === LLM_PROVIDER_OPENAI) return LLM_PROVIDER_OPENAI;
    return LLM_PROVIDER_GROQ;
}

/**
 * Returns the resolved config for the active provider:
 *   ['provider', 'api_key', 'model', 'base_url', 'allowed_models']
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
 * Display label, e.g. "OpenAI" / "Groq".
 */
function llm_provider_label(string $provider): string
{
    return $provider === LLM_PROVIDER_OPENAI ? 'OpenAI' : 'Groq';
}

/**
 * Perform one chat-completions request against the currently configured
 * provider. The chatbot never calls Groq or OpenAI directly; it goes through
 * here. Returns:
 *   [
 *     'ok'       => bool,
 *     'status'   => int,
 *     'error'    => ?string,
 *     'body'     => ?array,    // parsed JSON
 *     'raw'      => string,    // raw HTTP body for logging
 *     'provider' => string,    // 'groq' or 'openai'
 *     'model'    => string,
 *   ]
 *
 * The active provider's API key is the only place the secret lives during
 * the call; it is never logged.
 */
function llm_chat_completions(array $messages, array $tools, ?int $max_tokens = null): array
{
    $cfg = llm_get_active_config();
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

    $payload = [
        'model'       => $cfg['model'],
        'messages'    => $messages,
        'temperature' => 0.2,
    ];
    if (!empty($tools)) {
        $payload['tools']       = $tools;
        $payload['tool_choice'] = 'auto';
    }
    if ($max_tokens !== null && $max_tokens > 0) {
        $payload['max_tokens'] = $max_tokens;
    }

    $url = rtrim($cfg['base_url'], '/') . '/chat/completions';
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $cfg['api_key'],
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_SLASHES),
        CURLOPT_TIMEOUT        => 30,
    ]);
    $raw = curl_exec($ch);
    if ($raw === false) {
        $err = curl_error($ch);
        curl_close($ch);
        return [
            'ok'       => false,
            'status'   => 0,
            'error'    => $err,
            'body'     => null,
            'raw'      => '',
            'provider' => $cfg['provider'],
            'model'    => $cfg['model'],
        ];
    }
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $decoded = json_decode((string)$raw, true);
    return [
        'ok'       => $status >= 200 && $status < 300,
        'status'   => $status,
        'error'    => null,
        'body'     => is_array($decoded) ? $decoded : null,
        'raw'      => (string)$raw,
        'provider' => $cfg['provider'],
        'model'    => $cfg['model'],
    ];
}

/**
 * Hit the provider's GET /models endpoint with the saved key. Returns:
 *   ['ok' => bool, 'model_count' => int|null, 'error' => string|null,
 *    'http_status' => int]
 *
 * "no_api_key" is signalled with ok=false, error='No API key is configured.'
 * and model_count=null. The key is never echoed back.
 */
function llm_test_connection(string $provider): array
{
    try {
        $cfg = llm_get_provider_config($provider);
    } catch (LlmProviderException $e) {
        return ['ok' => false, 'model_count' => null, 'error' => $e->getMessage(), 'http_status' => 0];
    }
    if ($cfg['api_key'] === '') {
        return [
            'ok'          => false,
            'model_count' => null,
            'error'       => 'No ' . llm_provider_label($provider) . ' API key is configured.',
            'http_status' => 0,
        ];
    }
    $url = rtrim($cfg['base_url'], '/') . '/models';
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $cfg['api_key'],
            'Accept: application/json',
        ],
    ]);
    $body = curl_exec($ch);
    $err  = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false) {
        return ['ok' => false, 'model_count' => null, 'error' => 'Network error: ' . ($err ?: 'unknown'), 'http_status' => 0];
    }
    if ($code === 200) {
        $decoded = json_decode((string)$body, true);
        $count = 0;
        if (is_array($decoded) && isset($decoded['data']) && is_array($decoded['data'])) {
            $count = count($decoded['data']);
        }
        return ['ok' => true, 'model_count' => $count, 'error' => null, 'http_status' => 200];
    }
    $decoded = json_decode((string)$body, true);
    $msg = null;
    if (is_array($decoded)) {
        if (isset($decoded['error']['message'])) $msg = (string)$decoded['error']['message'];
        elseif (isset($decoded['message']))      $msg = (string)$decoded['message'];
    }
    if ($msg === null || $msg === '') {
        $msg = 'HTTP ' . $code . ' from ' . llm_provider_label($provider);
    }
    return ['ok' => false, 'model_count' => null, 'error' => $msg, 'http_status' => $code];
}
