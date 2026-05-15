<?php
/**
 * Unit tests for the "Custom" LLM provider in includes/llm_provider.php.
 *
 * Covers all three presets:
 *   - azure_openai
 *   - azure_anthropic
 *   - openai_compatible
 *
 * For each preset we verify:
 *   - llm_get_provider_config('custom') resolves the right base/request URL
 *   - llm_build_chat_request() constructs the right URL, headers, and body
 *   - llm_chat_completions() dispatches to the right URL with the right
 *     headers (via a stubbed HTTP hook — no live network)
 *   - llm_test_connection() picks the right probe per preset
 *   - Missing required fields surface in 'config_errors'
 *
 *     php tests/llm_provider_custom_test.php
 */

// In-memory stub of ai_settings_get() / AiSettingsException, declared
// BEFORE llm_provider.php is loaded so the production ai_settings.php
// (which opens a mysqli connection) is never required.
class AiSettingsException extends RuntimeException {}

$GLOBALS['__llm_test_store'] = [];
function ai_settings_get(string $key): ?string
{
    $store = $GLOBALS['__llm_test_store'] ?? [];
    return array_key_exists($key, $store) ? $store[$key] : null;
}
function _llm_test_set(string $key, ?string $value): void
{
    if ($value === null) { unset($GLOBALS['__llm_test_store'][$key]); return; }
    $GLOBALS['__llm_test_store'][$key] = $value;
}
function _llm_test_reset(): void
{
    $GLOBALS['__llm_test_store'] = [];
    unset($GLOBALS['LLM_HTTP_POST_HOOK']);
    unset($GLOBALS['LLM_HTTP_GET_HOOK']);
}

require_once __DIR__ . '/../includes/llm_provider.php';

$pass = 0; $fail = 0;
function check(string $name, bool $ok): void {
    global $pass, $fail;
    if ($ok) { echo "[PASS] $name\n"; $pass++; }
    else     { echo "[FAIL] $name\n"; $fail++; }
}

// ---------------------------------------------------------------------------
// Preset 1: azure_openai
// ---------------------------------------------------------------------------

_llm_test_reset();
_llm_test_set('llm_provider', 'custom');
_llm_test_set('custom_preset', 'azure_openai');
_llm_test_set('custom_resource_url', 'https://apim-xyz.azure-api.net');
_llm_test_set('custom_deployment', 'gpt-4o-mini');
_llm_test_set('custom_api_version', '2024-10-21');
_llm_test_set('custom_api_key', 'azkey-123');

$cfg = llm_get_active_config();
check('azure_openai: provider is custom',
    $cfg['provider'] === 'custom');
check('azure_openai: preset is azure_openai',
    $cfg['preset'] === 'azure_openai');
check('azure_openai: request_url has /openai/deployments/<name>/chat/completions?api-version=',
    $cfg['request_url'] === 'https://apim-xyz.azure-api.net/openai/deployments/gpt-4o-mini/chat/completions?api-version=2024-10-21');
check('azure_openai: auth header is api-key (no Bearer prefix)',
    $cfg['auth_header'] === 'api-key' && $cfg['auth_prefix'] === '');
check('azure_openai: token_field is max_tokens for gpt-4o-mini',
    $cfg['token_field'] === 'max_tokens');
check('azure_openai: model in body is suppressed',
    $cfg['include_model_in_body'] === false);
check('azure_openai: no config errors',
    empty($cfg['config_errors']));
check('azure_openai: banner format',
    llm_active_provider_banner($cfg) === 'Active: Custom — Azure OpenAI (gpt-4o-mini)');

// Build request — assert URL, headers, body shape
$req = llm_build_chat_request($cfg, [['role'=>'user','content'=>'hi']], [], 5);
$bodyArr = $req['body_arr'];
check('azure_openai: built URL matches',
    $req['url'] === $cfg['request_url']);
check('azure_openai: api-key header set without Bearer',
    in_array('api-key: azkey-123', $req['headers'], true));
check('azure_openai: body has no model key',
    !isset($bodyArr['model']));
check('azure_openai: body uses max_tokens',
    $bodyArr['max_tokens'] === 5);
check('azure_openai: body has messages',
    is_array($bodyArr['messages']) && $bodyArr['messages'][0]['role'] === 'user');

// gpt-5 deployment → max_completion_tokens
_llm_test_set('custom_deployment', 'gpt-5-preview');
$cfg2 = llm_get_active_config();
check('azure_openai: gpt-5 deployment uses max_completion_tokens',
    $cfg2['token_field'] === 'max_completion_tokens');
$req2 = llm_build_chat_request($cfg2, [['role'=>'user','content'=>'hi']], [], 5);
check('azure_openai: gpt-5 body uses max_completion_tokens',
    isset($req2['body_arr']['max_completion_tokens']) && !isset($req2['body_arr']['max_tokens']));

// o1 / o3 deployments same treatment
_llm_test_set('custom_deployment', 'o3-mini');
$cfg3 = llm_get_active_config();
check('azure_openai: o3-mini deployment uses max_completion_tokens',
    $cfg3['token_field'] === 'max_completion_tokens');

// Missing fields → config_errors
_llm_test_reset();
_llm_test_set('llm_provider', 'custom');
_llm_test_set('custom_preset', 'azure_openai');
$cfg4 = llm_get_active_config();
check('azure_openai: missing fields surface in config_errors',
    !empty($cfg4['config_errors']) && in_array('Resource URL', $cfg4['config_errors'], true)
    && in_array('Deployment name', $cfg4['config_errors'], true)
    && in_array('API key', $cfg4['config_errors'], true));

// Stubbed POST: verify llm_chat_completions hits the right URL with the
// right body shape and returns the parsed JSON.
_llm_test_reset();
_llm_test_set('llm_provider', 'custom');
_llm_test_set('custom_preset', 'azure_openai');
_llm_test_set('custom_resource_url', 'https://res.openai.azure.com');
_llm_test_set('custom_deployment', 'gpt-4o-mini');
_llm_test_set('custom_api_version', '2024-10-21');
_llm_test_set('custom_api_key', 'azkey');

$captured = ['url' => null, 'headers' => null, 'body' => null];
$GLOBALS['LLM_HTTP_POST_HOOK'] = function ($url, $headers, $body) use (&$captured) {
    $captured['url'] = $url; $captured['headers'] = $headers; $captured['body'] = $body;
    return [200, json_encode([
        'id'      => 'chatcmpl-1',
        'choices' => [['message' => ['role' => 'assistant', 'content' => 'pong']]],
        'usage'   => ['prompt_tokens' => 4, 'completion_tokens' => 1],
    ]), ''];
};
$res = llm_chat_completions([['role'=>'user','content'=>'ping']], [], 1);
check('azure_openai chat: POST URL is the built request URL',
    $captured['url'] === 'https://res.openai.azure.com/openai/deployments/gpt-4o-mini/chat/completions?api-version=2024-10-21');
check('azure_openai chat: api-key header in flight',
    in_array('api-key: azkey', $captured['headers'], true));
check('azure_openai chat: returns ok=true with OpenAI-shape body',
    $res['ok'] === true && $res['body']['choices'][0]['message']['content'] === 'pong');

// ---------------------------------------------------------------------------
// Preset 2: azure_anthropic
// ---------------------------------------------------------------------------

_llm_test_reset();
_llm_test_set('llm_provider', 'custom');
_llm_test_set('custom_preset', 'azure_anthropic');
_llm_test_set('custom_base_url', 'https://apim-xyz.azure-api.net');
_llm_test_set('custom_model', 'claude-sonnet-4-6');
_llm_test_set('custom_api_key', 'anth-key');

$cfg = llm_get_active_config();
check('azure_anthropic: provider is custom',
    $cfg['provider'] === 'custom' && $cfg['preset'] === 'azure_anthropic');
check('azure_anthropic: request_url is /anthropic/v1/messages',
    $cfg['request_url'] === 'https://apim-xyz.azure-api.net/anthropic/v1/messages');
check('azure_anthropic: auth header is x-api-key (no Bearer)',
    $cfg['auth_header'] === 'x-api-key' && $cfg['auth_prefix'] === '');
check('azure_anthropic: anthropic-version header present',
    in_array('anthropic-version: 2023-06-01', $cfg['extra_headers'], true));
check('azure_anthropic: body_format is anthropic_messages',
    $cfg['body_format'] === 'anthropic_messages');
check('azure_anthropic: token_field is max_tokens',
    $cfg['token_field'] === 'max_tokens');
check('azure_anthropic: banner format',
    llm_active_provider_banner($cfg) === 'Active: Custom — Azure Anthropic (claude-sonnet-4-6)');

$messages = [
    ['role' => 'system',    'content' => 'you are a helpful lab assistant.'],
    ['role' => 'user',      'content' => 'list cages'],
];
$req = llm_build_chat_request($cfg, $messages, [], 32);
$body = $req['body_arr'];
check('azure_anthropic: body has model',
    ($body['model'] ?? '') === 'claude-sonnet-4-6');
check('azure_anthropic: body has max_tokens',
    ($body['max_tokens'] ?? 0) === 32);
check('azure_anthropic: system is hoisted to top-level field',
    isset($body['system']) && strpos($body['system'], 'helpful lab assistant') !== false);
check('azure_anthropic: messages exclude system',
    count($body['messages']) === 1 && $body['messages'][0]['role'] === 'user');
check('azure_anthropic: message content is content blocks (not raw string)',
    is_array($body['messages'][0]['content']) && $body['messages'][0]['content'][0]['type'] === 'text');

// Stubbed POST: response is in Anthropic shape, must come back as OpenAI shape.
$GLOBALS['LLM_HTTP_POST_HOOK'] = function ($url, $headers, $body) {
    // Echo back an Anthropic-shape response with a tool_use block.
    return [200, json_encode([
        'id'      => 'msg_1',
        'model'   => 'claude-sonnet-4-6',
        'content' => [
            ['type' => 'text', 'text' => 'I will list cages.'],
            ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'listHoldingCages', 'input' => ['limit' => 5]],
        ],
        'stop_reason' => 'tool_use',
        'usage'   => ['input_tokens' => 12, 'output_tokens' => 8],
    ]), ''];
};
$res = llm_chat_completions($messages, [
    ['type' => 'function', 'function' => ['name' => 'listHoldingCages', 'description' => 'list cages', 'parameters' => ['type' => 'object', 'properties' => (object)[]]]],
], 64);

check('azure_anthropic chat: ok=true',
    $res['ok'] === true);
$msg = $res['body']['choices'][0]['message'] ?? null;
check('azure_anthropic chat: response is in OpenAI shape with assistant message',
    is_array($msg) && $msg['role'] === 'assistant');
check('azure_anthropic chat: content carried over',
    strpos((string)$msg['content'], 'I will list cages.') !== false);
check('azure_anthropic chat: tool_use translated to tool_calls',
    isset($msg['tool_calls'][0]['function']['name']) && $msg['tool_calls'][0]['function']['name'] === 'listHoldingCages');
check('azure_anthropic chat: tool_calls arguments is a JSON string',
    is_string($msg['tool_calls'][0]['function']['arguments'])
    && strpos($msg['tool_calls'][0]['function']['arguments'], 'limit') !== false);
check('azure_anthropic chat: usage mapped to prompt_tokens/completion_tokens',
    $res['body']['usage']['prompt_tokens'] === 12 && $res['body']['usage']['completion_tokens'] === 8);

// Missing fields → config_errors
_llm_test_reset();
_llm_test_set('llm_provider', 'custom');
_llm_test_set('custom_preset', 'azure_anthropic');
$cfg5 = llm_get_active_config();
check('azure_anthropic: missing fields surface in config_errors',
    in_array('Base URL', $cfg5['config_errors'], true)
    && in_array('Model', $cfg5['config_errors'], true)
    && in_array('API key', $cfg5['config_errors'], true));

// ---------------------------------------------------------------------------
// Preset 3: openai_compatible
// ---------------------------------------------------------------------------

_llm_test_reset();
_llm_test_set('llm_provider', 'custom');
_llm_test_set('custom_preset', 'openai_compatible');
_llm_test_set('custom_base_url', 'https://api.openrouter.ai/api/v1');
_llm_test_set('custom_model', 'openrouter/deepseek/deepseek-chat-v3');
_llm_test_set('custom_api_key', 'or-key');

$cfg = llm_get_active_config();
check('openai_compatible: provider/preset',
    $cfg['provider'] === 'custom' && $cfg['preset'] === 'openai_compatible');
check('openai_compatible: request_url is base + /chat/completions',
    $cfg['request_url'] === 'https://api.openrouter.ai/api/v1/chat/completions');
check('openai_compatible: auth is Bearer Authorization',
    $cfg['auth_header'] === 'Authorization' && $cfg['auth_prefix'] === 'Bearer ');
check('openai_compatible: body_format is openai_chat',
    $cfg['body_format'] === 'openai_chat');
check('openai_compatible: default token_field is max_tokens',
    $cfg['token_field'] === 'max_tokens');
check('openai_compatible: include_model_in_body is true',
    $cfg['include_model_in_body'] === true);
check('openai_compatible: banner format',
    llm_active_provider_banner($cfg) === 'Active: Custom — OpenAI-compatible (openrouter/deepseek/deepseek-chat-v3)');

// token field override
_llm_test_set('custom_token_field', 'max_completion_tokens');
$cfg6 = llm_get_active_config();
check('openai_compatible: token_field override honored',
    $cfg6['token_field'] === 'max_completion_tokens');
$req = llm_build_chat_request($cfg6, [['role'=>'user','content'=>'hi']], [], 7);
check('openai_compatible: body uses overridden token field',
    isset($req['body_arr']['max_completion_tokens']) && $req['body_arr']['max_completion_tokens'] === 7);

// Stubbed POST: confirm Bearer auth + model in body + URL.
_llm_test_set('custom_token_field', 'max_tokens');
$captured = ['url' => null, 'headers' => null, 'body' => null];
$GLOBALS['LLM_HTTP_POST_HOOK'] = function ($url, $headers, $body) use (&$captured) {
    $captured['url'] = $url; $captured['headers'] = $headers; $captured['body'] = json_decode($body, true);
    return [200, json_encode([
        'id'      => 'cmpl-x',
        'choices' => [['message' => ['role' => 'assistant', 'content' => 'ok']]],
        'usage'   => ['prompt_tokens' => 2, 'completion_tokens' => 1],
    ]), ''];
};
$res = llm_chat_completions([['role'=>'user','content'=>'hi']], [], 16);
check('openai_compatible chat: POST URL is base/chat/completions',
    $captured['url'] === 'https://api.openrouter.ai/api/v1/chat/completions');
check('openai_compatible chat: Authorization is Bearer + key',
    in_array('Authorization: Bearer or-key', $captured['headers'], true));
check('openai_compatible chat: body contains model',
    $captured['body']['model'] === 'openrouter/deepseek/deepseek-chat-v3');
check('openai_compatible chat: returns parsed body',
    $res['ok'] === true && $res['body']['choices'][0]['message']['content'] === 'ok');

// Missing fields → config_errors
_llm_test_reset();
_llm_test_set('llm_provider', 'custom');
_llm_test_set('custom_preset', 'openai_compatible');
$cfg7 = llm_get_active_config();
check('openai_compatible: missing fields → config_errors',
    in_array('Base URL', $cfg7['config_errors'], true)
    && in_array('Model', $cfg7['config_errors'], true)
    && in_array('API key', $cfg7['config_errors'], true));

// ---------------------------------------------------------------------------
// Active provider banner & label fallbacks
// ---------------------------------------------------------------------------

_llm_test_reset();
check('label: custom → Custom',
    llm_provider_label('custom') === 'Custom');
check('preset label: azure_openai → Azure OpenAI',
    llm_custom_preset_label('azure_openai') === 'Azure OpenAI');
check('preset label: azure_anthropic → Azure Anthropic',
    llm_custom_preset_label('azure_anthropic') === 'Azure Anthropic');
check('preset label: openai_compatible → OpenAI-compatible',
    llm_custom_preset_label('openai_compatible') === 'OpenAI-compatible');
check('preset label: bogus → ""',
    llm_custom_preset_label('foo') === '');

// Banner for groq / openai keeps the existing shape.
check('banner groq: name + parens',
    llm_active_provider_banner(['provider' => 'groq',   'model' => 'llama-3.3-70b-versatile'])
        === 'Active: Groq (llama-3.3-70b-versatile)');
check('banner openai: name + parens',
    llm_active_provider_banner(['provider' => 'openai', 'model' => 'gpt-5.4-mini'])
        === 'Active: OpenAI (gpt-5.4-mini)');

// ---------------------------------------------------------------------------
// llm_test_connection() dispatch per preset
// ---------------------------------------------------------------------------

// azure_openai → chat probe (POST)
_llm_test_reset();
_llm_test_set('llm_provider', 'custom');
_llm_test_set('custom_preset', 'azure_openai');
_llm_test_set('custom_resource_url', 'https://r.openai.azure.com');
_llm_test_set('custom_deployment', 'gpt-4o-mini');
_llm_test_set('custom_api_key', 'k');
$probedUrl = null;
$GLOBALS['LLM_HTTP_POST_HOOK'] = function ($url, $headers, $body) use (&$probedUrl) {
    $probedUrl = $url;
    return [200, json_encode(['id' => 'cmpl-x', 'choices' => [['message' => ['role'=>'assistant','content'=>'']]]]), ''];
};
$res = llm_test_connection('custom');
check('test_connection azure_openai: ok=true via chat probe',
    $res['ok'] === true && strpos((string)$probedUrl, '/openai/deployments/gpt-4o-mini/chat/completions') !== false);

// azure_anthropic → POST to /anthropic/v1/messages
_llm_test_reset();
_llm_test_set('llm_provider', 'custom');
_llm_test_set('custom_preset', 'azure_anthropic');
_llm_test_set('custom_base_url', 'https://apim.example.com');
_llm_test_set('custom_model', 'claude-haiku-4-5');
_llm_test_set('custom_api_key', 'k');
$probedUrl = null;
$GLOBALS['LLM_HTTP_POST_HOOK'] = function ($url, $headers, $body) use (&$probedUrl) {
    $probedUrl = $url;
    return [200, json_encode(['id'=>'msg_1','content'=>[['type'=>'text','text'=>'ok']],'usage'=>['input_tokens'=>1,'output_tokens'=>1]]), ''];
};
$res = llm_test_connection('custom');
check('test_connection azure_anthropic: ok=true via Anthropic Messages POST',
    $res['ok'] === true && $probedUrl === 'https://apim.example.com/anthropic/v1/messages');

// openai_compatible → GET /models first; 200 with data array reports count
_llm_test_reset();
_llm_test_set('llm_provider', 'custom');
_llm_test_set('custom_preset', 'openai_compatible');
_llm_test_set('custom_base_url', 'https://api.example.com/v1');
_llm_test_set('custom_model', 'foo/bar');
_llm_test_set('custom_api_key', 'k');
$GLOBALS['LLM_HTTP_GET_HOOK'] = function ($url, $headers) {
    return [200, json_encode(['data' => [['id' => 'a'], ['id' => 'b'], ['id' => 'c']]]), ''];
};
$res = llm_test_connection('custom');
check('test_connection openai_compatible: GET /models returns model count',
    $res['ok'] === true && $res['model_count'] === 3);

// openai_compatible → /models returns 404, falls back to chat probe
_llm_test_reset();
_llm_test_set('llm_provider', 'custom');
_llm_test_set('custom_preset', 'openai_compatible');
_llm_test_set('custom_base_url', 'https://api.example.com/v1');
_llm_test_set('custom_model', 'foo/bar');
_llm_test_set('custom_api_key', 'k');
$probeAttempted = false;
$GLOBALS['LLM_HTTP_GET_HOOK'] = function () { return [404, '{}', '']; };
$GLOBALS['LLM_HTTP_POST_HOOK'] = function ($url, $headers, $body) use (&$probeAttempted) {
    $probeAttempted = true;
    return [200, json_encode(['choices'=>[['message'=>['role'=>'assistant','content'=>'']]]]), ''];
};
$res = llm_test_connection('custom');
check('test_connection openai_compatible: 404 on /models falls back to chat probe',
    $res['ok'] === true && $probeAttempted === true);

// Custom-misconfigured: test_connection surfaces the missing fields rather
// than attempting any HTTP call.
_llm_test_reset();
_llm_test_set('llm_provider', 'custom');
_llm_test_set('custom_preset', 'azure_openai');
$GLOBALS['LLM_HTTP_POST_HOOK'] = function () { return [200, '{}', '']; };
$res = llm_test_connection('custom');
check('test_connection custom-misconfigured: blocks HTTP, reports missing fields',
    $res['ok'] === false && strpos((string)$res['error'], 'missing') !== false);

// chat_completions with misconfigured custom returns custom_misconfigured
// rather than calling out.
$called = false;
$GLOBALS['LLM_HTTP_POST_HOOK'] = function () use (&$called) { $called = true; return [200, '{}', '']; };
$res = llm_chat_completions([['role'=>'user','content'=>'hi']], []);
check('chat_completions custom-misconfigured: short-circuits without HTTP',
    $res['ok'] === false && $called === false);
check('chat_completions custom-misconfigured: error names the missing fields',
    strpos((string)$res['error'], 'custom_misconfigured') === 0);

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
