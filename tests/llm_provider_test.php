<?php
/**
 * Unit tests for includes/llm_provider.php.
 *
 * Verifies that llm_get_active_config() routes to the right base_url and
 * model based on the llm_provider setting, and that a missing API key
 * surfaces as a clear "no key configured" signal rather than a crash.
 *
 *     php tests/llm_provider_test.php
 *
 * No DB / network — ai_settings_get() is stubbed in-memory.
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
}

require_once __DIR__ . '/../includes/llm_provider.php';

$pass = 0; $fail = 0;
function check(string $name, bool $ok): void {
    global $pass, $fail;
    if ($ok) { echo "[PASS] $name\n"; $pass++; }
    else     { echo "[FAIL] $name\n"; $fail++; }
}

// 1. llm_provider=groq → base_url is api.groq.com, model from groq_model.
_llm_test_reset();
_llm_test_set('llm_provider', 'groq');
_llm_test_set('groq_api_key', 'gsk_dummy');
_llm_test_set('groq_model',   'llama-3.3-70b-versatile');
$cfg = llm_get_active_config();
check('groq: provider is groq',                 $cfg['provider'] === 'groq');
check('groq: base_url is api.groq.com',         strpos($cfg['base_url'], 'api.groq.com') !== false);
check('groq: model echoed back',                $cfg['model'] === 'llama-3.3-70b-versatile');
check('groq: api_key echoed back (in-memory)',  $cfg['api_key'] === 'gsk_dummy');
check('groq: allowed_models includes llama',    in_array('llama-3.3-70b-versatile', $cfg['allowed_models'], true));

// 2. llm_provider=openai → base_url is api.openai.com, model from openai_model.
_llm_test_reset();
_llm_test_set('llm_provider', 'openai');
_llm_test_set('openai_api_key', 'sk-dummy');
_llm_test_set('openai_model',   'gpt-5.4-mini');
$cfg = llm_get_active_config();
check('openai: provider is openai',             $cfg['provider'] === 'openai');
check('openai: base_url is api.openai.com',     strpos($cfg['base_url'], 'api.openai.com') !== false);
check('openai: model echoed back',              $cfg['model'] === 'gpt-5.4-mini');
check('openai: api_key echoed back',            $cfg['api_key'] === 'sk-dummy');
check('openai: allowed_models includes mini',   in_array('gpt-5.4-mini', $cfg['allowed_models'], true));

// 3. No llm_provider set → defaults to groq.
_llm_test_reset();
$cfg = llm_get_active_config();
check('default: provider falls back to groq',   $cfg['provider'] === 'groq');
check('default: base_url is api.groq.com',      strpos($cfg['base_url'], 'api.groq.com') !== false);

// 4. No key set for active provider → api_key is '' (clear no-key signal).
_llm_test_reset();
_llm_test_set('llm_provider', 'openai');
// openai_api_key intentionally not set
$cfg = llm_get_active_config();
check('no-key: returns empty api_key (no crash)', $cfg['api_key'] === '');
check('no-key: still returns provider/base_url',  $cfg['provider'] === 'openai' && strpos($cfg['base_url'], 'api.openai.com') !== false);
check('no-key: falls back to default openai model', $cfg['model'] === 'gpt-5.4-mini');

// 5. llm_get_provider_config() works for either provider regardless of active.
_llm_test_reset();
_llm_test_set('llm_provider', 'groq');
_llm_test_set('openai_api_key', 'sk-other');
$pcfg = llm_get_provider_config('openai');
check('per-provider lookup: groq active, openai cfg readable', $pcfg['provider'] === 'openai' && $pcfg['api_key'] === 'sk-other');

// 6. llm_get_provider_config() rejects unknown provider.
$threw = false;
try {
    llm_get_provider_config('anthropic');
} catch (LlmProviderException $e) {
    $threw = true;
}
check('unknown provider throws LlmProviderException', $threw);

// 7. llm_provider_label() helpers.
check('label: groq → Groq',     llm_provider_label('groq')   === 'Groq');
check('label: openai → OpenAI', llm_provider_label('openai') === 'OpenAI');

// 8. llm_chat_completions() with no api_key returns ok=false + error='no_api_key'
//    rather than attempting an HTTP call. We can't intercept curl in pure PHP
//    without uopz, but we can verify the early-return branch.
_llm_test_reset();
_llm_test_set('llm_provider', 'openai');
// no openai_api_key
$res = llm_chat_completions([['role' => 'user', 'content' => 'hi']], []);
check('chat_completions: no-key short-circuits',  $res['ok'] === false && $res['error'] === 'no_api_key');
check('chat_completions: reports provider/model', $res['provider'] === 'openai' && $res['model'] === 'gpt-5.4-mini');

// 9. llm_test_connection() with no key set surfaces the clear message.
_llm_test_reset();
$res = llm_test_connection('openai');
check('test_connection: no key → ok=false + helpful error',
    $res['ok'] === false && strpos((string)$res['error'], 'OpenAI') !== false);

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
