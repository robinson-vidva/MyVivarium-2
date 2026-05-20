<?php
// Pure-logic tests for includes/ai_configs.php (URL building, header/body
// split for custom KV rows, runtime config shape for each provider).
// Does not touch the database or .env; stubs the encryption helpers and the
// ai_settings_* accessors that ai_configs.php would otherwise pull in.

function ai_settings_get($k)    { return null; }
function ai_settings_set($k, $v, $uid) {}
function ai_settings_delete($k, $uid) {}
function ai_settings_get_meta($k) { return null; }
function ai_settings_encrypt($p) { return 'plain:' . $p; }
function ai_settings_decrypt($b) { return preg_replace('/^plain:/', '', $b); }
function log_activity($con, $a, $e, $eid = null, $d = null) {}
class AiSettingsException extends RuntimeException {}

require_once __DIR__ . '/../includes/llm_provider.php';
require_once __DIR__ . '/../includes/ai_configs.php';

$pass = 0; $fail = 0;
function ok($cond, $msg) { global $pass, $fail; if ($cond) { $pass++; echo "[PASS] $msg\n"; } else { $fail++; echo "[FAIL] $msg\n"; } }

// custom KV split
$res = ai_configs_split_custom_settings([
    ['key' => 'header.x-org',  'value' => 'org_123'],
    ['key' => 'top_p',         'value' => '0.9'],
    ['key' => 'stop',          'value' => '["END","STOP"]'],
    ['key' => 'reason',        'value' => 'a sentence'],
    ['key' => 'enable_x',      'value' => 'true'],
    ['key' => 'header.x-trace','value' => 'abc'],
    ['key' => '',              'value' => 'ignored'],
]);
ok($res['headers'] === ['x-org: org_123', 'x-trace: abc'], 'header. rows become headers');
ok(!array_key_exists('header.x-org', $res['body_extras']), 'header rows are NOT in body');
ok($res['body_extras']['top_p'] === 0.9, 'numeric value JSON-parsed to float');
ok($res['body_extras']['stop'] === ['END', 'STOP'], 'array value JSON-parsed');
ok($res['body_extras']['reason'] === 'a sentence', 'non-JSON value stays as string');
ok($res['body_extras']['enable_x'] === true, 'boolean string parsed as bool');

// runtime helpers
function row($overrides = []) {
    $base = [
        'id' => 1, 'nickname' => 'Test',
        'provider' => 'groq', 'model' => 'llama-3.3-70b-versatile',
        'preset' => '', 'base_url' => '',
        'api_key_primary'   => ai_settings_encrypt('key_primary'),
        'api_key_secondary' => null,
        'temperature' => null, 'max_tokens' => null,
        'custom_settings' => [],
    ];
    return array_merge($base, $overrides);
}

$cfg = ai_configs_to_runtime(row(), 'primary');
ok($cfg['api_key'] === 'key_primary', 'groq primary decrypts');
ok($cfg['request_url'] === 'https://api.groq.com/openai/v1/chat/completions', 'groq URL default');

$cfg = ai_configs_to_runtime(row(['provider' => 'openai', 'model' => 'gpt-5.4-mini']), 'primary');
ok($cfg['request_url'] === 'https://api.openai.com/v1/chat/completions', 'openai URL default');

$cfg = ai_configs_to_runtime(row([
    'provider' => 'custom', 'preset' => 'azure_openai',
    'base_url' => 'https://r.openai.azure.com', 'model' => 'gpt-4o-mini',
    'custom_settings' => [['key' => 'api_version', 'value' => '2024-12-01']],
]), 'primary');
ok(strpos($cfg['request_url'], '/openai/deployments/gpt-4o-mini/chat/completions') !== false, 'azure_openai URL embeds deployment');
ok(strpos($cfg['request_url'], 'api-version=2024-12-01') !== false, 'azure_openai api_version from custom_settings');
ok($cfg['auth_header'] === 'api-key', 'azure_openai uses api-key header');
ok($cfg['include_model_in_body'] === false, 'azure_openai does NOT include model in body');

$cfg = ai_configs_to_runtime(row([
    'provider' => 'custom', 'preset' => 'azure_anthropic',
    'base_url' => 'https://apim.example.net', 'model' => 'claude-sonnet-4-6',
]), 'primary');
ok($cfg['request_url'] === 'https://apim.example.net/anthropic/v1/messages', 'azure_anthropic URL');
ok($cfg['body_format'] === 'anthropic_messages', 'azure_anthropic body format');

$cfg = ai_configs_to_runtime(row([
    'provider' => 'custom', 'preset' => 'openai_compatible',
    'base_url' => 'https://openrouter.ai/api/v1', 'model' => 'deepseek/v3',
]), 'primary');
ok($cfg['request_url'] === 'https://openrouter.ai/api/v1/chat/completions', 'openai_compatible URL');
ok($cfg['test_url']    === 'https://openrouter.ai/api/v1/models',          'openai_compatible test URL');

$cfg = ai_configs_to_runtime(row(['api_key_secondary' => ai_settings_encrypt('key_secondary')]), 'secondary');
ok($cfg !== null && $cfg['api_key'] === 'key_secondary', 'secondary key decrypts');

$cfg = ai_configs_to_runtime(row(), 'secondary');
ok($cfg === null, 'secondary returns null when not set');

$cfg = ai_configs_to_runtime(row(['provider' => 'unknown', 'api_key_primary' => ai_settings_encrypt('x')]), 'primary');
ok(in_array('provider', $cfg['config_errors'], true), 'unknown provider flagged');

// test-connection chat probe must merge custom_settings the same way the
// runtime chat call does — otherwise admins see false negatives on configs
// whose auth lives in a custom header (e.g. Azure APIM).
$captured = null;
$GLOBALS['LLM_HTTP_POST_HOOK'] = function ($url, $headers, $body) use (&$captured) {
    $captured = ['url' => $url, 'headers' => $headers, 'body' => $body];
    return [200, '{"choices":[{"message":{"content":"ok"}}]}', ''];
};
$probeCfg = ai_configs_to_runtime(row([
    'provider' => 'custom', 'preset' => 'azure_openai',
    'base_url' => 'https://apim.example.net', 'model' => 'gpt-4o-mini',
    'custom_settings' => [
        ['key' => 'header.Ocp-Apim-Subscription-Key', 'value' => 'apim-secret'],
        ['key' => 'top_p',                            'value' => '0.7'],
    ],
]), 'primary');
$res = ai_configs_run_test_chat_probe($probeCfg);
ok($res['ok'] === true, 'test probe returns ok on 200');
ok(in_array('Ocp-Apim-Subscription-Key: apim-secret', $captured['headers'], true), 'test probe sends custom header');
$bodyArr = json_decode($captured['body'], true);
ok(isset($bodyArr['top_p']) && $bodyArr['top_p'] === 0.7, 'test probe merges custom body extras');
unset($GLOBALS['LLM_HTTP_POST_HOOK']);

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
