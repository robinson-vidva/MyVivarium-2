<?php
/**
 * Tests for the provider-chain admin model in includes/llm_provider.php.
 *
 *   php tests/provider_chain_test.php
 *
 * Covers:
 *   - Reading an empty / unset chain → migrates from legacy llm_provider key.
 *   - Reading a stored chain (JSON) → ordered list of {provider, enabled, priority}.
 *   - Normalization: dedupes, fills in missing providers, renumbers priorities.
 *   - llm_get_provider_chain() (resolved) skips disabled + misconfigured entries.
 *   - llm_get_active_provider() returns the primary from the chain.
 *   - "At least one enabled and fully configured" validation logic.
 */

class AiSettingsException extends RuntimeException {}

$GLOBALS['__llm_test_store'] = [];
function ai_settings_get(string $key): ?string
{
    return array_key_exists($key, $GLOBALS['__llm_test_store']) ? $GLOBALS['__llm_test_store'][$key] : null;
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
// 1. Fresh install: no chain, no legacy key → all three providers, all disabled.
// ---------------------------------------------------------------------------
_llm_test_reset();
$chain = llm_get_provider_chain_raw();
check('fresh install: chain has 3 entries', count($chain) === 3);
$providers = array_column($chain, 'provider');
check('fresh install: includes groq, openai, custom',
    in_array('groq', $providers, true)
    && in_array('openai', $providers, true)
    && in_array('custom', $providers, true));
$allDisabled = true;
foreach ($chain as $e) { if ($e['enabled']) { $allDisabled = false; break; } }
check('fresh install: every provider starts disabled', $allDisabled);
$priorities = array_column($chain, 'priority');
check('fresh install: priorities are 1,2,3 in order', $priorities === [1, 2, 3]);

$resolved = llm_get_provider_chain();
check('fresh install: resolved chain is empty (nothing enabled)', count($resolved) === 0);

// ---------------------------------------------------------------------------
// 2. Legacy migration: only llm_provider set → that provider lands at priority 1 enabled.
// ---------------------------------------------------------------------------
_llm_test_reset();
_llm_test_set('llm_provider', 'openai');
_llm_test_set('openai_api_key', 'sk-test');
$chain = llm_get_provider_chain_raw();
check('legacy migration: openai is priority 1', $chain[0]['provider'] === 'openai' && $chain[0]['priority'] === 1);
check('legacy migration: openai is enabled',    $chain[0]['enabled'] === true);
check('legacy migration: groq and custom appended disabled',
    $chain[1]['enabled'] === false && $chain[2]['enabled'] === false);

// Active-provider reads through the chain
check('legacy migration: active provider reflects chain primary',
    llm_get_active_provider() === 'openai');

// Resolved chain: openai is configured (has key) → present; groq/custom are disabled → absent.
$resolved = llm_get_provider_chain();
check('legacy migration: resolved chain has only openai', count($resolved) === 1 && $resolved[0]['provider'] === 'openai');

// ---------------------------------------------------------------------------
// 3. Legacy migration with custom: legacy=custom, key+preset set
// ---------------------------------------------------------------------------
_llm_test_reset();
_llm_test_set('llm_provider', 'custom');
_llm_test_set('custom_preset', 'openai_compatible');
_llm_test_set('custom_base_url', 'https://x.example.com/v1');
_llm_test_set('custom_model', 'm');
_llm_test_set('custom_api_key', 'k');
$chain = llm_get_provider_chain_raw();
check('legacy custom: custom is priority 1 enabled',
    $chain[0]['provider'] === 'custom' && $chain[0]['priority'] === 1 && $chain[0]['enabled']);

// ---------------------------------------------------------------------------
// 4. Stored chain takes precedence over legacy llm_provider.
// ---------------------------------------------------------------------------
_llm_test_reset();
_llm_test_set('llm_provider', 'groq'); // legacy says groq, but stored chain says openai first
_llm_test_set('llm_provider_chain', json_encode([
    ['provider' => 'openai', 'enabled' => true,  'priority' => 1],
    ['provider' => 'groq',   'enabled' => true,  'priority' => 2],
    ['provider' => 'custom', 'enabled' => false, 'priority' => 3],
]));
_llm_test_set('openai_api_key', 'sk-x');
_llm_test_set('groq_api_key',   'gsk_x');
$chain = llm_get_provider_chain_raw();
check('stored chain wins: openai is priority 1', $chain[0]['provider'] === 'openai');
check('stored chain wins: groq is priority 2',   $chain[1]['provider'] === 'groq');
check('stored chain wins: legacy llm_provider ignored', llm_get_active_provider() === 'openai');

$resolved = llm_get_provider_chain();
check('stored chain: resolved has openai then groq',
    count($resolved) === 2 && $resolved[0]['provider'] === 'openai' && $resolved[1]['provider'] === 'groq');

// ---------------------------------------------------------------------------
// 5. Normalization: dedupes, drops unknown providers, fills missing.
// ---------------------------------------------------------------------------
$norm = llm_normalize_chain([
    ['provider' => 'openai', 'enabled' => true,  'priority' => 2],
    ['provider' => 'openai', 'enabled' => false, 'priority' => 5], // dup ignored
    ['provider' => 'bogus',  'enabled' => true,  'priority' => 1], // unknown ignored
    ['provider' => 'groq',   'enabled' => true,  'priority' => 1],
    // 'custom' missing → appended disabled
]);
check('normalize: dedupes openai',
    count(array_filter($norm, fn($e) => $e['provider'] === 'openai')) === 1);
check('normalize: drops bogus',
    count(array_filter($norm, fn($e) => $e['provider'] === 'bogus')) === 0);
check('normalize: appends missing custom',
    count(array_filter($norm, fn($e) => $e['provider'] === 'custom')) === 1);
check('normalize: priorities renumbered 1..N consecutively',
    array_column($norm, 'priority') === [1, 2, 3]);
check('normalize: groq (priority 1) sorts before openai (priority 2)',
    $norm[0]['provider'] === 'groq' && $norm[1]['provider'] === 'openai');

// ---------------------------------------------------------------------------
// 6. Resolved chain: skips disabled and misconfigured entries silently.
// ---------------------------------------------------------------------------
_llm_test_reset();
_llm_test_set('llm_provider_chain', json_encode([
    ['provider' => 'groq',   'enabled' => true,  'priority' => 1], // no api key → skipped
    ['provider' => 'openai', 'enabled' => false, 'priority' => 2], // disabled    → skipped
    ['provider' => 'custom', 'enabled' => true,  'priority' => 3], // misconfigured → skipped
]));
_llm_test_set('custom_preset', 'azure_openai'); // missing other fields
$resolved = llm_get_provider_chain();
check('resolved: groq with no key is skipped', !in_array('groq',   array_column($resolved, 'provider'), true));
check('resolved: disabled openai is skipped',  !in_array('openai', array_column($resolved, 'provider'), true));
check('resolved: misconfigured custom is skipped', !in_array('custom', array_column($resolved, 'provider'), true));
check('resolved: empty when nothing qualifies', count($resolved) === 0);

// ---------------------------------------------------------------------------
// 7. "At least one enabled and fully configured" — admin validation logic.
// This mirrors what manage_ai_config.php does on save: it asks
// llm_normalize_chain() to canonicalize the submitted entries, then iterates
// to find at least one that is both enabled and configured.
// ---------------------------------------------------------------------------

function has_any_enabled_and_configured(array $submittedChain): bool {
    $norm = llm_normalize_chain($submittedChain);
    foreach ($norm as $entry) {
        if (!$entry['enabled']) continue;
        $cfg = llm_get_provider_config($entry['provider']);
        if (($cfg['api_key'] ?? '') === '') continue;
        if (($cfg['provider'] ?? '') === 'custom' && !empty($cfg['config_errors'])) continue;
        return true;
    }
    return false;
}

// All disabled → blocked
_llm_test_reset();
check('validation: all disabled → no provider available',
    has_any_enabled_and_configured([
        ['provider' => 'groq',   'enabled' => false, 'priority' => 1],
        ['provider' => 'openai', 'enabled' => false, 'priority' => 2],
        ['provider' => 'custom', 'enabled' => false, 'priority' => 3],
    ]) === false);

// One enabled but no key → blocked
_llm_test_reset();
check('validation: enabled-but-no-key → blocked',
    has_any_enabled_and_configured([
        ['provider' => 'openai', 'enabled' => true, 'priority' => 1],
    ]) === false);

// One enabled with key → ok
_llm_test_reset();
_llm_test_set('openai_api_key', 'sk-x');
check('validation: openai enabled with key → ok',
    has_any_enabled_and_configured([
        ['provider' => 'openai', 'enabled' => true, 'priority' => 1],
    ]) === true);

// Groq incomplete + OpenAI enabled+configured → ok (per-provider completeness is independent).
// This is the regression the new chain model fixes — previously "Cannot switch to X
// because Y is required" rejected the save even though OpenAI alone was fine.
_llm_test_reset();
_llm_test_set('openai_api_key', 'sk-x');
check('validation: groq missing + openai ok → save passes',
    has_any_enabled_and_configured([
        ['provider' => 'groq',   'enabled' => true, 'priority' => 1], // no key
        ['provider' => 'openai', 'enabled' => true, 'priority' => 2],
    ]) === true);

// Custom misconfigured + Groq configured → ok (this is the original bug report:
// "couldn't switch away from misconfigured Custom" no longer applies — Groq is
// independently configurable and enableable).
_llm_test_reset();
_llm_test_set('groq_api_key', 'gsk');
_llm_test_set('custom_preset', 'azure_openai'); // incomplete
check('validation: custom incomplete + groq ok → save passes (the previously-blocked case)',
    has_any_enabled_and_configured([
        ['provider' => 'groq',   'enabled' => true, 'priority' => 1],
        ['provider' => 'custom', 'enabled' => true, 'priority' => 2],
    ]) === true);

// ---------------------------------------------------------------------------
// 8. Backwards compat: llm_get_active_config() still works for tests that
// only set legacy llm_provider.
// ---------------------------------------------------------------------------
_llm_test_reset();
_llm_test_set('llm_provider', 'groq');
_llm_test_set('groq_api_key', 'gsk');
$cfg = llm_get_active_config();
check('compat: legacy llm_provider=groq still resolves to groq cfg',
    $cfg['provider'] === 'groq' && $cfg['api_key'] === 'gsk');

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
