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

// ---------------------------------------------------------------------------
// Temperature handling: Azure GPT-5 / o1 / o3 reject any non-default
// temperature value, so the helper must report "omit temperature" for those
// model/deployment names and "keep temperature" for everything else. The
// rule is asserted both at the helper level (llm_model_omits_temperature)
// and at the request-body level (llm_build_chat_request) so we know the
// request actually leaves without a temperature key.
// ---------------------------------------------------------------------------

check('omit_temperature helper: gpt-5 → true',
    llm_model_omits_temperature('gpt-5') === true);
check('omit_temperature helper: gpt-5-preview → true',
    llm_model_omits_temperature('gpt-5-preview') === true);
check('omit_temperature helper: gpt-5.5 (with dot) → true',
    llm_model_omits_temperature('gpt-5.5') === true);
check('omit_temperature helper: GPT-5 uppercase → true (case-insensitive)',
    llm_model_omits_temperature('GPT-5-Turbo') === true);
check('omit_temperature helper: o1-mini → true',
    llm_model_omits_temperature('o1-mini') === true);
check('omit_temperature helper: o3-mini → true',
    llm_model_omits_temperature('o3-mini') === true);
check('omit_temperature helper: O3 uppercase → true',
    llm_model_omits_temperature('O3-preview') === true);
check('omit_temperature helper: gpt-4o-mini → false',
    llm_model_omits_temperature('gpt-4o-mini') === false);
check('omit_temperature helper: gpt-4o → false',
    llm_model_omits_temperature('gpt-4o') === false);
check('omit_temperature helper: gpt-3.5 → false',
    llm_model_omits_temperature('gpt-3.5-turbo') === false);
check('omit_temperature helper: claude-sonnet-4-6 → false',
    llm_model_omits_temperature('claude-sonnet-4-6') === false);
check('omit_temperature helper: empty → false',
    llm_model_omits_temperature('') === false);

// azure_openai: gpt-5 deployment → request body omits temperature
_llm_test_reset();
_llm_test_set('llm_provider', 'custom');
_llm_test_set('custom_preset', 'azure_openai');
_llm_test_set('custom_resource_url', 'https://r.openai.azure.com');
_llm_test_set('custom_deployment', 'gpt-5');
_llm_test_set('custom_api_key', 'k');
$cfgGpt5 = llm_get_active_config();
$reqGpt5 = llm_build_chat_request($cfgGpt5, [['role'=>'user','content'=>'hi']], [], 1);
check('azure_openai gpt-5: request body has NO temperature key',
    !array_key_exists('temperature', $reqGpt5['body_arr']));
check('azure_openai gpt-5: serialized JSON has no temperature',
    strpos($reqGpt5['body_str'], 'temperature') === false);

// azure_openai: gpt-5.5 (with dot) → request body omits temperature
_llm_test_set('custom_deployment', 'gpt-5.5');
$cfgGpt55 = llm_get_active_config();
$reqGpt55 = llm_build_chat_request($cfgGpt55, [['role'=>'user','content'=>'hi']], [], 1);
check('azure_openai gpt-5.5: request body has NO temperature key',
    !array_key_exists('temperature', $reqGpt55['body_arr']));

// azure_openai: o1 deployment → request body omits temperature
_llm_test_set('custom_deployment', 'o1-preview');
$cfgO1 = llm_get_active_config();
$reqO1 = llm_build_chat_request($cfgO1, [['role'=>'user','content'=>'hi']], [], 1);
check('azure_openai o1-preview: request body has NO temperature key',
    !array_key_exists('temperature', $reqO1['body_arr']));

// azure_openai: o3 deployment → request body omits temperature
_llm_test_set('custom_deployment', 'o3-mini');
$cfgO3 = llm_get_active_config();
$reqO3 = llm_build_chat_request($cfgO3, [['role'=>'user','content'=>'hi']], [], 1);
check('azure_openai o3-mini: request body has NO temperature key',
    !array_key_exists('temperature', $reqO3['body_arr']));

// azure_openai: gpt-4o-mini deployment → request body STILL HAS temperature
_llm_test_set('custom_deployment', 'gpt-4o-mini');
$cfgGpt4o = llm_get_active_config();
$reqGpt4o = llm_build_chat_request($cfgGpt4o, [['role'=>'user','content'=>'hi']], [], 1);
check('azure_openai gpt-4o-mini: request body HAS temperature key',
    array_key_exists('temperature', $reqGpt4o['body_arr'])
    && $reqGpt4o['body_arr']['temperature'] === 0.2);

// test_connection probe: gpt-5 deployment also omits temperature in the
// minimal probe body that hits Azure.
_llm_test_reset();
_llm_test_set('llm_provider', 'custom');
_llm_test_set('custom_preset', 'azure_openai');
_llm_test_set('custom_resource_url', 'https://r.openai.azure.com');
_llm_test_set('custom_deployment', 'gpt-5.5');
_llm_test_set('custom_api_key', 'k');
$probeBody = null;
$GLOBALS['LLM_HTTP_POST_HOOK'] = function ($url, $headers, $body) use (&$probeBody) {
    $probeBody = $body;
    return [200, json_encode(['choices'=>[['message'=>['role'=>'assistant','content'=>'']]]]), ''];
};
$res = llm_test_connection('custom');
check('test_connection azure_openai gpt-5.5: probe body has no temperature',
    is_string($probeBody) && strpos($probeBody, 'temperature') === false);
check('test_connection azure_openai gpt-5.5: ok=true',
    $res['ok'] === true);

// openai_compatible with a gpt-5 model id → also omits temperature
_llm_test_reset();
_llm_test_set('llm_provider', 'custom');
_llm_test_set('custom_preset', 'openai_compatible');
_llm_test_set('custom_base_url', 'https://api.example.com/v1');
_llm_test_set('custom_model', 'gpt-5-something');
_llm_test_set('custom_api_key', 'k');
$cfgOaiCompatGpt5 = llm_get_active_config();
$reqOaiCompatGpt5 = llm_build_chat_request($cfgOaiCompatGpt5, [['role'=>'user','content'=>'hi']], [], 1);
check('openai_compatible gpt-5-something: body has NO temperature key',
    !array_key_exists('temperature', $reqOaiCompatGpt5['body_arr']));

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

// azure_anthropic → POST to /anthropic/v1/messages.
// The probe MUST send max_tokens=50, not 1: with max_tokens=1 Claude
// returns the specific "Could not finish the message because max_tokens
// or model output limit was reached" error rather than HTTP 200, which
// previously made Test Connection unusable for the Azure Anthropic preset.
_llm_test_reset();
_llm_test_set('llm_provider', 'custom');
_llm_test_set('custom_preset', 'azure_anthropic');
_llm_test_set('custom_base_url', 'https://apim.example.com');
_llm_test_set('custom_model', 'claude-haiku-4-5');
_llm_test_set('custom_api_key', 'k');
$probedUrl  = null;
$probedBody = null;
$GLOBALS['LLM_HTTP_POST_HOOK'] = function ($url, $headers, $body) use (&$probedUrl, &$probedBody) {
    $probedUrl  = $url;
    $probedBody = $body;
    return [200, json_encode(['id'=>'msg_1','content'=>[['type'=>'text','text'=>'ok']],'usage'=>['input_tokens'=>1,'output_tokens'=>1]]), ''];
};
$res = llm_test_connection('custom');
check('test_connection azure_anthropic: ok=true via Anthropic Messages POST',
    $res['ok'] === true && $probedUrl === 'https://apim.example.com/anthropic/v1/messages');
$probedDecoded = json_decode((string)$probedBody, true);
check('test_connection azure_anthropic: probe sends max_tokens=50 (not 1)',
    is_array($probedDecoded) && ($probedDecoded['max_tokens'] ?? null) === 50);

// Azure OpenAI gpt-5 deployment: probe must use max_completion_tokens=50,
// not 1 — reasoning models burn reasoning tokens against the same budget
// and won't finish a reply at 1.
_llm_test_reset();
_llm_test_set('llm_provider', 'custom');
_llm_test_set('custom_preset', 'azure_openai');
_llm_test_set('custom_resource_url', 'https://r.openai.azure.com');
_llm_test_set('custom_deployment', 'gpt-5');
_llm_test_set('custom_api_key', 'k');
$probedBody = null;
$GLOBALS['LLM_HTTP_POST_HOOK'] = function ($url, $headers, $body) use (&$probedBody) {
    $probedBody = $body;
    return [200, json_encode(['choices'=>[['message'=>['role'=>'assistant','content'=>'ok']]]]), ''];
};
$res = llm_test_connection('custom');
$probedDecoded = json_decode((string)$probedBody, true);
check('test_connection azure_openai gpt-5: probe sends max_completion_tokens=50',
    is_array($probedDecoded) && ($probedDecoded['max_completion_tokens'] ?? null) === 50);
check('test_connection azure_openai gpt-5: probe omits max_tokens (uses max_completion_tokens)',
    is_array($probedDecoded) && !array_key_exists('max_tokens', $probedDecoded));

// Azure OpenAI gpt-4o-mini (non-reasoning): probe stays at max_tokens=1.
_llm_test_reset();
_llm_test_set('llm_provider', 'custom');
_llm_test_set('custom_preset', 'azure_openai');
_llm_test_set('custom_resource_url', 'https://r.openai.azure.com');
_llm_test_set('custom_deployment', 'gpt-4o-mini');
_llm_test_set('custom_api_key', 'k');
$probedBody = null;
$GLOBALS['LLM_HTTP_POST_HOOK'] = function ($url, $headers, $body) use (&$probedBody) {
    $probedBody = $body;
    return [200, json_encode(['choices'=>[['message'=>['role'=>'assistant','content'=>'ok']]]]), ''];
};
$res = llm_test_connection('custom');
$probedDecoded = json_decode((string)$probedBody, true);
check('test_connection azure_openai gpt-4o-mini: probe stays at max_tokens=1',
    is_array($probedDecoded) && ($probedDecoded['max_tokens'] ?? null) === 1);

// openai_compatible chat fallback (after /models 404): also stays at
// max_tokens=1 because the default token_field is max_tokens.
_llm_test_reset();
_llm_test_set('llm_provider', 'custom');
_llm_test_set('custom_preset', 'openai_compatible');
_llm_test_set('custom_base_url', 'https://api.example.com/v1');
_llm_test_set('custom_model', 'foo/bar');
_llm_test_set('custom_api_key', 'k');
$probedBody = null;
$GLOBALS['LLM_HTTP_GET_HOOK']  = function () { return [404, '{}', '']; };
$GLOBALS['LLM_HTTP_POST_HOOK'] = function ($url, $headers, $body) use (&$probedBody) {
    $probedBody = $body;
    return [200, json_encode(['choices'=>[['message'=>['role'=>'assistant','content'=>'']]]]), ''];
};
$res = llm_test_connection('custom');
$probedDecoded = json_decode((string)$probedBody, true);
check('test_connection openai_compatible fallback probe: max_tokens=1 unchanged',
    is_array($probedDecoded) && ($probedDecoded['max_tokens'] ?? null) === 1);

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
